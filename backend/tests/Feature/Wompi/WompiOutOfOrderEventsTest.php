<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;
use Tests\Unit\Wompi\PaymentStateMachineTest;

/**
 * EVENTOS QUE LLEGAN TARDE, O AL REVÉS.
 *
 * Wompi reintenta durante 24 horas y no promete orden: un `PENDING` de hace
 * tres horas puede entrar después del `APPROVED`, y un `DECLINED` de un intento
 * anterior después del cobro bueno. Si eso degradara el estado, un socio que
 * pagó se quedaría sin membresía —o peor, con una notificación de pago
 * rechazado— por un evento viejo.
 *
 * Que la máquina de estados no retrocede ya estaba probado sobre la CLASE PURA
 * ({@see PaymentStateMachineTest}). Lo que faltaba es esto:
 * el camino entero, entrando por donde entran los eventos de verdad. Un test
 * que empieza a mitad del recorrido no puede ver un fallo de orden, que es
 * justo lo que aquí se mide.
 */
class WompiOutOfOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Member $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x', 'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz', 'events_secret' => 'test_events_xyz',
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

    private function entregar(string $reference, string $status, int $sello): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => 'wompi-tx-001', 'status' => $status, 'reference' => $reference,
                'amount_in_cents' => 8000000, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp' => $sello,
        ];
        $payload['signature']['checksum'] = strtoupper(
            (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
                ->computeWebhookChecksum($payload, 'test_events_xyz')
        );

        return WompiWebhookService::make()->handle($payload, (string) json_encode($payload));
    }

    /** Un `PENDING` rezagado no deshace un cobro. */
    public function test_a_late_pending_never_undoes_an_approved_payment(): void
    {
        $tx = $this->cobroPendiente();

        $this->entregar($tx->reference, 'APPROVED', 1700000000);
        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $vence = $this->user->fresh()->membership_end_date;

        $r = $this->entregar($tx->reference, 'PENDING', 1700000500);
        $this->assertSame(200, $r['http']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status, 'el cobro retrocedió');
        $this->assertNotNull($tx->paid_at);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->assertEquals($vence, $this->user->fresh()->membership_end_date, 'la membresía se movió');
    }

    /**
     * Un `DECLINED` tardío no revierte el cobro NI avisa de un rechazo.
     *
     * Lo segundo importa tanto como lo primero: al socio que ya pagó no se le
     * puede mandar un aviso de pago rechazado por un evento viejo.
     */
    public function test_a_late_declined_neither_reverts_nor_notifies(): void
    {
        $tx = $this->cobroPendiente();
        $this->entregar($tx->reference, 'APPROVED', 1700000000);

        $avisos = Mockery::mock(NotificationService::class);
        $avisos->shouldNotReceive('notifyPaymentRejected');
        $this->app->instance(NotificationService::class, $avisos);

        $r = $this->entregar($tx->reference, 'DECLINED', 1700000900);
        $this->assertSame(200, $r['http']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertNull($tx->declined_at);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->assertNotNull($this->user->fresh()->membership_end_date);
    }
}
