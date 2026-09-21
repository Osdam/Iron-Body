<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * QUÉ PASA CUANDO ALGO SE CAE CON EL DINERO YA DENTRO.
 *
 * El webhook contesta 500 para que Wompi reintente —tres veces en 24 h, a los
 * 30 min, 3 h y 24 h—. Ese 500 es una PROMESA: pedimos que nos lo vuelvan a
 * mandar porque esta vez no pudimos aplicarlo.
 *
 * La promesa estaba rota. La fila del evento se escribe antes de procesar y
 * sobrevive al rollback, así que la reentrega chocaba con la unicidad de
 * `payload_hash` y se contestaba «duplicate, 200»: pedíamos el reintento y
 * luego lo tirábamos. Con la activación de membresía cayéndose una vez, el
 * desenlace era pago cobrado en Wompi, transacción sin aprobar en casa,
 * membresía sin activar y ninguna oportunidad más.
 */
class WompiFailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Member $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox',
            'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x',
            'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz',
            'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->user = User::create([
            'name' => 'Oscar Mancipe', 'email' => 'oscar@example.com', 'password' => bcrypt('x'),
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'Oscar Mancipe', 'email' => 'oscar@example.com', 'document_number' => '1004301550',
            'phone' => '3215542105', 'status' => Member::STATUS_PENDING_REGISTRATION, 'user_id' => $this->user->id,
        ]);

        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'accept_tok_123', 'permalink' => 'https://wompi.co/terminos', 'type' => 'END_USER_POLICY'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'personal_tok_456', 'permalink' => 'https://wompi.co/datos', 'type' => 'PERSONAL_DATA_AUTH'],
            ]], 200),
            'sandbox.wompi.co/v1/transactions*' => fn ($request) => Http::response(['data' => [
                'id' => 'wompi-tx-001', 'status' => 'PENDING',
                'reference' => json_decode($request->body(), true)['reference'] ?? 'ref',
                'amount_in_cents' => 8000000, 'currency' => 'COP',
                'payment_method' => ['type' => 'NEQUI'],
            ]], 200),
        ]);
    }

    private function cobroPendiente(): PaymentTransaction
    {
        return WompiNequiPaymentService::make()->process([
            'plan_id' => $this->plan->id, 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'phone' => '3215542105',
            'customer' => ['email' => 'oscar@example.com', 'name' => 'Oscar Mancipe', 'phone' => '3215542105'],
        ], '200.21.179.249', 'phpunit');
    }

    private function eventoAprobado(string $reference): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => 'wompi-tx-001', 'status' => 'APPROVED', 'reference' => $reference,
                'amount_in_cents' => 8000000, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp' => 1700000000,
        ];
        $payload['signature']['checksum'] = strtoupper(
            (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
                ->computeWebhookChecksum($payload, 'test_events_xyz')
        );

        return $payload;
    }

    private function entregar(array $payload): array
    {
        return WompiWebhookService::make()->handle($payload, (string) json_encode($payload));
    }

    /**
     * La activación de membresía se cae una vez.
     *
     * Corre DENTRO de la transacción de la transición y sin try/catch —a
     * diferencia de sus vecinas de suscripción y notificación, que sí lo
     * tienen—, así que su caída revierte la aprobación entera. Eso es
     * deliberado: o entra todo o no entra nada. Lo que faltaba era la segunda
     * mitad, que la reentrega lo arregle.
     *
     * Falla sobre el commit anterior: la reentrega contestaba «duplicate» y el
     * cobro se quedaba sin aprobar para siempre.
     */
    public function test_a_failed_activation_is_repaired_by_the_redelivery(): void
    {
        $tx = $this->cobroPendiente();
        $payload = $this->eventoAprobado($tx->reference);

        $real = $this->app->make(PaymentMembershipActivator::class);
        $activador = Mockery::mock(PaymentMembershipActivator::class);
        $activador->shouldReceive('activate')->once()->ordered()
            ->andThrow(new RuntimeException('activación caída'));
        $activador->shouldReceive('activate')->ordered()
            ->andReturnUsing(fn ($transaccion, $origen) => $real->activate($transaccion, $origen));
        $this->app->instance(PaymentMembershipActivator::class, $activador);

        // Primera entrega: se cae por dentro.
        $r1 = $this->entregar($payload);
        $this->assertSame(500, $r1['http'], 'sin 500 no hay reintento que valga');
        $this->assertSame('process_error', $r1['status']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::PENDING, $tx->status, 'quedó un pago a medias');
        $this->assertNull($tx->paid_at);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, PaymentWebhookEvent::sole()->processing_status);

        // Reentrega IDÉNTICA, la que Wompi manda a los 30 minutos.
        $r2 = $this->entregar($payload);
        $this->assertSame(200, $r2['http']);
        $this->assertSame('processed', $r2['status'], 'el reintento que pedimos se tiró a la basura');

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->assertNotNull($this->user->fresh()->membership_end_date);

        // Y una tercera entrega ya no aplica nada: lo procesado no se repite.
        $r3 = $this->entregar($payload);
        $this->assertSame('duplicate', $r3['status']);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    /**
     * La base deja de contestar en mitad de la transición.
     *
     * El corte va en el UPDATE de la propia transacción de pago, que es donde
     * se sella el estado. Lo que se mide es que no quede un pago a medias: ni
     * fila aprobada sin membresía, ni membresía sin fila aprobada.
     */
    public function test_a_database_timeout_mid_transition_leaves_nothing_half_done(): void
    {
        $tx = $this->cobroPendiente();
        $payload = $this->eventoAprobado($tx->reference);

        /*
         * El corte es de UNA sola escritura, y por eso no se desarma nada.
         *
         * La forma corta de hacer esto —registrar el fallo y después
         * `flushEventListeners()`— se lleva por delante TODOS los oyentes del
         * modelo, incluido `MarketingPaymentOutcomeObserver`, que corre en
         * `updated` dentro de la misma transacción. La reparación de abajo se
         * mediría entonces sobre un sistema desarmado, que no es el que corre
         * en producción. Con un oyente que falla una vez y se aparta solo, la
         * segunda entrega pasa por la máquina entera.
         */
        $todaviaFalla = true;
        PaymentTransaction::updating(function () use (&$todaviaFalla) {
            if (! $todaviaFalla) {
                return;
            }
            $todaviaFalla = false;

            throw new QueryException('pgsql', 'update payment_transactions ...', [],
                new \PDOException('SQLSTATE[57014]: canceling statement due to statement timeout'));
        });

        $r = $this->entregar($payload);
        $this->assertSame(500, $r['http'], 'un corte de base no puede contestarse con 200');
        $this->assertSame('process_error', $r['status']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::PENDING, $tx->status);
        $this->assertNull($tx->approved_at);
        $this->assertNull($tx->paid_at);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count(), 'membresía sin pago detrás');
        $this->assertNull($this->user->fresh()->membership_end_date);

        // Y el evento queda reintentable, no dado por bueno.
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, PaymentWebhookEvent::sole()->processing_status);

        // Con la base de vuelta —y con los observers en su sitio—, la
        // reentrega lo aplica.
        $r2 = $this->entregar($payload);
        $this->assertSame('processed', $r2['status']);
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    /**
     * El proceso muere ANTES de poder marcar nada.
     *
     * Es la otra mitad, y la más probable en un fallo de infraestructura: OOM,
     * kill, timeout de php-fpm, 504 del balanceador. La fila del evento queda
     * escrita en `received` y nadie llega nunca a cerrarla, así que la
     * reentrega la veía «ya registrada» y contestaba duplicate.
     *
     * No se puede matar el proceso dentro de un test, así que se monta el
     * estado que ese proceso DEJA: la fila del evento con su hash, en
     * `received` y vieja, y el cobro sin aplicar.
     */
    public function test_an_event_stuck_in_received_is_retried_when_it_goes_stale(): void
    {
        $tx = $this->cobroPendiente();
        $payload = $this->eventoAprobado($tx->reference);
        $crudo = (string) json_encode($payload);

        PaymentWebhookEvent::create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'wompi',
            'event_type' => 'transaction.updated',
            'transaction_id' => 'wompi-tx-001',
            'payload_hash' => hash('sha256', $crudo),
            'payload' => $payload,
            'processing_status' => PaymentWebhookEvent::STATUS_RECEIVED,
        ]);
        PaymentWebhookEvent::sole()->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        $r = $this->entregar($payload);

        $this->assertSame('processed', $r['status'], 'la aprobación se perdió para siempre');
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->assertSame(1, PaymentWebhookEvent::count(), 'se duplicó la fila del evento');
    }

    /**
     * Pero un `received` RECIÉN escrito sigue siendo un duplicado.
     *
     * Es lo que separa «el proceso se murió» de «hay otro trabajando ahora
     * mismo». Sin el umbral, dos entregas simultáneas de Wompi se pondrían a
     * aplicar el mismo pago a la vez.
     */
    public function test_a_fresh_received_event_is_still_a_duplicate(): void
    {
        $tx = $this->cobroPendiente();
        $payload = $this->eventoAprobado($tx->reference);

        PaymentWebhookEvent::create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'wompi',
            'event_type' => 'transaction.updated',
            'transaction_id' => 'wompi-tx-001',
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'payload' => $payload,
            'processing_status' => PaymentWebhookEvent::STATUS_RECEIVED,
        ]);

        $r = $this->entregar($payload);

        $this->assertSame('duplicate', $r['status']);
        $this->assertSame(PaymentStateMachine::PENDING, $tx->fresh()->status);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
    }
}
