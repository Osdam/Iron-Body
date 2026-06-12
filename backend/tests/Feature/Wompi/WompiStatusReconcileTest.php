<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sincronización de estados: el endpoint /status reconcilia contra Wompi cuando
 * el pago sigue en vuelo; no consulta si ya es final; un fallo temporal de Wompi
 * conserva el estado y responde 200; un pago aprobado por Wompi no se expira por
 * antigüedad; la activación de membresía ocurre una sola vez; y el comando de
 * reconciliación corre sin error de dependencia.
 */
class WompiStatusReconcileTest extends TestCase
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

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function tx(array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'uuid' => (string) Str::uuid(), 'reference' => 'IRON-'.Str::random(6),
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'method' => 'card', 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'wompi_transaction_id' => 'wtx_'.Str::random(6),
        ], $overrides));
    }

    private function fakeGet(string $status): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wtx_remote', 'status' => $status, 'amount_in_cents' => 8000000,
                'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]], 200),
        ]);
    }

    public function test_status_pending_reconciles_and_becomes_approved(): void
    {
        $this->fakeGet('APPROVED');
        $tx = $this->tx();

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::APPROVED)
            ->assertJsonPath('is_final', true);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertNotNull($tx->last_reconciled_at);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    public function test_status_does_not_query_wompi_when_final(): void
    {
        Http::fake(); // si se llamara a Wompi, lo detectaríamos abajo
        $tx = $this->tx(['status' => PaymentStateMachine::APPROVED, 'approved_at' => now(), 'paid_at' => now()]);

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::APPROVED);

        Http::assertNothingSent();
    }

    public function test_status_keeps_pending_and_returns_200_on_wompi_error(): void
    {
        // Error temporal de Wompi (HTTP 500).
        Http::fake(['sandbox.wompi.co/v1/transactions*' => Http::response([], 500)]);
        $tx = $this->tx();

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::PENDING);

        $this->assertSame(PaymentStateMachine::PENDING, $tx->fresh()->status);
    }

    public function test_reconcile_command_runs_without_dependency_error(): void
    {
        Http::fake(['sandbox.wompi.co/v1/transactions*' => Http::response(['data' => []], 200)]);
        $this->tx(); // un candidato pendiente

        $this->artisan('payments:wompi-reconcile', ['--limit' => 5])
            ->assertExitCode(0);
    }

    public function test_old_pending_approved_in_wompi_is_not_expired(): void
    {
        $this->fakeGet('APPROVED');
        // Pago ANTIGUO y con muchos reintentos, pero Wompi lo aprobó.
        $tx = $this->tx([
            'created_at' => now()->subHours(3),
            'retry_count' => 100,
        ]);

        $result = WompiReconciliationService::make()->reconcileOne($tx->fresh());

        $this->assertSame('updated', $result);
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
    }

    public function test_old_pending_still_pending_in_wompi_expires(): void
    {
        $this->fakeGet('PENDING');
        $tx = $this->tx(['created_at' => now()->subHours(3), 'retry_count' => 100]);

        $result = WompiReconciliationService::make()->reconcileOne($tx->fresh());

        $this->assertSame('expired', $result);
        $this->assertSame(PaymentStateMachine::EXPIRED, $tx->fresh()->status);
    }

    public function test_membership_activation_happens_once(): void
    {
        $this->fakeGet('APPROVED');
        $tx = $this->tx();

        // Dos pasadas de reconciliación (p. ej. /status + job).
        WompiReconciliationService::make()->reconcileOne($tx->fresh());
        WompiReconciliationService::make()->reconcileOne($tx->fresh());

        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }
}
