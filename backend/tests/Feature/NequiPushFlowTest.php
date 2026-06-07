<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nequi DIRECTO (Pagos con notificación Push). Cubre: disabled→unavailable,
 * teléfono inválido, push pending no activa, webhook approved activa una sola
 * vez (idempotente), rechazado/expirado no activan, monto autoritativo y
 * can_access_home gobernado SOLO por membresía activa.
 */
class NequiPushFlowTest extends TestCase
{
    use RefreshDatabase;

    private function enableNequi(): void
    {
        config([
            'services.payments.nequi_provider' => 'direct',
            'services.nequi.enabled'        => true,
            'services.nequi.env'            => 'sandbox',
            'services.nequi.auth_url'       => 'https://nequi.test/oauth/token',
            'services.nequi.base_url'       => 'https://nequi.test',
            'services.nequi.client_id'      => 'cid',
            'services.nequi.client_secret'  => 'csecret',
            'services.nequi.webhook_secret' => null, // sandbox: webhook sin firma
            'services.nequi.ttl_minutes'    => 15,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
        ]);
    }

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba', 'email' => 'ana@example.com', 'password' => 'secret',
            'document' => '1010101010', 'phone' => '3001234567', 'status' => 'pending',
        ]);
        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba', 'email' => 'ana@example.com',
            'document_number' => '1010101010', 'phone' => '3001234567',
            'access_hash' => 'tok-' . uniqid(),
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function pendingTx(Member $m, Plan $p): PaymentTransaction
    {
        return PaymentTransaction::create([
            'reference' => 'NEQUI-TEST-' . uniqid(),
            'idempotency_key' => 'idem-' . uniqid(),
            'member_id' => $m->id, 'user_id' => $m->user_id, 'plan_id' => $p->id,
            'amount' => 80000, 'currency' => 'COP',
            'status' => PaymentTransaction::STATUS_PENDING,
            'provider' => 'nequi', 'method' => 'nequi_push',
            'provider_ref' => 'NQ-REF-1',
            'raw_response' => ['flow' => 'nequi_push'],
        ]);
    }

    public function test_nequi_disabled_returns_unavailable(): void
    {
        // Default: PAYMENT_NEQUI_PROVIDER=disabled → unavailable, sin crear tx.
        $member = $this->member();
        $plan = $this->plan();

        $res = $this->postJson('/api/payments/nequi/push', [
            'plan_id' => $plan->id, 'phone' => '3001234567',
        ], $this->auth($member));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('provider', 'nequi')
            ->assertJsonPath('method', 'nequi_push');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_nequi_invalid_phone_rejected(): void
    {
        $this->enableNequi();
        $member = $this->member();
        $plan = $this->plan();

        // 10 dígitos pero no celular Nequi (no empieza por 3) → rechazado.
        $res = $this->postJson('/api/payments/nequi/push', [
            'plan_id' => $plan->id, 'phone' => '1234567890',
        ], $this->auth($member));

        $res->assertOk()->assertJsonPath('ok', false)->assertJsonPath('status', 'failed');
        $this->assertFalse(app(MembershipService::class)->isActive($member->user));
    }

    public function test_nequi_push_pending_does_not_activate_membership(): void
    {
        $this->enableNequi();
        Http::fake([
            'nequi.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'nequi.test/*' => Http::response(['transaction_id' => 'NQ-1', 'status' => 'pending']),
        ]);
        $member = $this->member();
        $plan = $this->plan();

        $res = $this->postJson('/api/payments/nequi/push', [
            'plan_id' => $plan->id, 'phone' => '3001234567',
        ], $this->auth($member));

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('can_access_home', false);
        $this->assertSame('pending', PaymentTransaction::first()->status);
        $this->assertSame(0, Payment::count());
        $this->assertFalse(app(MembershipService::class)->isActive($member->user->fresh()));
    }

    public function test_manipulated_amount_ignored_uses_plan_price(): void
    {
        $this->enableNequi();
        Http::fake([
            'nequi.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'nequi.test/*' => Http::response(['transaction_id' => 'NQ-1', 'status' => 'pending']),
        ]);
        $member = $this->member();
        $plan = $this->plan();

        // 'amount' manipulado NO existe en el contrato; el monto sale del plan.
        $this->postJson('/api/payments/nequi/push', [
            'plan_id' => $plan->id, 'phone' => '3001234567', 'amount' => 1000,
        ], $this->auth($member))->assertOk();

        $this->assertEquals(80000, (float) PaymentTransaction::first()->amount);
    }

    public function test_nequi_approved_webhook_activates_membership_once(): void
    {
        $this->enableNequi();
        $member = $this->member();
        $plan = $this->plan();
        $tx = $this->pendingTx($member, $plan);

        $payload = ['reference' => $tx->reference, 'status' => 'approved', 'amount' => 80000, 'transaction_id' => 'NQ-REF-1'];

        $this->postJson('/api/payments/nequi/confirmation', $payload)->assertOk();
        $this->assertSame('approved', $tx->fresh()->status);
        $this->assertTrue(app(MembershipService::class)->isActive($member->user->fresh()));
        $this->assertSame(1, Payment::count());

        // Webhook duplicado → idempotente (no duplica Payment ni reactiva).
        $this->postJson('/api/payments/nequi/confirmation', $payload)->assertOk();
        $this->assertSame(1, Payment::count());
    }

    public function test_nequi_rejected_webhook_does_not_activate(): void
    {
        $this->enableNequi();
        $member = $this->member();
        $tx = $this->pendingTx($member, $this->plan());

        $this->postJson('/api/payments/nequi/confirmation', [
            'reference' => $tx->reference, 'status' => 'rejected',
        ])->assertOk();

        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertFalse(app(MembershipService::class)->isActive($member->user->fresh()));
        $this->assertSame(0, Payment::count());
    }

    public function test_nequi_expired_webhook_does_not_activate(): void
    {
        $this->enableNequi();
        $member = $this->member();
        $tx = $this->pendingTx($member, $this->plan());

        $this->postJson('/api/payments/nequi/confirmation', [
            'reference' => $tx->reference, 'status' => 'expired',
        ])->assertOk();

        $this->assertSame('expired', $tx->fresh()->status);
        $this->assertFalse(app(MembershipService::class)->isActive($member->user->fresh()));
    }

    public function test_status_pending_can_access_home_false(): void
    {
        $this->enableNequi();
        Http::fake(['nequi.test/*' => Http::response(['status' => 'pending'])]);
        $member = $this->member();
        $tx = $this->pendingTx($member, $this->plan());

        $this->getJson("/api/payments/nequi/{$tx->reference}/status", $this->auth($member))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('can_access_home', false);
    }

    public function test_status_approved_can_access_home_true_after_membership_active(): void
    {
        $this->enableNequi();
        $member = $this->member();
        $tx = $this->pendingTx($member, $this->plan());
        // Aprobado por webhook → membresía activa.
        $this->postJson('/api/payments/nequi/confirmation', [
            'reference' => $tx->reference, 'status' => 'approved', 'amount' => 80000,
        ])->assertOk();

        $this->getJson("/api/payments/nequi/{$tx->reference}/status", $this->auth($member))
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('membership_active', true)
            ->assertJsonPath('can_access_home', true);
    }
}
