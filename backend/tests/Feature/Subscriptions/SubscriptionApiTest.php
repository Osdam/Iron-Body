<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\WompiPaymentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoints REST de pago automático (Bloque 5): miembro (auth.member) y admin
 * (auth.admin). Sin red real (Http::fake). Verifica feature flag, no-cobro en
 * authorize, alta con tarjeta, rechazo de métodos no soportados, respuestas sin
 * secretos, cancelación, listado admin y retry admin idempotente.
 */
class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_SECRET = 'test_admin_secret';

    private Plan $plan;
    private Member $member;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin.api_token', self::ADMIN_SECRET);
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

    private function fakeWompi(string $sourceStatus = 'AVAILABLE', string $chargeStatus = 'APPROVED'): void
    {
        $seq = 0;
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'a_tok', 'permalink' => 'https://w/terms'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'p_tok', 'permalink' => 'https://w/data'],
            ]], 200),
            'sandbox.wompi.co/v1/payment_sources*' => function () use ($sourceStatus, &$seq) {
                $seq++;
                return Http::response(['data' => [
                    'id' => 2000 + $seq, 'status' => $sourceStatus, 'type' => 'CARD',
                    'public_data' => ['brand' => 'VISA', 'last_four' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
                ]], 200);
            },
            'sandbox.wompi.co/v1/transactions*' => fn ($req) => Http::response(['data' => [
                'id' => 'wompi-sub-'.Str::random(4), 'status' => $chargeStatus,
                'reference' => json_decode($req->body(), true)['reference'] ?? 'ref',
                'amount_in_cents' => 8000000, 'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]], 200),
        ]);
    }

    private function memberAuth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function adminAuth(): array
    {
        return ['Authorization' => 'Bearer '.self::ADMIN_SECRET];
    }

    private function validCardPayload(): array
    {
        return [
            'plan_id' => $this->plan->id, 'type' => 'CARD', 'token' => 'tok_test_abc',
            'card_brand' => 'VISA', 'card_last_four' => '4242', 'exp_month' => '12', 'exp_year' => '2030',
            'accepted_terms' => true, 'accepted_personal_data' => true,
        ];
    }

    // ── Feature flag ──────────────────────────────────────────────────────────

    public function test_flag_off_authorize_reports_disabled_and_store_blocked(): void
    {
        config()->set('wompi.recurring.enabled', false);
        Http::fake();

        $this->postJson('/api/memberships/subscriptions/authorize', ['plan_id' => $this->plan->id], $this->memberAuth())
            ->assertOk()->assertJsonPath('recurring_enabled', false);

        $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())
            ->assertStatus(503)->assertJsonPath('error_code', 'recurring_disabled');

        Http::assertNothingSent();
        $this->assertSame(0, MembershipSubscription::count());
    }

    // ── Authorize ─────────────────────────────────────────────────────────────

    public function test_authorize_does_not_charge(): void
    {
        $this->fakeWompi();

        $this->postJson('/api/memberships/subscriptions/authorize', ['plan_id' => $this->plan->id], $this->memberAuth())
            ->assertOk()
            ->assertJsonPath('recurring_enabled', true)
            ->assertJsonPath('plan.id', $this->plan->id)
            ->assertJsonPath('public_key', 'pub_test_x');

        // No se creó suscripción ni se envió cobro.
        $this->assertSame(0, MembershipSubscription::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transactions'));
    }

    // ── Crear ─────────────────────────────────────────────────────────────────

    public function test_create_subscription_with_valid_card(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');

        $resp = $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('subscription.payment_method.last_four', '4242');

        // Respuesta SIN secretos.
        $json = $resp->json();
        $this->assertStringNotContainsString('tok_test', json_encode($json));
        $this->assertArrayNotHasKey('payment_source_id', $json['subscription']);
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
    }

    public function test_create_rejects_unsupported_method(): void
    {
        $this->fakeWompi();

        foreach (['PSE', 'DAVIPLATA', 'BANCOLOMBIA_TRANSFER'] as $type) {
            $this->postJson('/api/memberships/subscriptions', array_merge($this->validCardPayload(), ['type' => $type]), $this->memberAuth())
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'unsupported_autopay_method');
        }

        $this->assertSame(0, PaymentTransaction::where('is_recurring', true)->count());
    }

    public function test_create_requires_consents(): void
    {
        $this->fakeWompi();
        $payload = $this->validCardPayload();
        unset($payload['accepted_terms'], $payload['accepted_personal_data']);

        $this->postJson('/api/memberships/subscriptions', $payload, $this->memberAuth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_terms', 'accepted_personal_data']);
    }

    // ── Current ───────────────────────────────────────────────────────────────

    public function test_current_returns_state_without_secrets(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');
        $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())->assertOk();

        $resp = $this->getJson('/api/memberships/subscriptions/current', $this->memberAuth())
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.plan.name', 'Mensual');

        $json = $resp->json('data');
        $this->assertArrayHasKey('payment_method', $json);
        $this->assertArrayNotHasKey('payment_source_id', $json);
        $this->assertStringNotContainsString('prv_test', json_encode($json));
        $this->assertStringNotContainsString('integrity', json_encode($json));
    }

    // ── Cancel (miembro) ──────────────────────────────────────────────────────

    public function test_member_cancel_stops_renewal_keeps_history(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');
        $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())->assertOk();
        $sub = MembershipSubscription::firstOrFail();

        $this->postJson("/api/memberships/subscriptions/{$sub->id}/cancel", ['reason' => 'ya no lo uso'], $this->memberAuth())
            ->assertOk()->assertJsonPath('ok', true);

        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_CANCELLED, $sub->status);
        $this->assertNull($sub->next_charge_at);
        // Histórico conservado: pago + evento de cancelación.
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
        $this->assertDatabaseHas('subscription_events', ['subscription_id' => $sub->id, 'type' => 'cancelled', 'actor' => 'member']);
        // La membresía sigue vigente (no se cortó).
        $this->user->refresh();
        $this->assertNotNull($this->user->membership_end_date);
    }

    public function test_member_cannot_cancel_other_members_subscription(): void
    {
        $other = Member::create([
            'full_name' => 'Ana', 'email' => 'ana@example.com', 'document_number' => '999',
            'phone' => '3000000000', 'status' => Member::STATUS_ACTIVE,
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $other->id, 'plan_id' => $this->plan->id,
            'status' => 'active', 'price_snapshot' => 80000, 'currency' => 'COP', 'interval_days' => 30,
        ]);

        $this->postJson("/api/memberships/subscriptions/{$sub->id}/cancel", [], $this->memberAuth())
            ->assertStatus(404);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function test_admin_lists_subscriptions(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');
        $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())->assertOk();

        $this->getJson('/api/admin/subscriptions?status=active', $this->adminAuth())
            ->assertOk()
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('meta.total', 1);

        // Sin token admin → 401.
        $this->getJson('/api/admin/subscriptions')->assertStatus(401);
    }

    public function test_admin_retry_is_idempotent_no_double_charge(): void
    {
        // Suscripción past_due (fuente disponible) para reintentar.
        $source = WompiPaymentSource::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'provider' => 'wompi', 'wompi_payment_source_id' => 'src_x', 'type' => 'CARD',
            'status' => WompiPaymentSource::STATUS_AVAILABLE, 'card_brand' => 'VISA', 'card_last_four' => '4242',
            'customer_email' => 'oscar@example.com', 'environment' => 'sandbox',
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'plan_id' => $this->plan->id, 'payment_source_id' => $source->id,
            'status' => MembershipSubscription::STATUS_PAST_DUE, 'price_snapshot' => 80000, 'currency' => 'COP',
            'interval_days' => 30, 'method' => 'card', 'failed_attempts' => 3, 'next_charge_at' => null,
            'current_period_end' => now()->toDateString(),
        ]);
        $this->user->forceFill(['membership_end_date' => now()->toDateString(), 'membership_start_date' => now()->subDays(30)->toDateString(), 'status' => 'active'])->save();

        $this->fakeWompi(chargeStatus: 'APPROVED');

        $this->postJson("/api/admin/subscriptions/{$sub->id}/retry", [], $this->adminAuth())
            ->assertOk()->assertJsonPath('result', 'approved');

        // Un solo cobro nuevo para este intento; no se duplica.
        $this->assertSame(1, PaymentTransaction::where('subscription_id', $sub->id)->count());
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
    }

    public function test_admin_shows_detail_with_charges_and_events(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');
        $this->postJson('/api/memberships/subscriptions', $this->validCardPayload(), $this->memberAuth())->assertOk();
        $sub = MembershipSubscription::firstOrFail();

        $this->getJson("/api/admin/subscriptions/{$sub->id}", $this->adminAuth())
            ->assertOk()
            ->assertJsonPath('subscription.id', $sub->id)
            ->assertJsonPath('member.full_name', 'Oscar')
            ->assertJsonCount(1, 'charges');
    }

    // ── Reemplazo de fuente de pago ───────────────────────────────────────────

    /** Suscripción past_due con una fuente previa disponible. */
    private function pastDueSub(): array
    {
        $old = WompiPaymentSource::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'provider' => 'wompi', 'wompi_payment_source_id' => 'src_old', 'type' => 'CARD',
            'status' => WompiPaymentSource::STATUS_AVAILABLE, 'card_brand' => 'VISA', 'card_last_four' => '1111',
            'customer_email' => 'oscar@example.com', 'environment' => 'sandbox',
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'plan_id' => $this->plan->id, 'payment_source_id' => $old->id,
            'status' => MembershipSubscription::STATUS_PAST_DUE, 'price_snapshot' => 80000, 'currency' => 'COP',
            'interval_days' => 30, 'method' => 'card', 'failed_attempts' => 3, 'next_charge_at' => null,
            'current_period_end' => now()->toDateString(),
        ]);
        $this->user->forceFill([
            'membership_end_date' => now()->toDateString(),
            'membership_start_date' => now()->subDays(30)->toDateString(), 'status' => 'active',
        ])->save();
        return [$sub, $old];
    }

    private function replacePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'CARD', 'token' => 'tok_test_new',
            'card_brand' => 'MASTERCARD', 'card_last_four' => '5555',
            'exp_month' => '10', 'exp_year' => '2031',
            'accepted_terms' => true, 'accepted_personal_data' => true,
        ], $overrides);
    }

    public function test_member_replaces_payment_source_and_rearms_past_due(): void
    {
        [$sub, $old] = $this->pastDueSub();
        $this->fakeWompi(chargeStatus: 'APPROVED');

        $resp = $this->postJson("/api/memberships/subscriptions/{$sub->id}/payment-source",
            $this->replacePayload(), $this->memberAuth())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('retry_result', 'approved');

        $sub->refresh();
        $old->refresh();
        // La suscripción quedó al día con la nueva fuente.
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNotSame($old->id, $sub->payment_source_id);
        // La fuente anterior se REVOCÓ (histórico conservado, no borrado).
        $this->assertSame(WompiPaymentSource::STATUS_REVOKED, $old->status);
        $this->assertSame(2, WompiPaymentSource::count());
        // Membresía extendida por el retry controlado.
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
        // Sin secretos en la respuesta.
        $json = $resp->json();
        $this->assertStringNotContainsString('tok_test', json_encode($json));
        $this->assertArrayNotHasKey('payment_source_id', $json['subscription']);
    }

    public function test_replace_rejects_unsupported_method(): void
    {
        [$sub, ] = $this->pastDueSub();
        $this->fakeWompi();

        $this->postJson("/api/memberships/subscriptions/{$sub->id}/payment-source",
            $this->replacePayload(['type' => 'PSE']), $this->memberAuth())
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'unsupported_autopay_method');

        // No se cobró ni cambió la fuente.
        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_PAST_DUE, $sub->status);
    }

    public function test_replace_without_ownership_is_404(): void
    {
        $other = Member::create([
            'full_name' => 'Ana', 'email' => 'ana@example.com', 'document_number' => '888',
            'phone' => '3001112222', 'status' => Member::STATUS_ACTIVE,
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $other->id, 'plan_id' => $this->plan->id,
            'status' => 'past_due', 'price_snapshot' => 80000, 'currency' => 'COP', 'interval_days' => 30,
        ]);
        $this->fakeWompi();

        $this->postJson("/api/memberships/subscriptions/{$sub->id}/payment-source",
            $this->replacePayload(), $this->memberAuth())
            ->assertStatus(404);
    }

    public function test_replace_blocked_when_flag_off(): void
    {
        [$sub, ] = $this->pastDueSub();
        config()->set('wompi.recurring.enabled', false);
        Http::fake();

        $this->postJson("/api/memberships/subscriptions/{$sub->id}/payment-source",
            $this->replacePayload(), $this->memberAuth())
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'recurring_disabled');

        Http::assertNothingSent();
    }
}
