<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bloque D — rutas HTTP Wompi: webhook público (firma), cobro autenticado,
 * consentimientos obligatorios, ownership y status. Sin red real (Http::fake).
 */
class WompiRoutesTest extends TestCase
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
            'name' => 'Oscar', 'email' => 'oscar@example.com', 'password' => bcrypt('x'),
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'Oscar', 'email' => 'oscar@example.com', 'document_number' => '1004301550',
            'phone' => '3215542105', 'status' => Member::STATUS_ACTIVE, 'user_id' => $this->user->id,
        ]);
    }

    private function fakeWompi(): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'a_tok', 'permalink' => 'https://w/terms'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'p_tok', 'permalink' => 'https://w/data'],
            ]], 200),
            'sandbox.wompi.co/v1/transactions*' => fn ($req) => Http::response(['data' => [
                'id' => 'wompi-tx-001', 'status' => 'PENDING',
                'reference' => json_decode($req->body(), true)['reference'] ?? 'ref',
                'amount_in_cents' => 8000000, 'currency' => 'COP', 'payment_method' => ['type' => 'NEQUI'],
            ]], 200),
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    public function test_nequi_route_requires_consents(): void
    {
        $this->fakeWompi();
        $this->postJson('/api/payments/wompi/nequi', [
            'plan_id' => $this->plan->id, 'phone' => '3215542105',
            // sin accepted_terms / accepted_personal_data
        ], $this->auth())->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_terms', 'accepted_personal_data']);
    }

    public function test_nequi_route_creates_pending_transaction(): void
    {
        $this->fakeWompi();
        $res = $this->postJson('/api/payments/wompi/nequi', [
            'plan_id' => $this->plan->id, 'phone' => '3215542105',
            'accepted_terms' => true, 'accepted_personal_data' => true,
            'client_request_id' => 'cli-123',
        ], $this->auth());

        $res->assertOk()->assertJsonPath('status', PaymentStateMachine::PENDING)
            ->assertJsonPath('provider', 'wompi');

        $this->assertDatabaseHas('payment_transactions', [
            'provider' => 'wompi', 'member_id' => $this->member->id, 'plan_id' => $this->plan->id,
            'idempotency_key' => 'cli-123',
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/payments/wompi/nequi', [
            'plan_id' => $this->plan->id, 'phone' => '3215542105',
            'accepted_terms' => true, 'accepted_personal_data' => true,
        ])->assertStatus(401);
    }

    public function test_status_ownership_returns_404_for_other_member(): void
    {
        $other = Member::create([
            'full_name' => 'Otro', 'email' => 'otro@example.com', 'document_number' => '999',
            'phone' => '3000000000', 'status' => Member::STATUS_ACTIVE,
        ]);
        $tx = PaymentTransaction::create([
            'uuid' => (string) \Str::uuid(), 'reference' => 'IRON-OTHER-1',
            'idempotency_key' => 'k1', 'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'member_id' => $other->id, 'user_id' => null,
        ]);

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertStatus(404);
    }

    public function test_webhook_valid_signature_processes(): void
    {
        $this->fakeWompi();
        // Crea la transacción primero (vía ruta).
        $this->postJson('/api/payments/wompi/nequi', [
            'plan_id' => $this->plan->id, 'phone' => '3215542105',
            'accepted_terms' => true, 'accepted_personal_data' => true,
        ], $this->auth())->assertOk();

        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();

        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => 'wompi-tx-001', 'status' => 'APPROVED',
                'reference' => $tx->reference, 'amount_in_cents' => 8000000, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp' => 1700000000,
        ];
        $checksum = (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
            ->computeWebhookChecksum($payload, 'test_events_xyz');
        $payload['signature']['checksum'] = strtoupper($checksum);

        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();

        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    public function test_webhook_invalid_signature_401(): void
    {
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => ['id' => 'x', 'status' => 'APPROVED', 'reference' => 'r', 'amount_in_cents' => 1, 'currency' => 'COP']],
            'environment' => 'test',
            'signature' => ['properties' => ['transaction.id'], 'checksum' => 'BADBAD'],
            'timestamp' => 1700000000,
        ];

        $this->postJson('/api/webhooks/wompi', $payload)->assertStatus(401);
    }
}
