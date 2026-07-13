<?php

namespace Tests\Feature\Subscriptions;

use App\Exceptions\SubscriptionException;
use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\WompiPaymentSource;
use App\Services\Subscriptions\MembershipSubscriptionService;
use App\Services\Subscriptions\WompiPaymentSourceService;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pago automático (Bloque 3): fuente de pago + primer cobro. Sin red real
 * (Http::fake). Verifica lo crítico: solo tarjeta admitida, precio autoritativo
 * congelado, activación ÚNICA por APPROVED, PENDING no activa, idempotencia
 * (una viva por miembro + no doble cobro por periodo) y que con el flag apagado
 * NO se toca Wompi ni se cobra.
 */
class MembershipSubscriptionServiceTest extends TestCase
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
            'methods'          => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
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

    /**
     * Http::fake: merchant (aceptación) + payment_sources + transactions.
     *
     * @param  string  $sourceStatus  estado devuelto por POST /payment_sources.
     * @param  string  $chargeStatus  estado devuelto por POST /transactions.
     */
    private function fakeWompi(string $sourceStatus = 'AVAILABLE', string $chargeStatus = 'APPROVED'): void
    {
        $sourceSeq = 0; // Wompi entrega un id distinto por cada fuente creada.
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance'         => ['acceptance_token' => 'accept_tok_123', 'permalink' => 'https://wompi.co/terminos'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'personal_tok_456', 'permalink' => 'https://wompi.co/datos'],
            ]], 200),
            'sandbox.wompi.co/v1/payment_sources*' => function () use ($sourceStatus, &$sourceSeq) {
                $sourceSeq++;
                return Http::response(['data' => [
                    'id'          => 1000 + $sourceSeq,
                    'status'      => $sourceStatus,
                    'type'        => 'CARD',
                    'public_data' => ['brand' => 'VISA', 'last_four' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
                ]], 200);
            },
            'sandbox.wompi.co/v1/transactions*' => function ($request) use ($chargeStatus) {
                return Http::response(['data' => [
                    'id'              => 'wompi-sub-tx-1',
                    'status'          => $chargeStatus,
                    'reference'       => json_decode($request->body(), true)['reference'] ?? 'ref',
                    'amount_in_cents' => 8000000,
                    'currency'        => 'COP',
                    'payment_method'  => ['type' => 'CARD'],
                ]], 200);
            },
        ]);
    }

    private function cardData(array $overrides = []): array
    {
        return array_merge([
            'member_id'      => $this->member->id,
            'user_id'        => $this->user->id,
            'plan_id'        => $this->plan->id,
            'type'           => 'CARD',
            'token'          => 'tok_test_abc123',
            'customer_email' => 'oscar@example.com',
            'card_brand'     => 'VISA',
            'card_last_four' => '4242',
            'exp_month'      => '12',
            'exp_year'       => '2030',
        ], $overrides);
    }

    // ── 1) Fuente de pago ─────────────────────────────────────────────────────

    public function test_creates_payment_source_available_with_safe_refs_only(): void
    {
        $this->fakeWompi();

        $source = WompiPaymentSourceService::make()->createForMember($this->cardData());

        $this->assertSame(WompiPaymentSource::STATUS_AVAILABLE, $source->status);
        $this->assertSame('1001', (string) $source->wompi_payment_source_id);
        $this->assertSame('VISA', $source->card_brand);
        $this->assertSame('4242', $source->card_last_four);
        // NUNCA se persiste el token ni datos sensibles.
        $this->assertArrayNotHasKey('token', $source->getAttributes());
        $this->assertStringNotContainsString('tok_test', json_encode($source->getAttributes()));
    }

    public function test_declined_payment_source_is_mapped_declined(): void
    {
        $this->fakeWompi(sourceStatus: 'DECLINED');

        $source = WompiPaymentSourceService::make()->createForMember($this->cardData());

        $this->assertSame(WompiPaymentSource::STATUS_DECLINED, $source->status);
        $this->assertFalse($source->isChargeable());
    }

    // ── 2) Suscripción + primer cobro ─────────────────────────────────────────

    public function test_creates_subscription_pending_when_first_charge_pending(): void
    {
        // Cobro PENDING → suscripción queda pending_first_payment, membresía NO activa.
        $this->fakeWompi(chargeStatus: 'PENDING');

        $out = MembershipSubscriptionService::make()->subscribeWithFirstCharge($this->cardData());

        $this->assertSame(MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT, $out['subscription']->status);
        $this->assertSame(80000.0, (float) $out['subscription']->price_snapshot);
        $this->assertSame(30, (int) $out['subscription']->interval_days);
        // NO se extendió la membresía (nada en `payments`, sin fecha de fin).
        $this->assertSame(0, Payment::where('user_id', $this->user->id)->count());
        $this->user->refresh();
        $this->assertNull($this->user->membership_end_date);
    }

    public function test_first_charge_approved_activates_subscription_and_membership(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');

        $out = MembershipSubscriptionService::make()->subscribeWithFirstCharge($this->cardData());

        $sub = $out['subscription'];
        $this->assertSame(MembershipSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertSame(PaymentStateMachine::APPROVED, $out['charge']->status);
        $this->assertTrue((bool) $out['charge']->is_recurring);

        // Membresía extendida por el ACTIVADOR compartido (una sola vez).
        $this->assertSame(1, Payment::where('reference', $out['charge']->reference)->count());
        $this->user->refresh();
        $this->assertNotNull($this->user->membership_end_date);
        $this->assertSame('active', $this->user->status);
        $this->member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $this->member->status);
        // Evento de auditoría.
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id, 'type' => 'first_charge_approved',
        ]);
    }

    public function test_double_attempt_does_not_charge_twice(): void
    {
        // Primer cobro PENDING (sigue viva pending_first_payment). Segundo intento
        // el MISMO día debe reutilizar el mismo periodo → NO recobra.
        $this->fakeWompi(chargeStatus: 'PENDING');
        $svc = MembershipSubscriptionService::make();

        $out1 = $svc->subscribeWithFirstCharge($this->cardData());
        $out2 = $svc->subscribeWithFirstCharge($this->cardData(['token' => 'tok_test_def456']));

        // Una sola suscripción viva y un solo cobro recurrente.
        $this->assertSame(1, MembershipSubscription::where('member_id', $this->member->id)->count());
        $this->assertSame(1, PaymentTransaction::where('is_recurring', true)->count());
        $this->assertSame($out1['charge']->id, $out2['charge']->id);
        // Solo se envió UN POST de cobro a Wompi (el segundo intento no recobra).
        $this->assertSame(1, $this->countChargePosts());
    }

    public function test_active_subscription_is_idempotent_no_second_charge(): void
    {
        $this->fakeWompi(chargeStatus: 'APPROVED');
        $svc = MembershipSubscriptionService::make();

        $svc->subscribeWithFirstCharge($this->cardData());
        $out2 = $svc->subscribeWithFirstCharge($this->cardData(['token' => 'tok_test_def456']));

        $this->assertSame('already_subscribed', $out2['status']);
        $this->assertNull($out2['charge']);
        $this->assertSame(1, PaymentTransaction::where('is_recurring', true)->count());
        $this->assertSame(1, Payment::where('user_id', $this->user->id)->count());
    }

    // ── 3) Flag apagado ───────────────────────────────────────────────────────

    public function test_disabled_flag_does_not_touch_wompi_nor_charge(): void
    {
        config()->set('wompi.recurring.enabled', false);
        Http::fake(); // cualquier llamada fallaría la aserción de abajo.

        try {
            MembershipSubscriptionService::make()->subscribeWithFirstCharge($this->cardData());
            $this->fail('Se esperaba SubscriptionException (recurring_disabled).');
        } catch (SubscriptionException $e) {
            $this->assertSame('recurring_disabled', $e->errorCode);
        }

        Http::assertNothingSent();
        $this->assertSame(0, MembershipSubscription::count());
        $this->assertSame(0, PaymentTransaction::where('is_recurring', true)->count());
        $this->assertSame(0, WompiPaymentSource::count());
    }

    // ── 4) Métodos no soportados ──────────────────────────────────────────────

    /** @dataProvider unsupportedMethods */
    public function test_unsupported_methods_are_rejected(string $type): void
    {
        Http::fake();

        try {
            MembershipSubscriptionService::make()->subscribeWithFirstCharge($this->cardData(['type' => $type]));
            $this->fail("Se esperaba rechazo del método {$type}.");
        } catch (SubscriptionException $e) {
            $this->assertSame('unsupported_autopay_method', $e->errorCode);
        }

        // No se creó fuente ni se cobró; no se envió el cobro a Wompi.
        $this->assertSame(0, PaymentTransaction::where('is_recurring', true)->count());
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/transactions'));
    }

    public static function unsupportedMethods(): array
    {
        return [
            'PSE'                  => ['PSE'],
            'DAVIPLATA'            => ['DAVIPLATA'],
            'BANCOLOMBIA_TRANSFER' => ['BANCOLOMBIA_TRANSFER'],
            // Nequi está apagado por flag (methods.nequi=false) → también se rechaza.
            'NEQUI'                => ['NEQUI'],
        ];
    }

    /** Nº de POST a /transactions (cobros realmente enviados a Wompi). */
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
}
