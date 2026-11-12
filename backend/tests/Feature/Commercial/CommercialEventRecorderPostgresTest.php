<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\ElectronicInvoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PgSql\Connection;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * I-1 contra PostgreSQL de verdad: COMMIT reales y una segunda sesión.
 *
 * El registrador de hechos corre dentro de la transacción del pago (lo llama
 * `PaymentTransactionCommercialObserver`). En PostgreSQL una sentencia que
 * falla deja la transacción abortada (25P02) aunque PHP se trague la excepción,
 * y si no hay otra sentencia detrás, `commit()` no lanza: el servidor hace
 * ROLLBACK y el cambio de estado del pago se pierde en silencio. Por eso aquí
 * NO hay `RefreshDatabase`: dentro de la transacción de la prueba el COMMIT de
 * la aplicación sería solo un nivel anidado y no se vería la pérdida.
 *
 * La segunda sesión es `pg_connect` (la extensión `pgsql`), porque una de las
 * pruebas necesita que la otra sesión ESPERE un cerrojo sin bloquear a PHP
 * (`pg_send_query`). Una tercera sesión solo mira (`pg_locks`) y relee lo
 * confirmado.
 *
 * Lo que se confirma aquí se borra al terminar cada prueba (solo las filas que
 * crea la prueba y `commercial_events`, una tabla de la base desechable).
 * Se ejecuta con `docs/ultron-studio/ops/pgunit.sh`. En SQLite solo corre el
 * caso sin transacción (c), sobre la base en memoria recién migrada.
 */
#[Group('pgsql')]
class CommercialEventRecorderPostgresTest extends TestCase
{
    /** @var resource|Connection|null */
    private mixed $rival = null;

    /** @var resource|Connection|null */
    private mixed $monitor = null;

    private ?User $user = null;

    private ?Member $member = null;

    /** @var list<int> */
    private array $transactions = [];

    /** @var list<int> */
    private array $invoices = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isPostgres()) {
            if (! RefreshDatabaseState::$migrated) {
                $this->artisan('migrate:fresh', ['--force' => true]);
                RefreshDatabaseState::$migrated = true;
            }
        } else {
            // SQLite en memoria: una base nueva por aplicación; se migra como haría
            // RefreshDatabase, pero sin su transacción alrededor.
            $this->artisan('migrate', ['--force' => true]);
        }

        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);
        Queue::fake([EvaluateCommercialSubject::class]);
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });

        $this->user = User::create(['name' => 'Socio PG', 'email' => 'socio-pg-'.uniqid().'@iron.test', 'password' => bcrypt('x')]);
        $this->member = Member::create([
            'full_name' => 'Socio PG', 'document_number' => (string) random_int(1000000000, 1999999999), 'phone' => '3150536026',
            'user_id' => $this->user->id, 'access_hash' => 'tok-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['rival', 'monitor'] as $c) {
            if ($this->{$c} !== null) {
                @pg_cancel_query($this->{$c});
                @pg_query($this->{$c}, 'ROLLBACK');
                pg_close($this->{$c});
                $this->{$c} = null;
            }
        }
        if ($this->app !== null) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->isPostgres()) {
                DB::statement('RESET lock_timeout');
                DB::statement('RESET deadlock_timeout');
            }
            CommercialEvent::flushEventListeners();
            // Solo lo que esta prueba confirmó: la base la comparten las demás pruebas del proceso.
            DB::table('commercial_events')->delete();
            DB::table('payment_transactions')->whereIn('id', $this->transactions)->delete();
            DB::table('electronic_invoices')->whereIn('id', $this->invoices)->delete();
            if ($this->member !== null) {
                DB::table('members')->where('id', $this->member->id)->delete();
            }
            if ($this->user !== null) {
                DB::table('users')->where('id', $this->user->id)->delete();
            }
        }
        Context::flush();
        parent::tearDown();
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function onlyPostgres(string $why): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo en PostgreSQL: '.$why.' Ejecutar con docs/ultron-studio/ops/pgunit.sh.');
        }
    }

    /** Otra sesión de PostgreSQL, sin pasar por Laravel. */
    private function pgSession(): mixed
    {
        $c = DB::connection()->getConfig();
        $dsn = sprintf('host=%s port=%s dbname=%s user=%s connect_timeout=5', $c['host'], $c['port'], $c['database'], $c['username']);
        if ((string) ($c['password'] ?? '') !== '') {
            $dsn .= " password='".addslashes((string) $c['password'])."'";
        }
        $s = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($s, 'no se pudo abrir la segunda sesión');

        return $s;
    }

    private function rival(): mixed
    {
        return $this->rival ??= $this->pgSession();
    }

    private function monitor(): mixed
    {
        return $this->monitor ??= $this->pgSession();
    }

    private function scalar(mixed $session, string $sql, array $params = []): ?string
    {
        $r = pg_query_params($session, $sql, $params);
        $this->assertNotFalse($r, pg_last_error($session));
        if (pg_num_rows($r) === 0) {
            return null;   // la fila no existe (p. ej. el COMMIT se la tragó)
        }
        $v = pg_fetch_result($r, 0, 0);

        return $v === false ? null : $v;
    }

    /** Un pago recién creado, confirmado. */
    private function payment(): PaymentTransaction
    {
        $tx = PaymentTransaction::create([
            'reference' => 'REF-'.uniqid(), 'idempotency_key' => 'idem-'.uniqid(),
            'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'amount' => 120000, 'currency' => 'COP', 'status' => 'created', 'provider' => 'wompi',
        ]);
        $this->transactions[] = $tx->id;

        return $tx;
    }

    /** El estado del pago leído desde OTRA sesión: lo que de verdad quedó confirmado. */
    private function committedStatus(PaymentTransaction $tx): ?string
    {
        return $this->scalar($this->monitor(), 'select status from payment_transactions where id = $1', [$tx->id]);
    }

    private function committedEvents(string $key): int
    {
        return (int) $this->scalar($this->monitor(), 'select count(*) from commercial_events where dedupe_key = $1', [$key]);
    }

    /** La otra sesión deja SIN CONFIRMAR el mismo hecho: vio el mismo pago a la vez. */
    private function rivalHoldsTheSameFact(string $key): void
    {
        $r = $this->rival();
        $this->assertNotFalse(pg_query($r, 'BEGIN'));
        $this->assertNotFalse(pg_query_params($r,
            "insert into commercial_events (member_id, event, dedupe_key, payload, occurred_at, created_at, updated_at) values ($1, 'payment_approved', $2, '{}', now(), now(), now())",
            [$this->member->id, $key]));
    }

    /** Espera a que la sesión rival esté ESPERANDO un cerrojo. */
    private function waitUntilRivalWaits(): void
    {
        $pid = (int) pg_get_pid($this->rival());
        $hasta = microtime(true) + 5;
        while (microtime(true) < $hasta) {
            if ((int) $this->scalar($this->monitor(), 'select count(*) from pg_locks where pid = $1 and not granted', [$pid]) > 0) {
                usleep(20000);

                return;
            }
            usleep(10000);
        }
        $this->fail('la sesión rival no llegó a esperar el cerrojo');
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn (array $l): bool => $l['message'] === $message));
    }

    private function approveInsideATransaction(PaymentTransaction $tx): void
    {
        // Como el webhook o la reconciliación: el cambio de estado en su transacción.
        // El observador comercial es lo ÚLTIMO que corre antes del COMMIT.
        DB::transaction(function () use ($tx): void {
            $tx->update(['status' => 'approved']);
        });
    }

    // ── (a) La pérdida silenciosa de I-1, por las clases reales ─────────────

    public function test_si_el_insert_del_hecho_falla_el_pago_queda_aprobado_de_verdad(): void
    {
        $this->onlyPostgres('SQLite no valida el tipo uuid ni aborta la transacción por una sentencia que falla.');
        $tx = $this->payment();
        // `correlation_id` es uuid y sale del Context: el INSERT del hecho falla.
        Context::add('correlation_id', 'no-es-un-uuid');

        $this->approveInsideATransaction($tx);   // no lanza: el registrador contiene el fallo

        $this->assertSame('approved', $this->committedStatus($tx), 'el COMMIT se tragó el cambio de estado del pago');
        $this->assertSame(0, $this->committedEvents('payment_approved:tx:'.$tx->id.':approved'));
        $this->assertCount(1, $this->logged('commercial.event.record_failed'));
        $this->assertSame(0, DB::transactionLevel());
    }

    // ── (b) La carrera de dedupe_key con dos sesiones ───────────────────────

    public function test_la_carrera_de_dedupe_key_con_otra_sesion_no_pierde_el_pago(): void
    {
        $this->onlyPostgres('la espera por un INSERT sin confirmar y la transacción abortada por 23505 no existen en SQLite.');
        $tx = $this->payment();
        $key = 'payment_approved:tx:'.$tx->id.':approved';

        // Las dos sesiones pasaron la lectura previa (la de la otra aún no está
        // confirmada); esta llega al INSERT, ESPERA a la otra y, cuando la otra
        // confirma, choca con el índice único (23505).
        $this->rivalHoldsTheSameFact($key);
        $enviado = false;
        CommercialEvent::creating(function () use (&$enviado): void {
            if (! $enviado) {
                $enviado = true;
                $this->assertTrue(pg_send_query($this->rival(), 'select pg_sleep(0.4); commit'));
            }
        });

        $inicio = microtime(true);
        $this->approveInsideATransaction($tx);
        $espera = microtime(true) - $inicio;
        while (pg_get_result($this->rival()) !== false) {
            // vaciar los resultados de la otra sesión
        }

        $this->assertTrue($enviado, 'el registrador no llegó al INSERT');
        $this->assertGreaterThanOrEqual(0.3, $espera, 'el INSERT no esperó a la otra sesión: no hubo carrera');
        $this->assertSame('approved', $this->committedStatus($tx));
        $this->assertSame(1, $this->committedEvents($key), 'el hecho existe una vez: el de la otra sesión');
        // La rama de unicidad es la NORMAL de una carrera: se calla, como siempre.
        $this->assertSame([], $this->logged('commercial.event.record_failed'));
    }

    // ── Otros fallos de la base dentro de record() ──────────────────────────

    public function test_un_lock_timeout_en_el_insert_no_pierde_el_pago(): void
    {
        $this->onlyPostgres('lock_timeout no existe en SQLite.');
        $tx = $this->payment();
        $key = 'payment_approved:tx:'.$tx->id.':approved';
        $this->rivalHoldsTheSameFact($key);   // y no confirma mientras esta espera
        DB::statement("SET lock_timeout = '300ms'");

        $this->approveInsideATransaction($tx);

        $this->assertSame('approved', $this->committedStatus($tx));
        $fallo = $this->logged('commercial.event.record_failed');
        $this->assertCount(1, $fallo);
        $this->assertStringContainsString('lock timeout', $fallo[0]['context']['message']);
        pg_query($this->rival(), 'ROLLBACK');
        $this->assertSame(0, $this->committedEvents($key), 'el hecho se pierde (queda en el log); el pago no');
    }

    /**
     * La lectura previa también está dentro de la protección: una migración que
     * tiene `commercial_events` con ACCESS EXCLUSIVE hace que el SELECT espere y
     * caiga por `lock_timeout`.
     */
    public function test_un_lock_timeout_en_la_lectura_previa_no_pierde_el_pago(): void
    {
        $this->onlyPostgres('LOCK TABLE y lock_timeout no existen en SQLite.');
        $tx = $this->payment();
        $this->assertNotFalse(pg_query($this->rival(), 'BEGIN'));
        $this->assertNotFalse(pg_query($this->rival(), 'LOCK TABLE commercial_events IN ACCESS EXCLUSIVE MODE'));
        DB::statement("SET lock_timeout = '300ms'");

        $this->approveInsideATransaction($tx);

        $this->assertSame('approved', $this->committedStatus($tx));
        $this->assertCount(1, $this->logged('commercial.event.record_failed'));
    }

    /**
     * El interbloqueo. Laravel, en un `DB::transaction` ANIDADO, trata un
     * interbloqueo (40P01) como en MySQL: baja el contador y lanza
     * `DeadlockException` SIN `ROLLBACK TO SAVEPOINT`, y en PostgreSQL la
     * transacción de fuera quedaría abortada. El registrador no puede apoyarse en
     * ese cierre.
     *
     * El ciclo: esta sesión tiene la fila del pago (UPDATE) y espera el hecho sin
     * confirmar de la otra; la otra espera la fila del pago. Esta detecta el
     * interbloqueo antes (deadlock_timeout 100 ms frente a 10 s) y es la víctima.
     */
    public function test_un_interbloqueo_dentro_del_registrador_no_pierde_el_pago(): void
    {
        $this->onlyPostgres('el detector de interbloqueos de PostgreSQL no existe en SQLite.');
        $tx = $this->payment();
        $key = 'payment_approved:tx:'.$tx->id.':approved';
        $this->assertNotFalse(pg_query($this->rival(), "SET deadlock_timeout = '10s'"));
        $this->rivalHoldsTheSameFact($key);
        DB::statement("SET deadlock_timeout = '100ms'");

        $enviado = false;
        CommercialEvent::creating(function () use ($tx, &$enviado): void {
            if (! $enviado) {
                $enviado = true;
                // La otra sesión pide la fila del pago, que esta tiene bloqueada.
                $this->assertTrue(pg_send_query($this->rival(), 'update payment_transactions set updated_at = now() where id = '.(int) $tx->id));
                $this->waitUntilRivalWaits();
            }
        });

        $this->approveInsideATransaction($tx);
        while (pg_get_result($this->rival()) !== false) {
            // el UPDATE de la otra sesión termina en cuanto esta confirma
        }
        $this->assertNotFalse(pg_query($this->rival(), 'COMMIT'));

        $this->assertTrue($enviado);
        $this->assertSame('approved', $this->committedStatus($tx));
        $this->assertSame(1, $this->committedEvents($key), 'el hecho existe una vez: el de la otra sesión');
        $fallo = $this->logged('commercial.event.record_failed');
        $this->assertCount(1, $fallo);
        $this->assertStringContainsString('deadlock', $fallo[0]['context']['message']);
    }

    // ── (1b) La consulta propia del observador de facturas ─────────────────

    /**
     * `ElectronicInvoiceCommercialObserver::subjectFor()` busca el origen de la
     * factura (`$class::query()->find($source_id)`) DENTRO de la transacción de
     * quien guarda la factura y se traga el fallo. Aquí falla por `lock_timeout`:
     * otra sesión tiene `payments` con ACCESS EXCLUSIVE (una migración sobre la
     * tabla de pagos durante un despliegue). El origen es `App\Models\Payment`, el
     * `getMorphClass()` que guarda `InvoicingService`.
     */
    private function invoiceWhoseSourceQueryFails(): void
    {
        $this->assertNotFalse(pg_query($this->rival(), 'BEGIN'));
        $this->assertNotFalse(pg_query($this->rival(), 'LOCK TABLE payments IN ACCESS EXCLUSIVE MODE'));
        DB::statement("SET lock_timeout = '300ms'");
    }

    private function newInvoice(): ElectronicInvoice
    {
        $invoice = ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => 987654, 'type' => 'invoice',
            'status' => 'pending', 'full_number' => 'IBFE-PG-'.uniqid(), 'total' => '80000.00',
        ]);
        $this->invoices[] = $invoice->id;

        return $invoice;
    }

    private function committedInvoice(int $id): ?string
    {
        return $this->scalar($this->monitor(), 'select coalesce(failure_reason, status) from electronic_invoices where id = $1', [$id]);
    }

    public function test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_queda_confirmada(): void
    {
        $this->onlyPostgres('LOCK TABLE y lock_timeout no existen en SQLite.');
        $this->invoiceWhoseSourceQueryFails();

        // El observador es lo ÚLTIMO de la transacción: el caso silencioso.
        $invoice = DB::transaction(fn (): ElectronicInvoice => $this->newInvoice());

        pg_query($this->rival(), 'ROLLBACK');
        $this->assertSame('pending', $this->committedInvoice($invoice->id), 'el COMMIT se tragó la factura');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_tras_el_fallo_de_la_consulta_del_observador_la_transaccion_de_la_factura_sigue_sana(): void
    {
        $this->onlyPostgres('LOCK TABLE y lock_timeout no existen en SQLite.');
        $this->invoiceWhoseSourceQueryFails();

        $invoice = DB::transaction(function (): ElectronicInvoice {
            $invoice = $this->newInvoice();
            // Lo siguiente de quien guarda la factura (sin el punto de guardado: 25P02).
            DB::table('electronic_invoices')->where('id', $invoice->id)->update(['failure_reason' => 'siguiente sentencia']);

            return $invoice;
        });

        pg_query($this->rival(), 'ROLLBACK');
        $this->assertSame('siguiente sentencia', $this->committedInvoice($invoice->id));
    }

    // ── (c) Sin transacción abierta, todo igual que hoy ─────────────────────

    public function test_sin_transaccion_abierta_registra_en_el_acto_y_un_fallo_no_deja_nada_roto(): void
    {
        config()->set('commercial.enabled', true);
        $recorder = app(CommercialEventRecorder::class);
        $this->assertSame(0, DB::transactionLevel());

        $hecho = $recorder->record(V::EV_PAYMENT_APPROVED, ['member' => $this->member], ['reference' => 'REF-C'], 'tx:c:approved');

        $this->assertInstanceOf(CommercialEvent::class, $hecho);
        $this->assertSame(0, DB::transactionLevel());
        Queue::assertPushed(EvaluateCommercialSubject::class, 1);   // sin transacción, afterCommit despacha en el acto
        if ($this->isPostgres()) {
            $this->assertSame(1, $this->committedEvents('payment_approved:tx:c:approved'), 'confirmado: otra sesión lo ve');
        }
        $this->assertNull($recorder->record(V::EV_PAYMENT_APPROVED, ['member' => $this->member], [], 'tx:c:approved'), 'el duplicado');

        if ($this->isPostgres()) {
            Context::add('correlation_id', 'no-es-un-uuid');
            $this->assertNull($recorder->record(V::EV_PAYMENT_APPROVED, ['member' => $this->member], [], 'tx:c2:approved'));
            Context::flush();
            $this->assertCount(1, $this->logged('commercial.event.record_failed'));
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, CommercialEvent::query()->count(), 'la conexión sigue sirviendo');
        $this->assertCount(1, $this->logged('commercial.event.recorded'));
    }
}
