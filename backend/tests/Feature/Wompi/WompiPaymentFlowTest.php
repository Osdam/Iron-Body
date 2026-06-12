<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiReconciliationService;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Flujo Wompi end-to-end a nivel de SERVICIO (sin red real: Http::fake). Cubre
 * lo crítico de una pasarela: creación pending, aprobación por webhook con
 * activación ÚNICA de membresía, idempotencia ante webhook duplicado, rechazo de
 * firma inválida y de monto alterado, y reconciliación por consulta directa.
 */
class WompiPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private Member $member;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env'              => 'sandbox',
            'api_url'          => 'https://sandbox.wompi.co/v1',
            'public_key'       => 'pub_test_x',
            'private_key'      => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz',
            'events_secret'    => 'test_events_xyz',
            'methods'          => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));

        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
        ]);
        $this->user = User::create([
            'name' => 'Oscar Mancipe', 'email' => 'oscar@example.com',
            'password' => bcrypt('x'), 'document' => '1004301550', 'phone' => '3215542105',
            'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'Oscar Mancipe', 'email' => 'oscar@example.com',
            'document_number' => '1004301550', 'phone' => '3215542105',
            'status' => Member::STATUS_PENDING_REGISTRATION, 'user_id' => $this->user->id,
        ]);
    }

    /** Http::fake: merchant (aceptación) + POST/GET transactions. */
    private function fakeWompi(string $createStatus = 'PENDING', string $getStatus = 'APPROVED'): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'accept_tok_123',
                        'permalink'        => 'https://wompi.co/terminos',
                        'type'             => 'END_USER_POLICY',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal_tok_456',
                        'permalink'        => 'https://wompi.co/datos',
                        'type'             => 'PERSONAL_DATA_AUTH',
                    ],
                ],
            ], 200),
            'sandbox.wompi.co/v1/transactions*' => function ($request) use ($createStatus, $getStatus) {
                $status = $request->method() === 'POST' ? $createStatus : $getStatus;
                return Http::response([
                    'data' => [
                        'id'              => 'wompi-tx-001',
                        'status'          => $status,
                        'reference'       => $request->method() === 'POST'
                            ? (json_decode($request->body(), true)['reference'] ?? 'ref')
                            : 'ref',
                        'amount_in_cents' => 8000000,
                        'currency'        => 'COP',
                        'payment_method'  => ['type' => 'NEQUI'],
                    ],
                ], 200);
            },
        ]);
    }

    private function payNequi(): PaymentTransaction
    {
        return WompiNequiPaymentService::make()->process([
            'plan_id'   => $this->plan->id,
            'member_id' => $this->member->id,
            'user_id'   => $this->user->id,
            'phone'     => '3215542105',
            'customer'  => ['email' => 'oscar@example.com', 'name' => 'Oscar Mancipe', 'phone' => '3215542105'],
        ], '200.21.179.249', 'phpunit');
    }

    private function approvedWebhook(string $reference, int $cents = 8000000): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data'  => ['transaction' => [
                'id' => 'wompi-tx-001', 'status' => 'APPROVED',
                'reference' => $reference, 'amount_in_cents' => $cents, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature'   => [
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum'   => '',
            ],
            'timestamp' => 1700000000,
        ];
        $secret = 'test_events_xyz';
        $checksum = (new WompiSignatureService(['events_secret' => $secret]))
            ->computeWebhookChecksum($payload, $secret);
        $payload['signature']['checksum'] = strtoupper($checksum);
        return $payload;
    }

    public function test_nequi_payment_creates_pending_transaction(): void
    {
        $this->fakeWompi();
        $tx = $this->payNequi();

        $this->assertSame(PaymentStateMachine::PENDING, $tx->status);
        $this->assertSame('wompi-tx-001', $tx->wompi_transaction_id);
        $this->assertSame('wompi', $tx->provider);
        $this->assertSame(80000.0, (float) $tx->amount);
        // Consentimiento registrado.
        $this->assertDatabaseHas('payment_consents', ['reference' => $tx->reference]);
    }

    public function test_webhook_approves_and_activates_membership_once(): void
    {
        $this->fakeWompi();
        $tx = $this->payNequi();
        $payload = $this->approvedWebhook($tx->reference);
        $raw = json_encode($payload);

        $r1 = WompiWebhookService::make()->handle($payload, $raw);
        $this->assertSame(200, $r1['http']);
        $this->assertSame('processed', $r1['status']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertNotNull($tx->approved_at);

        // Membresía activada: un único registro legado en `payments`.
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->user->refresh();
        $this->assertNotNull($this->user->membership_end_date);

        // Webhook DUPLICADO (mismo payload) → idempotente, sin doble activación.
        $r2 = WompiWebhookService::make()->handle($payload, $raw);
        $this->assertSame('duplicate', $r2['status']);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->fakeWompi();
        $tx = $this->payNequi();
        $payload = $this->approvedWebhook($tx->reference);
        $payload['signature']['checksum'] = 'DEADBEEF'; // alterado

        $r = WompiWebhookService::make()->handle($payload, json_encode($payload));

        $this->assertSame(401, $r['http']);
        $tx->refresh();
        $this->assertSame(PaymentStateMachine::PENDING, $tx->status); // sin cambios
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $this->fakeWompi();
        $tx = $this->payNequi();
        // Monto alterado (1 centavo) con checksum VÁLIDO para ese monto.
        $payload = $this->approvedWebhook($tx->reference, cents: 1);

        $r = WompiWebhookService::make()->handle($payload, json_encode($payload));

        $this->assertSame('amount_mismatch', $r['status']);
        $tx->refresh();
        $this->assertNotSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
    }

    public function test_reconciliation_approves_pending_payment(): void
    {
        // create=PENDING, get=APPROVED → la reconciliación lo resuelve.
        $this->fakeWompi(createStatus: 'PENDING', getStatus: 'APPROVED');
        $tx = $this->payNequi();
        $this->assertSame(PaymentStateMachine::PENDING, $tx->status);

        $stats = WompiReconciliationService::make()->reconcileOne($tx->fresh());
        $this->assertSame('updated', $stats);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }
}
