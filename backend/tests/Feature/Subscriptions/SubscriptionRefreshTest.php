<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\WompiPaymentSource;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Refresh seguro de suscripción: GET /current?refresh=1 reconcilia el cobro en
 * vuelo sin saturar Wompi (lock + throttle) y de forma idempotente. Sin red real.
 */
class SubscriptionRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private Member $member;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x', 'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz', 'events_secret' => 'test_events_xyz',
            'currency' => 'COP',
            'recurring' => [
                'enabled' => true, 'sandbox' => true, '3ds_enabled' => false, '3ri_enabled' => false,
                'max_retries' => 3, 'grace_days' => 0, 'retry_days' => [1, 3],
                'methods' => ['card' => true, 'nequi' => false],
            ],
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

    /** Suscripción pending_first_payment con un cobro EN VUELO (pending + wompi id). */
    private function pendingWithInFlightCharge(): MembershipSubscription
    {
        $source = WompiPaymentSource::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'provider' => 'wompi', 'wompi_payment_source_id' => 'src_1', 'type' => 'CARD',
            'status' => WompiPaymentSource::STATUS_AVAILABLE, 'card_brand' => 'VISA', 'card_last_four' => '4242',
            'customer_email' => 'oscar@example.com', 'environment' => 'sandbox',
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'plan_id' => $this->plan->id, 'payment_source_id' => $source->id,
            'status' => MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT, 'price_snapshot' => 80000,
            'currency' => 'COP', 'interval_days' => 30, 'method' => 'card',
        ]);
        PaymentTransaction::create([
            'uuid' => (string) Str::uuid(), 'reference' => 'IRON-SUB-REF1', 'idempotency_key' => (string) Str::uuid(),
            'member_id' => $this->member->id, 'user_id' => $this->user->id, 'plan_id' => $this->plan->id,
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'provider' => 'wompi', 'method' => 'card', 'wompi_transaction_id' => 'wompi-tx-inflight',
            'subscription_id' => $sub->id, 'billing_period' => 'p1', 'is_recurring' => true,
            'wompi_payment_source_id' => 'src_1',
        ]);
        return $sub;
    }

    private function fakeGetApproved(): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/transactions/*' => Http::response(['data' => [
                'id' => 'wompi-tx-inflight', 'status' => 'APPROVED', 'reference' => 'IRON-SUB-REF1',
                'amount_in_cents' => 8000000, 'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]], 200),
        ]);
    }

    private function wompiGetCount(): int
    {
        $n = 0;
        foreach (Http::recorded() as [$req]) {
            if ($req->method() === 'GET' && str_contains($req->url(), '/transactions/')) $n++;
        }
        return $n;
    }

    public function test_refresh_reconciles_pending_charge_to_active(): void
    {
        $sub = $this->pendingWithInFlightCharge();
        $this->fakeGetApproved();

        $this->getJson('/api/memberships/subscriptions/current?refresh=1', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        // Membresía extendida una sola vez por el activador compartido.
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
        $this->user->refresh();
        $this->assertNotNull($this->user->membership_end_date);
    }

    public function test_current_without_refresh_does_not_touch_wompi(): void
    {
        $this->pendingWithInFlightCharge();
        Http::fake();

        $this->getJson('/api/memberships/subscriptions/current', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_first_payment');

        Http::assertNothingSent();
    }

    public function test_refresh_does_not_call_wompi_when_no_inflight_charge(): void
    {
        // Suscripción activa, sin cobro en vuelo → refresh es no-op (sin Wompi).
        MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'plan_id' => $this->plan->id, 'status' => MembershipSubscription::STATUS_ACTIVE,
            'price_snapshot' => 80000, 'currency' => 'COP', 'interval_days' => 30,
            'next_charge_at' => now()->addDays(30),
        ]);
        Http::fake();

        $this->getJson('/api/memberships/subscriptions/current?refresh=1', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        Http::assertNothingSent();
    }

    public function test_refresh_is_throttled_and_does_not_double_activate(): void
    {
        $this->pendingWithInFlightCharge();
        $this->fakeGetApproved();

        // Dos refresh seguidos: el throttle evita una segunda consulta a Wompi.
        $this->getJson('/api/memberships/subscriptions/current?refresh=1', $this->auth())->assertOk();
        $this->getJson('/api/memberships/subscriptions/current?refresh=1', $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'active');

        // Solo UNA consulta GET a Wompi (throttle) y UNA activación (idempotencia).
        $this->assertSame(1, $this->wompiGetCount());
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
    }
}
