<?php

namespace Tests\Feature\Commercial;

use App\Models\Attendance;
use App\Models\CommercialEvent;
use App\Models\ElectronicInvoice;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El registrador de hechos no puede romper la transacción de quien lo llama
 * (hallazgo I-1 de security, informe-db-comercial §1).
 *
 * Lo llaman observadores DENTRO de la transacción de quien escribe (un pago,
 * una membresía, una asistencia). En PostgreSQL una sentencia que falla deja la
 * transacción abortada (25P02) aunque PHP se trague la excepción. Estas pruebas
 * corren dentro de la transacción de `RefreshDatabase`, así que cualquier
 * sentencia que falle sin su punto de guardado aborta la prueba entera en PG:
 * la sentencia siguiente da 25P02. En SQLite un fallo no aborta la transacción,
 * así que aquí comprueban el CONTRATO (null, la transacción sigue, lo de quien
 * llama queda), no la causa. La causa —la pérdida silenciosa en un COMMIT de
 * verdad, la carrera con dos conexiones, el `lock_timeout` y el interbloqueo—
 * vive en {@see CommercialEventRecorderPostgresTest}.
 */
class CommercialEventRecorderContainmentTest extends TestCase
{
    use RefreshDatabase;

    private CommercialEventRecorder $recorder;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);   // sin motor no se encola nada (salvo donde se dice)

        $this->recorder = app(CommercialEventRecorder::class);
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    private function member(string $document = '1010101010'): Member
    {
        return Member::create([
            'full_name' => 'Socio '.$document,
            'document_number' => $document,
            'phone' => '315'.substr($document, -7),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    /** @return list<string> */
    private function logged(string $message): array
    {
        return array_values(array_map(
            fn (array $l): string => $l['level'],
            array_filter($this->logs, fn (array $l): bool => $l['message'] === $message),
        ));
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * (a) El INSERT falla dentro de la transacción de la aplicación: el prospecto
     * no existe (la clave foránea de `marketing_lead_id`; en producción, un
     * prospecto borrado mientras tanto). Falla igual en los dos motores.
     */
    public function test_dentro_de_una_transaccion_un_insert_que_falla_devuelve_null_y_la_transaccion_sigue_sana(): void
    {
        $nivel = DB::transactionLevel();

        $resultado = DB::transaction(function () use ($nivel): array {
            $socio = $this->member();   // la escritura de quien llama

            $hecho = $this->recorder->record(
                event: V::EV_PAYMENT_APPROVED,
                subject: ['lead_id' => 987654321, 'member_id' => $socio->id],
                payload: ['reference' => 'REF-FK'],
                dedupeKey: 'tx:fk:approved',
            );

            $this->assertSame($nivel + 1, DB::transactionLevel(), 'el registrador no deja niveles de transacción de más ni de menos');

            // La transacción sigue sana: otra sentencia funciona (en PG, sin el punto
            // de guardado, aquí salta 25P02 «current transaction is aborted»).
            $this->member('2020202020');

            return [$hecho, $socio->id, Member::query()->count()];
        });

        [$hecho, $socioId, $socios] = $resultado;
        $this->assertNull($hecho);
        $this->assertSame(2, $socios);
        $this->assertSame($nivel, DB::transactionLevel());
        $this->assertTrue(Member::query()->whereKey($socioId)->exists(), 'la escritura de quien llama queda');
        $this->assertSame(0, CommercialEvent::query()->count());
        if ($this->isPostgres()) {
            // En SQLite la clave foránea responde SQLSTATE 23000, que el registrador
            // confunde (desde antes) con la unicidad: se calla. En PG es 23503.
            $this->assertSame(['error'], $this->logged('commercial.event.record_failed'));
        }
    }

    /**
     * (a) La forma determinista de I-1 en PG: `correlation_id` es `uuid` y sale
     * del `Context`. En producción lo pone el servidor; aquí un valor que no es un
     * uuid hace fallar el INSERT sin dobles.
     */
    public function test_un_correlation_id_que_no_es_uuid_no_aborta_la_transaccion_de_quien_llama(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo en PostgreSQL: SQLite no valida el tipo uuid, así que el INSERT no falla.');
        }

        Context::add('correlation_id', 'no-es-un-uuid');

        [$hecho, $socioId] = DB::transaction(function (): array {
            $socio = $this->member();
            $hecho = $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], ['reference' => 'REF-UUID'], 'tx:uuid:approved');
            $this->member('3030303030');   // sigue sana

            return [$hecho, $socio->id];
        });

        $this->assertNull($hecho);
        $this->assertTrue(Member::query()->whereKey($socioId)->exists());
        $this->assertSame(2, Member::query()->count());
        $this->assertSame(0, CommercialEvent::query()->count());

        // El mismo log de siempre, con el mismo contenido.
        $fallos = array_values(array_filter($this->logs, fn (array $l): bool => $l['message'] === 'commercial.event.record_failed'));
        $this->assertCount(1, $fallos);
        $this->assertSame('error', $fallos[0]['level']);
        $this->assertSame(V::EV_PAYMENT_APPROVED, $fallos[0]['context']['event']);
        $this->assertSame('QueryException', $fallos[0]['context']['exception']);
        $this->assertStringContainsString('uuid', $fallos[0]['context']['message']);
        $this->assertSame([], $this->logged('commercial.event.recorded'));
    }

    /**
     * (d) El duplicado de todos los días (la reconciliación de Wompi vuelve a ver
     * el pago): lo para la lectura previa, sin intentar el INSERT y sin errores.
     */
    public function test_el_duplicado_normal_se_para_en_la_lectura_sin_intentar_el_insert_ni_registrar_errores(): void
    {
        $socio = $this->member();
        $sql = [];

        $resultado = DB::transaction(function () use ($socio, &$sql): array {
            $primero = $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], ['reference' => 'REF-1'], 'tx:1:approved');
            DB::listen(function ($q) use (&$sql): void {
                $sql[] = $q->sql;
            });
            $segundo = $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], ['reference' => 'REF-1'], 'tx:1:approved');
            $this->member('4040404040');   // sigue sana

            return [$primero, $segundo];
        });

        [$primero, $segundo] = $resultado;
        $this->assertInstanceOf(CommercialEvent::class, $primero);
        $this->assertNull($segundo);
        $this->assertSame(1, CommercialEvent::query()->where('dedupe_key', 'payment_approved:tx:1:approved')->count());
        $this->assertSame([], preg_grep('/^insert into\s+"?commercial_events"?/i', $sql), 'el duplicado no llega a intentar el INSERT');
        $this->assertNotSame([], preg_grep('/from\s+"?commercial_events"?.*"?dedupe_key"?\s*=/i', $sql), 'lo para la lectura previa');
        $this->assertSame([], $this->logged('commercial.event.record_failed'));
        $this->assertSame(['info'], $this->logged('commercial.event.recorded'));
    }

    /**
     * El hecho sigue siendo parte de la transacción de quien llama: si esta se
     * revierte, el hecho tampoco existe y no se encola nada. El punto de guardado
     * se LIBERA, no confirma por su cuenta. Y la evaluación sigue esperando al
     * COMMIT de quien llama (`afterCommit`). Con la cola `database` de verdad
     * (la de producción): `Queue::fake()` apunta el trabajo en el acto y no
     * respeta `afterCommit`.
     */
    public function test_el_hecho_va_con_la_transaccion_de_quien_llama_y_su_evaluacion_espera_al_commit(): void
    {
        config()->set('commercial.enabled', true);
        config()->set('queue.default', 'database');
        $socio = $this->member();
        $evaluaciones = fn (): int => DB::table('jobs')->where('payload', 'like', '%EvaluateCommercialSubject%')->count();

        try {
            DB::transaction(function () use ($socio, $evaluaciones): void {
                $hecho = $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], [], 'tx:rev:approved');
                $this->assertInstanceOf(CommercialEvent::class, $hecho);
                $this->assertSame(1, CommercialEvent::query()->count(), 'dentro de la transacción, el hecho se ve');
                $this->assertSame(0, $evaluaciones(), 'afterCommit: todavía no');
                throw new \RuntimeException('el pago falla después');
            });
        } catch (\RuntimeException) {
            // Esperada.
        }
        $this->assertSame(0, CommercialEvent::query()->count(), 'revertida la transacción de quien llama, el hecho tampoco existe');
        $this->assertSame(0, $evaluaciones(), 'ni su evaluación');

        DB::transaction(function () use ($socio, $evaluaciones): void {
            $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], [], 'tx:ok:approved');
            $this->assertSame(0, $evaluaciones(), 'afterCommit: todavía no');
        });
        $this->assertSame(1, $evaluaciones(), 'confirmada, una evaluación');
        $this->assertSame(1, CommercialEvent::query()->count());
    }

    /**
     * Un fallo deja el registrador listo para el siguiente hecho en la MISMA
     * transacción (el punto de guardado se libera también en el camino del fallo).
     */
    public function test_tras_un_fallo_el_siguiente_hecho_de_la_misma_transaccion_se_registra(): void
    {
        $socio = $this->member();

        [$malo, $bueno] = DB::transaction(fn (): array => [
            $this->recorder->record(V::EV_PAYMENT_APPROVED, ['lead_id' => 987654321, 'member_id' => $socio->id], [], 'tx:a:approved'),
            $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], [], 'tx:b:approved'),
        ]);

        $this->assertNull($malo);
        $this->assertInstanceOf(CommercialEvent::class, $bueno);
        $this->assertSame(['payment_approved:tx:b:approved'], CommercialEvent::query()->pluck('dedupe_key')->all());
    }

    /**
     * (1c, MENOR 2 del reviewer) En el camino feliz el punto de guardado se
     * LIBERA: después de un `record()` con éxito ya no existe. Se comprueba
     * dentro de un punto de guardado de la propia prueba, porque en PostgreSQL
     * el error «no existe» abortaría la transacción.
     */
    public function test_en_el_camino_feliz_el_punto_de_guardado_se_libera(): void
    {
        $socio = $this->member();

        DB::transaction(function () use ($socio): void {
            $this->assertInstanceOf(CommercialEvent::class, $this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], [], 'tx:release:approved'));
            $this->assertNull($this->recorder->record(V::EV_PAYMENT_APPROVED, ['member_id' => $socio->id], [], 'tx:release:approved'), 'el duplicado también');

            $pdo = DB::connection()->getPdo();
            $pdo->exec('SAVEPOINT prueba_release');
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT commercial_event_record');
                $this->fail('el punto de guardado del registrador sigue vivo: no se liberó');
            } catch (\PDOException $e) {
                $this->assertMatchesRegularExpression('/savepoint/i', $e->getMessage());
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT prueba_release');
            $pdo->exec('RELEASE SAVEPOINT prueba_release');
            $this->member('5050505050');   // sigue sana
        });

        $this->assertSame(1, CommercialEvent::query()->count());
    }

    /**
     * (1c) El sujeto como CIERRE se resuelve dentro del punto de guardado: si
     * resolverlo falla, `null`, `commercial.event.record_failed` y la transacción
     * de quien llama sigue sana. Aquí falla en los dos motores (una tabla que no
     * existe); el bloqueo real de `marketing_leads` vive en la prueba de PG.
     */
    public function test_un_sujeto_que_no_se_puede_resolver_no_rompe_la_transaccion_de_quien_llama(): void
    {
        [$hecho, $socio] = DB::transaction(function (): array {
            $socio = $this->member();
            $hecho = $this->recorder->record(V::EV_PAYMENT_APPROVED, function () use ($socio): array {
                DB::table('tabla_que_no_existe_1c')->where('id', $socio->id)->value('id');

                return ['member_id' => $socio->id];
            }, [], 'tx:closure:approved');
            $this->member('6060606060');   // sigue sana (en PG, sin el punto de guardado: 25P02)

            return [$hecho, $socio];
        });

        $this->assertNull($hecho);
        $this->assertTrue(Member::query()->whereKey($socio->id)->exists());
        $this->assertSame(0, CommercialEvent::query()->count());
        $this->assertSame(['error'], $this->logged('commercial.event.record_failed'));

        // Y el cierre que SÍ resuelve registra como un array.
        $ok = DB::transaction(fn () => $this->recorder->record(V::EV_PAYMENT_APPROVED, fn (): array => ['member_id' => $socio->id], [], 'tx:closure-ok:approved'));
        $this->assertSame($socio->id, $ok->member_id);
    }

    /**
     * (1c) La consulta PROPIA del observador de asistencias (`entriesFor()`, el
     * recuento de visitas que decide si es la primera o un hito) corre dentro de
     * la transacción de quien registra la entrada y va también por `isolated()`.
     *
     * Un recuento que falla de verdad no se puede provocar sin dobles (el INSERT
     * de la asistencia ya tiene la tabla). Se provoca un error REAL del servidor
     * justo antes del recuento, en la misma conexión: en PostgreSQL deja la
     * transacción abortada exactamente igual que si fallara el recuento.
     */
    public function test_un_recuento_de_visitas_que_falla_no_rompe_la_transaccion_de_la_asistencia(): void
    {
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'count(distinct date(captured_at))')) {
                DB::connection()->getPdo()->exec('select * from tabla_que_no_existe_1c_asistencias');
            }
        });

        [$asistencia, $socio] = DB::transaction(function (): array {
            $socio = $this->member();
            $usuario = User::query()->create(['name' => 'Socio 1c', 'email' => 'socio-1c-'.uniqid().'@iron.test', 'password' => 'x']);
            $asistencia = Attendance::query()->create([
                'user_id' => $usuario->id, 'member_id' => $socio->id, 'action' => 'entry', 'source' => 'manual', 'captured_at' => now(),
            ]);
            $this->member('7070707070');   // sigue sana (en PG, sin el punto de guardado: 25P02)

            return [$asistencia, $socio];
        });

        $this->assertTrue(Attendance::query()->whereKey($asistencia->id)->exists(), 'la asistencia queda');
        $this->assertSame(0, CommercialEvent::query()->count(), 'sin recuento no se decide el hecho: no se registra nada');
        $fallos = array_values(array_filter($this->logs, fn (array $l): bool => $l['message'] === 'commercial.event.record_failed'));
        $this->assertCount(1, $fallos);
        $this->assertSame(V::EV_FIRST_CHECKIN.'|'.V::EV_ATTENDANCE_MILESTONE, $fallos[0]['context']['event']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function observadoresQueResuelvenSujeto(): array
    {
        return [
            'pago' => ['pago', V::EV_PAYMENT_APPROVED],
            'membresía' => ['membresia', V::EV_MEMBERSHIP_ACTIVATED],
            'ficha enlazada' => ['ficha', V::EV_APP_LINKED],
            'cita' => ['cita', V::EV_APPOINTMENT_CREATED],
            'conversación' => ['conversacion', V::EV_HUMAN_REQUESTED],
            'asistencia' => ['asistencia', V::EV_FIRST_CHECKIN],
            'factura' => ['factura', V::EV_INVOICE_REQUESTED],
        ];
    }

    /**
     * (1c) Los observadores pasan el sujeto como CIERRE: si resolverlo falla, el
     * fallo se queda dentro de `record()` (log `record_failed`, nada registrado)
     * y no sale por el `save()` de quien escribe. Antes salía: el resolver
     * corría en el observador, fuera de la protección. Aquí el resolver falla con
     * una excepción de PHP (un doble), así que esto fija DÓNDE se resuelve; que
     * un fallo de la BASE no deje abortada la transacción lo fijan las pruebas
     * de PostgreSQL de la cadena de pagos.
     */
    #[DataProvider('observadoresQueResuelvenSujeto')]
    public function test_si_el_resolver_falla_el_observador_no_lanza_y_no_registra(string $caso, string $evento): void
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150000001',
            'phone' => '3150000001', 'name' => 'Prospecto 1c', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $usuario = User::create(['name' => 'Usuario 1c', 'email' => 'u1c-'.uniqid().'@iron.test', 'password' => 'x']);
        $socio = $this->member();
        $pago = PaymentTransaction::create([
            'reference' => 'REF-1C-'.uniqid(), 'idempotency_key' => 'idem-1c-'.uniqid(), 'member_id' => $socio->id,
            'user_id' => $usuario->id, 'amount' => 80000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi',
        ]);
        $conversacion = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $sinApp = $this->member('8080808080');

        $this->app->instance(CommercialSubjectResolver::class, new class extends CommercialSubjectResolver
        {
            public function fromMember(?int $memberId): array
            {
                throw new \RuntimeException('base caída (resolver)');
            }

            public function fromLead(?int $leadId): array
            {
                throw new \RuntimeException('base caída (resolver)');
            }

            public function fromUser(?int $userId): array
            {
                throw new \RuntimeException('base caída (resolver)');
            }
        });

        DB::transaction(function () use ($caso, $lead, $usuario, $socio, $pago, $conversacion, $sinApp): void {
            match ($caso) {
                'pago' => $pago->update(['status' => 'approved']),
                'membresia' => $usuario->update(['membership_end_date' => now()->addDays(30)->toDateString()]),
                'ficha' => $sinApp->update(['user_id' => $usuario->id]),
                'cita' => MarketingAppointment::create([
                    'marketing_lead_id' => $lead->id, 'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Visita 1c',
                    'status' => MarketingAppointment::STATUS_SCHEDULED, 'source' => MarketingAppointment::SOURCE_CRM,
                    'scheduled_at' => now()->addDay(),
                ]),
                'conversacion' => $conversacion->update(['human_takeover' => true, 'human_takeover_source' => 'customer_request']),
                'asistencia' => Attendance::query()->create([
                    'user_id' => $usuario->id, 'member_id' => $socio->id, 'action' => 'entry', 'source' => 'manual', 'captured_at' => now(),
                ]),
                'factura' => ElectronicInvoice::create([
                    'source_type' => PaymentTransaction::class, 'source_id' => $pago->id, 'type' => 'invoice',
                    'status' => 'pending', 'full_number' => 'IBFE-1C', 'total' => '80000.00',
                ]),
            };
            $this->member('9090909090');   // la transacción de quien escribe sigue
        });

        $this->assertSame(0, CommercialEvent::query()->where('event', $evento)->count());
        $fallos = array_values(array_filter($this->logs, fn (array $l): bool => $l['message'] === 'commercial.event.record_failed'));
        $this->assertCount(1, $fallos);
        $this->assertSame($evento, $fallos[0]['context']['event']);
        $this->assertSame('RuntimeException', $fallos[0]['context']['exception']);
    }

    /**
     * (1b) La consulta PROPIA del observador de facturas (`subjectFor()` busca el
     * origen) también vuelve a un punto de guardado antes de tragarse el fallo.
     * Aquí falla en los dos motores: el origen apunta a una tabla que no existe.
     */
    public function test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_se_guarda_y_la_transaccion_sigue_sana(): void
    {
        [$factura, $socio] = DB::transaction(function (): array {
            $factura = ElectronicInvoice::create([
                'source_type' => FuenteDeFacturaSinTabla::class, 'source_id' => 1, 'type' => 'invoice',
                'status' => 'pending', 'full_number' => 'IBFE-SIN-TABLA', 'total' => '80000.00',
            ]);
            $socio = $this->member();   // sigue sana (en PG, sin el punto de guardado: 25P02)

            return [$factura, $socio];
        });

        $this->assertTrue(ElectronicInvoice::query()->whereKey($factura->id)->exists(), 'la factura queda');
        $this->assertTrue(Member::query()->whereKey($socio->id)->exists());
        $this->assertSame(0, CommercialEvent::query()->count(), 'sin origen no hay sujeto: no se registra nada, como antes');
    }
}

/** Un origen de factura cuya tabla no existe: hace fallar `find()` en los dos motores. */
final class FuenteDeFacturaSinTabla extends Model
{
    protected $table = 'fuente_de_factura_sin_tabla';
}
