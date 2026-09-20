<?php

namespace Tests\Feature\Payments;

use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use App\Services\Payments\PaymentMembershipActivator;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La referencia de un cobro de PASARELA no puede existir dos veces.
 *
 * `PaymentMembershipActivator::activate()` se apoya en `Payment::firstOrCreate`
 * por `reference` y lo llama «idempotencia dura». No lo era: sin restricción en
 * la base es SELECT-luego-INSERT, y entre las dos sentencias cabe otro proceso.
 * Los disparadores reales son varios y concurrentes —webhook de Wompi,
 * reconciliación, aceptación de cobro de ULTRON—, así que la carrera no es
 * teórica: dos filas con la misma referencia significan doble cobro contable,
 * dos facturas electrónicas y la membresía extendida dos veces.
 *
 * Estas pruebas fijan las DOS capas:
 *   1. La base rechaza la segunda fila de pasarela (índice único parcial).
 *   2. La aplicación no se rompe con ese rechazo: se queda con la que ya hay.
 *
 * Y fijan también lo que NO debe cambiar: el mostrador SÍ puede repetir una
 * referencia (en producción hay 154 cobros con la misma), porque allí es un
 * número de comprobante escrito a mano, no la llave de un cobro de pasarela.
 */
class PaymentReferenceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'IRON-20260918-CARRERA';

    /** Reentrada de la carrera simulada: solo una vez por prueba. */
    private static bool $carreraDisparada = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();                  // ninguna llamada real sale de aquí
        Http::preventStrayRequests();  // y si alguien lo intenta, revienta

        config(['billing.enabled' => false]);   // nunca se llama a Factus

        self::$carreraDisparada = false;
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'benefits' => '', 'active' => true,
        ]);
    }

    private function socio(): User
    {
        return User::create([
            'name' => 'Socio Carrera',
            'email' => 'carrera-'.uniqid().'@example.com',
            'password' => bcrypt('secret-password'),
            'status' => 'pending',
        ]);
    }

    private function transaccion(User $u, Plan $p): PaymentTransaction
    {
        $tx = PaymentTransaction::create([
            'reference' => self::REF,
            'idempotency_key' => 'idem-'.self::REF,
            'user_id' => $u->id,
            'plan_id' => $p->id,
            'amount' => 80000,
            'currency' => 'COP',
            'status' => 'APPROVED',
            'provider' => 'wompi',
        ]);
        $tx->forceFill(['paid_at' => now()])->save();

        return $tx;
    }

    /** Un cobro de pasarela tal y como lo escribe el activador. */
    private function filaDePasarela(User $u, Plan $p, array $extra = []): array
    {
        return array_merge([
            'user_id' => $u->id,
            'plan_id' => $p->id,
            'amount' => 80000,
            'method' => 'wompi',
            'status' => 'paid',
            'paid_at' => now(),
            'reference' => self::REF,
            'origin' => PaymentOrigin::GATEWAY->value,
        ], $extra);
    }

    // ── 1. La base ──────────────────────────────────────────────────────────

    public function test_la_base_rechaza_dos_cobros_de_pasarela_con_la_misma_referencia(): void
    {
        $u = $this->socio();
        $p = $this->plan();

        Payment::create($this->filaDePasarela($u, $p));

        $choque = null;
        try {
            // Savepoint: el rechazo no debe tumbar la transacción de la prueba
            // (en PostgreSQL un error aborta la transacción entera).
            DB::transaction(fn () => Payment::create($this->filaDePasarela($u, $p)));
        } catch (UniqueConstraintViolationException $e) {
            $choque = $e;
        }

        $this->assertNotNull(
            $choque,
            'la base debe rechazar una segunda fila de pasarela con la misma reference'
        );
        $this->assertSame(1, Payment::where('reference', self::REF)->count());
    }

    /**
     * Lo que NO se puede romper: en el mostrador la referencia es un número de
     * comprobante escrito por una persona y se repite legítimamente.
     */
    public function test_el_mostrador_puede_repetir_la_misma_referencia(): void
    {
        $u = $this->socio();
        $p = $this->plan();

        Payment::create($this->filaDePasarela($u, $p, [
            'origin' => PaymentOrigin::COUNTER->value, 'method' => 'cash', 'reference' => 'REC-1001',
        ]));
        Payment::create($this->filaDePasarela($u, $p, [
            'origin' => PaymentOrigin::COUNTER->value, 'method' => 'transfer', 'reference' => 'REC-1001',
        ]));

        $this->assertSame(2, Payment::where('reference', 'REC-1001')->count());
    }

    /** Y los cobros sin referencia (9 en producción) tampoco compiten entre sí. */
    public function test_varios_cobros_sin_referencia_conviven(): void
    {
        $u = $this->socio();
        $p = $this->plan();

        Payment::create($this->filaDePasarela($u, $p, ['reference' => null]));
        Payment::create($this->filaDePasarela($u, $p, ['reference' => null]));

        $this->assertSame(2, Payment::whereNull('reference')->count());
    }

    /** El SELECT de `firstOrCreate` sobre `payments` por `reference`. */
    private function esLaBusquedaPorReferencia(string $sql): bool
    {
        $s = strtolower($sql);

        return str_starts_with(ltrim($s), 'select')
            && str_contains($s, 'from "payments"')
            && str_contains($s, '"reference"');
    }

    // ── 2. La aplicación ────────────────────────────────────────────────────

    /**
     * LA CARRERA. Otro proceso escribe el pago entre el SELECT y el INSERT de
     * `firstOrCreate`, que es justo la ventana que el índice cierra.
     *
     * Se reproduce con `DB::listen`: en cuanto el activador termina de ejecutar
     * su SELECT por `reference` —y antes de llegar al INSERT— se lanza un
     * segundo `activate()` completo sobre la misma transacción. Ese segundo no
     * ve nada en la tabla, así que crea el pago, extiende la membresía y encola
     * la factura. Es el interleaving de dos webhooks simultáneos.
     *
     * El enganche va en el SELECT y no en el evento `creating` a propósito:
     * `createOrFirst()` envuelve el INSERT en un SAVEPOINT, y un competidor
     * escrito dentro de él desaparecería en el rollback del choque. En la
     * realidad el otro proceso escribe en su propia transacción y su fila
     * sobrevive; aquí también, porque se escribe antes del savepoint.
     */
    public function test_la_carrera_deja_un_solo_pago_una_extension_y_una_factura(): void
    {
        $u = $this->socio();
        $p = $this->plan();
        $tx = $this->transaccion($u, $p);

        DB::listen(function ($query) use ($tx) {
            if (self::$carreraDisparada || ! $this->esLaBusquedaPorReferencia($query->sql)) {
                return;
            }
            self::$carreraDisparada = true;

            // El «otro proceso», dentro de la ventana.
            app(PaymentMembershipActivator::class)
                ->activate(PaymentTransaction::find($tx->id), 'wompi');
        });

        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        $this->assertTrue(self::$carreraDisparada, 'la carrera no llegó a dispararse');

        $this->assertSame(
            1,
            Payment::where('reference', self::REF)->count(),
            'dos procesos concurrentes crearon dos cobros con la misma referencia'
        );

        // Una sola extensión: 30 días, no 60.
        $this->assertSame(
            MembershipPeriod::today()->addDays(30)->toDateString(),
            $u->fresh()->membership_end_date instanceof \DateTimeInterface
                ? $u->fresh()->membership_end_date->format('Y-m-d')
                : (string) $u->fresh()->membership_end_date,
            'la membresía se extendió más de una vez'
        );

        // Una sola factura electrónica encolada.
        $this->assertSame(1, ElectronicInvoice::count(), 'se encoló más de una factura');

        Http::assertNothingSent();
    }

    /**
     * El reintento NORMAL del webhook (secuencial, sin carrera) sigue siendo
     * idempotente: esto es la red de regresión del camino feliz, que el índice
     * no debe alterar.
     */
    public function test_el_reintento_secuencial_del_webhook_sigue_siendo_idempotente(): void
    {
        $u = $this->socio();
        $p = $this->plan();
        $tx = $this->transaccion($u, $p);

        $activador = app(PaymentMembershipActivator::class);
        $activador->activate($tx, 'wompi');
        $activador->activate($tx->fresh(), 'wompi');

        $this->assertSame(1, Payment::where('reference', self::REF)->count());
        $this->assertSame(1, ElectronicInvoice::count());
        $this->assertSame(
            MembershipPeriod::today()->addDays(30)->toDateString(),
            $u->fresh()->membership_end_date instanceof \DateTimeInterface
                ? $u->fresh()->membership_end_date->format('Y-m-d')
                : (string) $u->fresh()->membership_end_date,
        );
    }

    /**
     * El cobro de mostrador que se parece a uno de pasarela no puede tragarse
     * la activación.
     *
     * El índice único acota por `origin='gateway'`, así que la búsqueda de
     * idempotencia tiene que acotar igual. Cuando no lo hacía, una referencia
     * repetida desde el mostrador devolvía ESA fila, `wasRecentlyCreated` era
     * false y la membresía no se extendía: el socio pagaba por la app y no
     * pasaba nada, sin excepción que mirar en el log.
     */
    public function test_un_cobro_de_mostrador_con_la_misma_referencia_no_se_come_la_activacion(): void
    {
        $u = $this->socio();
        $p = $this->plan();
        $tx = $this->transaccion($u, $p);

        Payment::create($this->filaDePasarela($u, $p, [
            'method' => 'cash',
            'origin' => PaymentOrigin::COUNTER->value,
        ]));

        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        $this->assertSame(
            1,
            Payment::where('reference', self::REF)->where('origin', PaymentOrigin::GATEWAY->value)->count(),
            'la fila de pasarela se creó pese a la del mostrador',
        );
        $this->assertSame(2, Payment::where('reference', self::REF)->count(), 'y la del mostrador sigue ahí, intacta');
        $this->assertNotNull($u->fresh()->membership_end_date, 'la membresía se extendió');
    }
}
