<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Models\WompiPaymentSource;
use App\Services\Subscriptions\RecurringBillingService;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use App\Services\Wompi\WompiWebhookService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobro recurrente automático (Bloque 4): scheduler, reintentos, past_due y
 * cierre idempotente por webhook/reconciliación. Sin red real (Http::fake).
 */
class RecurringBillingTest extends TestCase
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
            'currency'         => 'COP',
            'recurring'        => [
                'enabled'     => true,
                'sandbox'     => true,
                '3ds_enabled' => false,
                '3ri_enabled' => false,
                'max_retries' => 3,
                'grace_days'  => 0,
                'retry_days'  => [1, 3],
                'methods'     => ['card' => true, 'nequi' => false],
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

    private function fakeCharge(string $status = 'APPROVED'): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/transactions*' => function ($request) use ($status) {
                return Http::response(['data' => [
                    'id'              => 'wompi-sub-tx-'.Str::random(4),
                    'status'          => $status,
                    'reference'       => json_decode($request->body(), true)['reference'] ?? 'ref',
                    'amount_in_cents' => 8000000,
                    'currency'        => 'COP',
                    'payment_method'  => ['type' => 'CARD'],
                ]], 200);
            },
        ]);
    }

    /** Suscripción ACTIVA con fuente disponible y vencida (next_charge_at en el pasado). */
    private function makeActiveSub(array $subOverrides = []): array
    {
        $source = WompiPaymentSource::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'provider' => 'wompi', 'wompi_payment_source_id' => 'src_test_'.Str::random(5),
            'type' => 'CARD', 'status' => WompiPaymentSource::STATUS_AVAILABLE,
            'card_brand' => 'VISA', 'card_last_four' => '4242',
            'customer_email' => 'oscar@example.com', 'environment' => 'sandbox',
        ]);

        // Membresía vigente que vence HOY.
        $this->user->forceFill([
            'membership_start_date' => Carbon::today()->subDays(30)->toDateString(),
            'membership_end_date'   => Carbon::today()->toDateString(),
            'plan' => $this->plan->name, 'status' => 'active',
        ])->save();

        $sub = MembershipSubscription::create(array_merge([
            'uuid' => (string) Str::uuid(), 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'plan_id' => $this->plan->id, 'payment_source_id' => $source->id,
            'status' => MembershipSubscription::STATUS_ACTIVE, 'price_snapshot' => 80000,
            'currency' => 'COP', 'interval_days' => 30, 'method' => 'card',
            'next_charge_at' => Carbon::now()->subMinute(),
            'current_period_start' => Carbon::today()->subDays(30)->toDateString(),
            'current_period_end' => Carbon::today()->toDateString(),
            'last_charged_at' => Carbon::now()->subDays(30), 'last_charge_reference' => 'IRON-SUB-PREV',
        ], $subOverrides));

        return [$sub, $source];
    }

    private function approvedWebhook(string $reference, int $cents = 8000000, string $wompiId = 'wompi-sub-tx-1'): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data'  => ['transaction' => [
                'id' => $wompiId, 'status' => 'APPROVED', 'reference' => $reference,
                'amount_in_cents' => $cents, 'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]],
            'environment' => 'test',
            'signature'   => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp'   => 1700000000,
        ];
        $checksum = (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
            ->computeWebhookChecksum($payload, 'test_events_xyz');
        $payload['signature']['checksum'] = strtoupper($checksum);
        return $payload;
    }

    private function countChargePosts(): int
    {
        $n = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/transactions')) {
                $n++;
            }
        }
        return $n;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_recurring_approved_extends_membership_and_recalcs_next_charge(): void
    {
        $this->fakeCharge('APPROVED');
        [$sub] = $this->makeActiveSub();

        $stats = RecurringBillingService::make()->chargeDue();

        $this->assertSame(1, $stats['approved']);
        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        // Membresía extendida 30 días (una sola vez).
        $this->user->refresh();
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), Carbon::parse($this->user->membership_end_date)->toDateString());
        // next_charge_at recalculado al nuevo vencimiento (futuro).
        $this->assertTrue($sub->next_charge_at->gt(now()));
        $this->assertSame(0, (int) $sub->failed_attempts);
        $this->assertSame(1, PaymentTransaction::where('is_recurring', true)->where('status', 'approved')->count());
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
    }

    public function test_recurring_pending_does_not_extend_membership(): void
    {
        $this->fakeCharge('PENDING');
        [$sub] = $this->makeActiveSub();
        $endBefore = $this->user->fresh()->membership_end_date;

        $stats = RecurringBillingService::make()->chargeDue();

        $this->assertSame(1, $stats['pending']);
        $this->assertSame(0, Payment::where('user_id', $this->user->id)->count());
        $this->assertSame($endBefore, $this->user->fresh()->membership_end_date);
        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
    }

    public function test_webhook_pending_to_approved_activates_once_and_dedup(): void
    {
        $this->fakeCharge('PENDING');
        [$sub] = $this->makeActiveSub();
        RecurringBillingService::make()->chargeDue();

        $charge = PaymentTransaction::where('is_recurring', true)->firstOrFail();
        $this->assertSame(PaymentStateMachine::PENDING, $charge->status);

        // Wompi confirma por webhook.
        $payload = $this->approvedWebhook($charge->reference, 8000000, (string) $charge->wompi_transaction_id);
        $raw = json_encode($payload);
        $r1 = WompiWebhookService::make()->handle($payload, $raw);
        $this->assertSame('processed', $r1['status']);

        $sub->refresh();
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->next_charge_at->gt(now()));
        $this->assertSame(1, Payment::where('reference', $charge->reference)->count());

        // Webhook DUPLICADO → idempotente, no duplica membresía ni cierre.
        $r2 = WompiWebhookService::make()->handle($payload, $raw);
        $this->assertSame('duplicate', $r2['status']);
        $this->assertSame(1, Payment::where('reference', $charge->reference)->count());
        $this->assertSame(1, SubscriptionEvent::where('type', 'charge_approved')->count());
    }

    public function test_scheduler_run_twice_does_not_charge_twice(): void
    {
        $this->fakeCharge('APPROVED');
        $this->makeActiveSub();

        $svc = RecurringBillingService::make();
        $svc->chargeDue();
        $svc->chargeDue();

        $this->assertSame(1, PaymentTransaction::where('is_recurring', true)->count());
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
        $this->assertSame(1, $this->countChargePosts());
    }

    public function test_retry_schedule_plus_one_and_plus_three_then_past_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 06:00:00'));
        $this->fakeCharge('DECLINED');
        [$sub] = $this->makeActiveSub();
        $svc = RecurringBillingService::make();

        // 1er fallo → reintento a +1 día.
        $svc->chargeDue();
        $sub->refresh();
        $this->assertSame(1, (int) $sub->failed_attempts);
        $this->assertSame(1, (int) $sub->retry_stage);
        $this->assertSame(Carbon::parse('2026-07-13 06:00:00')->toDateTimeString(), $sub->next_charge_at->toDateTimeString());
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);

        // 2º fallo (al día siguiente) → reintento a +3 días.
        Carbon::setTestNow(Carbon::parse('2026-07-13 06:00:00'));
        $svc->chargeDue();
        $sub->refresh();
        $this->assertSame(2, (int) $sub->failed_attempts);
        $this->assertSame(Carbon::parse('2026-07-16 06:00:00')->toDateTimeString(), $sub->next_charge_at->toDateTimeString());

        // 3er fallo → se agotan reintentos → past_due.
        Carbon::setTestNow(Carbon::parse('2026-07-16 06:00:00'));
        $result = $svc->chargeDue();
        $sub->refresh();
        $this->assertSame(1, $result['past_due']);
        $this->assertSame(MembershipSubscription::STATUS_PAST_DUE, $sub->status);
        $this->assertNull($sub->next_charge_at);
        // Se registraron 3 cobros distintos (uno por intento), ninguno aprobado.
        $this->assertSame(3, PaymentTransaction::where('subscription_id', $sub->id)->count());
        $this->assertSame(0, Payment::where('user_id', $this->user->id)->count());

        Carbon::setTestNow();
    }

    public function test_disabled_flag_does_not_touch_wompi(): void
    {
        config()->set('wompi.recurring.enabled', false);
        $this->makeActiveSub();
        Http::fake();

        $stats = RecurringBillingService::make()->chargeDue();

        Http::assertNothingSent();
        $this->assertSame(0, $stats['selected']);
        $this->assertSame(0, PaymentTransaction::where('is_recurring', true)->count());
    }

    // ── Cierre asíncrono de cobros recurrentes PENDING que luego fallan ────────

    private function failWebhook(string $reference, string $wompiId, string $status = 'DECLINED', int $cents = 8000000): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data'  => ['transaction' => [
                'id' => $wompiId, 'status' => $status, 'reference' => $reference,
                'amount_in_cents' => $cents, 'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]],
            'environment' => 'test',
            'signature'   => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp'   => 1700000000,
        ];
        $checksum = (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
            ->computeWebhookChecksum($payload, 'test_events_xyz');
        $payload['signature']['checksum'] = strtoupper($checksum);
        return $payload;
    }

    /** Deja un cobro recurrente en PENDING vía el scheduler y lo devuelve. */
    private function pendingRecurringCharge(): PaymentTransaction
    {
        $this->fakeCharge('PENDING');
        $this->makeActiveSub();
        RecurringBillingService::make()->chargeDue();
        return PaymentTransaction::where('is_recurring', true)->firstOrFail();
    }

    public function test_recurring_pending_then_declined_via_webhook_schedules_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 06:00:00'));
        $charge = $this->pendingRecurringCharge();

        $payload = $this->failWebhook($charge->reference, (string) $charge->wompi_transaction_id, 'DECLINED');
        WompiWebhookService::make()->handle($payload, json_encode($payload));

        $sub = MembershipSubscription::firstOrFail();
        $this->assertSame(1, (int) $sub->failed_attempts);
        $this->assertSame(Carbon::parse('2026-07-13 06:00:00')->toDateTimeString(), $sub->next_charge_at->toDateTimeString());
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame(0, Payment::where('reference', $charge->reference)->count());
        Carbon::setTestNow();
    }

    public function test_recurring_pending_then_error_schedules_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 06:00:00'));
        $charge = $this->pendingRecurringCharge();

        // Simula que la reconciliación/flujo interno lo lleva a ERROR.
        WompiTransactionService::make()->transitionTo($charge->fresh(), PaymentStateMachine::ERROR);

        $sub = MembershipSubscription::firstOrFail();
        $this->assertSame(1, (int) $sub->failed_attempts);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        Carbon::setTestNow();
    }

    public function test_recurring_pending_then_expired_via_reconciliation_schedules_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 06:00:00'));
        $charge = $this->pendingRecurringCharge();

        // La reconciliación marca EXPIRED cuando Wompi no confirma a tiempo.
        WompiTransactionService::make()->transitionTo($charge->fresh(), PaymentStateMachine::EXPIRED);

        $sub = MembershipSubscription::firstOrFail();
        $this->assertSame(1, (int) $sub->failed_attempts);
        $this->assertSame(Carbon::parse('2026-07-13 06:00:00')->toDateTimeString(), $sub->next_charge_at->toDateTimeString());
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        Carbon::setTestNow();
    }

    public function test_duplicate_failure_webhook_does_not_duplicate_events(): void
    {
        $charge = $this->pendingRecurringCharge();
        $payload = $this->failWebhook($charge->reference, (string) $charge->wompi_transaction_id, 'DECLINED');
        $raw = json_encode($payload);

        WompiWebhookService::make()->handle($payload, $raw);
        $r2 = WompiWebhookService::make()->handle($payload, $raw);

        $this->assertSame('duplicate', $r2['status']);
        // Un solo retry_scheduled y un solo incremento de intento.
        $this->assertSame(1, SubscriptionEvent::where('type', 'retry_scheduled')->count());
        $this->assertSame(1, (int) MembershipSubscription::firstOrFail()->failed_attempts);
    }

    public function test_single_payment_flow_is_untouched_by_recurring_hook(): void
    {
        // Una transacción NO recurrente (subscription_id null) que pasa a approved
        // no debe crear eventos de suscripción ni fallar.
        $tx = PaymentTransaction::create([
            'uuid' => (string) Str::uuid(), 'reference' => 'IRON-SINGLE-1', 'idempotency_key' => (string) Str::uuid(),
            'user_id' => $this->user->id, 'member_id' => $this->member->id, 'plan_id' => $this->plan->id,
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'provider' => 'wompi', 'method' => 'card',
        ]);

        WompiTransactionService::make()->transitionTo($tx, PaymentStateMachine::APPROVED);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(0, SubscriptionEvent::count());
        $this->assertSame(0, MembershipSubscription::count());
        // El pago único sí extiende membresía por el activador (comportamiento intacto).
        $this->assertSame(1, Payment::where('reference', 'IRON-SINGLE-1')->count());
    }
}
