<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\EpaycoApiClient;
use App\Services\RealtimeEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flujo Smart Checkout v2 de billeteras (Nequi/DaviPlata) + webhook + status.
 *
 * El cliente APIFY (EpaycoApiClient::createCheckoutSession) se MOCKEA: los tests
 * verifican la lógica de negocio (sesión pendiente, no activar en pending,
 * webhook idempotente que activa, firma inválida, monto autoritativo, status con
 * can_access_home/membership_active y SSE) SIN llamadas reales a ePayco.
 */
class PaymentWalletFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Sandbox sin llaves: el webhook se valida por confianza de pruebas
        // (x_cod_response) y nunca llama a ePayco. Determinista.
        config([
            'services.epayco.test' => true,
            'services.epayco.p_key' => null,
            'services.epayco.p_cust_id_cliente' => null,
            'services.epayco.public_key' => null,
            'services.epayco.private_key' => null,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Mensual',
            'price' => 80000,
            'duration_days' => 30,
            'active' => true,
        ]);
    }

    private function member(): Member
    {
        return Member::create([
            'full_name' => 'Ana Prueba',
            'email' => 'ana@example.com',
            'document_number' => '1010101010',
            'phone' => '3001234567',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);
    }

    /** Mock: la sesión Smart Checkout se crea OK con un sessionId. */
    private function fakeSessionOk(): void
    {
        $this->mock(EpaycoApiClient::class, function ($m) {
            $m->shouldReceive('createCheckoutSession')->andReturn([
                'ok' => true,
                'session_id' => 'sess_ABC123',
                'message' => null,
                'raw' => [],
            ]);
        });
    }

    /** Mock: la sesión Smart Checkout falla (APIFY login/session error). */
    private function fakeSessionFail(): void
    {
        $this->mock(EpaycoApiClient::class, function ($m) {
            $m->shouldReceive('createCheckoutSession')->andReturn([
                'ok' => false,
                'session_id' => null,
                'message' => 'No pudimos iniciar el pago con ePayco. Intenta nuevamente.',
                'raw' => [],
            ]);
        });
    }

    private function startWallet(string $method, Plan $plan, Member $member, array $over = []): array
    {
        return $this->postJson("/api/payments/epayco/pay-{$method}", array_merge([
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => "idem-{$method}-" . uniqid(),
        ], $over))->json();
    }

    public function test_nequi_creates_smart_checkout_session(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();

        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-1',
        ]);

        $r->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('method', 'nequi')
            ->assertJsonPath('flow', 'smart_checkout')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('session_id', 'sess_ABC123');
        $this->assertNotEmpty($r->json('checkout_bridge_url'));

        $tx = PaymentTransaction::where('reference', $r->json('reference'))->first();
        $this->assertSame('nequi', $tx->method);
        $this->assertTrue($tx->isInFlight());
        // PENDIENTE no activa membresía.
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertDatabaseMissing('payments', ['reference' => $tx->reference]);
    }

    public function test_daviplata_creates_smart_checkout_session(): void
    {
        // DaviPlata también falla por API directa ("no habilitado") → Smart
        // Checkout v2, igual que Nequi.
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();

        $r = $this->postJson('/api/payments/epayco/pay-daviplata', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-davi-1',
        ]);

        $r->assertOk()
            ->assertJsonPath('flow', 'smart_checkout')
            ->assertJsonPath('method', 'daviplata')
            ->assertJsonPath('status', 'pending');
        $this->assertNotEmpty($r->json('session_id'));
        $this->assertNotEmpty($r->json('checkout_bridge_url'));

        $tx = PaymentTransaction::where('reference', $r->json('reference'))->first();
        $this->assertSame('daviplata', $tx->method);
        $this->assertTrue($tx->isInFlight());
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_apify_session_failure_does_not_activate_membership(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionFail();

        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'idempotency_key' => 'idem-nequi-fail',
        ]);

        $r->assertOk()->assertJsonPath('ok', false)->assertJsonPath('status', 'failed');
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertDatabaseMissing('payments', ['reference' => $r->json('reference')]);
    }

    public function test_session_failure_with_public_key_falls_back_to_bridge(): void
    {
        // session/create falla (200 sin sessionId) PERO hay llave pública → NO se
        // marca failed: el bridge abrirá el checkout con la llave pública. La
        // wallet SIEMPRE puede abrir el WebView.
        config(['services.epayco.public_key' => 'pub_real']);
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionFail();

        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'idempotency_key' => 'idem-fallback',
        ]);

        $r->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('flow', 'smart_checkout')
            ->assertJsonPath('status', 'pending');
        $this->assertNotEmpty($r->json('checkout_bridge_url'));
        $this->assertNull($r->json('session_id')); // sin sesión → bridge fallback

        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_backend_overrides_manipulated_amount_with_plan_price(): void
    {
        $plan = $this->plan(); // 80000
        $member = $this->member();
        $this->fakeSessionOk();

        $r = $this->startWallet('nequi', $plan, $member, ['amount' => 1000]);
        $tx = PaymentTransaction::where('reference', $r['reference'])->first();
        $this->assertEqualsWithDelta(80000.0, (float) $tx->amount, 0.01);
    }

    public function test_pending_session_does_not_unlock_home(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $r = $this->startWallet('nequi', $plan, $member);

        $this->getJson("/api/payments/{$r['reference']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('membership_active', false)
            ->assertJsonPath('can_access_home', false);
    }

    public function test_approved_webhook_activates_membership_and_is_idempotent(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $reference = $this->startWallet('nequi', $plan, $member)['reference'];

        $payload = [
            'x_extra1' => $reference,
            'x_cod_response' => 1,
            'x_amount' => 80000,
            'x_currency_code' => 'COP',
            'x_ref_payco' => 'REF-AP-1',
            'x_transaction_id' => 'TXAP-1',
        ];

        $this->postJson('/api/payments/epayco/confirmation', $payload)->assertOk();

        $tx = PaymentTransaction::where('reference', $reference)->first();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $tx->status);
        $member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertSame(1, Payment::where('reference', $reference)->count());

        // Reintento de ePayco: idempotente.
        $this->postJson('/api/payments/epayco/confirmation', $payload)->assertOk();
        $this->assertSame(1, Payment::where('reference', $reference)->count());

        // Status: ahora membresía activa y acceso a Home.
        $this->getJson("/api/payments/{$reference}/status")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('membership_active', true)
            ->assertJsonPath('can_access_home', true);
    }

    public function test_approval_broadcasts_payment_membership_and_app_state(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $reference = $this->startWallet('nequi', $plan, $member)['reference'];

        $this->postJson('/api/payments/epayco/confirmation', [
            'x_extra1' => $reference,
            'x_cod_response' => 1,
            'x_amount' => 80000,
            'x_currency_code' => 'COP',
            'x_ref_payco' => 'REF-SSE-1',
            'x_transaction_id' => 'TXSSE-1',
        ])->assertOk();

        foreach ([RealtimeEvents::PAYMENT, RealtimeEvents::MEMBERSHIP, RealtimeEvents::APP_STATE] as $type) {
            $this->assertTrue(
                MemberRealtimeEvent::where('member_id', $member->id)->where('type', $type)->exists(),
                "Falta el evento SSE: {$type}",
            );
        }
    }

    public function test_rejected_webhook_does_not_activate_membership(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $reference = $this->startWallet('nequi', $plan, $member)['reference'];

        $this->postJson('/api/payments/epayco/confirmation', [
            'x_extra1' => $reference,
            'x_cod_response' => 2,
            'x_amount' => 80000,
            'x_currency_code' => 'COP',
            'x_ref_payco' => 'REF-REJ-1',
            'x_transaction_id' => 'TXREJ-1',
        ])->assertOk();

        $tx = PaymentTransaction::where('reference', $reference)->first();
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $tx->status);
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_invalid_signature_does_not_activate(): void
    {
        // Con llaves de firma configuradas y SIN keys de API (no hay validación
        // remota), una firma inválida NO debe activar: el webhook deja el estado.
        config([
            'services.epayco.p_key' => 'test_pkey',
            'services.epayco.p_cust_id_cliente' => '123456',
            'services.epayco.public_key' => null,
            'services.epayco.private_key' => null,
        ]);
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $reference = $this->startWallet('nequi', $plan, $member)['reference'];

        $this->postJson('/api/payments/epayco/confirmation', [
            'x_extra1' => $reference,
            'x_cod_response' => 1,
            'x_amount' => 80000,
            'x_currency_code' => 'COP',
            'x_ref_payco' => 'REF-BADSIG',
            'x_transaction_id' => 'TXBADSIG',
            'x_signature' => 'firma-invalida',
        ])->assertOk();

        $tx = PaymentTransaction::where('reference', $reference)->first();
        $this->assertNotSame(PaymentTransaction::STATUS_APPROVED, $tx->status);
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertDatabaseMissing('payments', ['reference' => $reference]);
    }

    public function test_status_unknown_reference_is_404(): void
    {
        $this->getJson('/api/payments/NO-EXISTE/status')->assertStatus(404);
    }

    public function test_bridge_url_is_web_route_without_api_and_no_double_slash(): void
    {
        config(['app.url' => 'https://api.ironbodyneiva.cloud']);
        $url = app(\App\Services\EpaycoPaymentService::class)->checkoutBridgeUrl('IRON-X');

        $this->assertStringStartsWith(
            'https://api.ironbodyneiva.cloud/payments/epayco/checkout-bridge/IRON-X?', $url);
        $this->assertStringNotContainsString('/api/payments/epayco/checkout-bridge', $url);
        // Sin doble slash tras el dominio.
        $this->assertStringNotContainsString('cloud//payments', $url);
    }

    public function test_bridge_url_has_no_double_slash_when_app_url_has_trailing_slash(): void
    {
        config(['app.url' => 'https://api.ironbodyneiva.cloud/']);
        $url = app(\App\Services\EpaycoPaymentService::class)->checkoutBridgeUrl('IRON-Y');

        $this->assertStringContainsString('cloud/payments/epayco/checkout-bridge/IRON-Y', $url);
        $this->assertStringNotContainsString('cloud//payments', $url);
    }

    public function test_apify_payload_amount_is_integer_from_plan(): void
    {
        $plan = $this->plan(); // 80000
        $member = $this->member();
        $captured = null;
        $this->mock(EpaycoApiClient::class, function ($m) use (&$captured) {
            $m->shouldReceive('createCheckoutSession')->andReturnUsing(function ($payload) use (&$captured) {
                $captured = $payload;
                return ['ok' => true, 'session_id' => 'sess_X', 'checkout_url' => null, 'message' => null, 'raw' => []];
            });
        });

        // Flutter manda amount string raro; el backend usa el del plan, entero.
        $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 999, 'plan_id' => $plan->id, 'member_id' => $member->id,
            'idempotency_key' => 'idem-amt',
        ])->assertOk();

        $this->assertIsInt($captured['amount']);
        $this->assertSame(80000, $captured['amount']);
    }

    public function test_apify_rejects_invalid_amount_before_request(): void
    {
        // Plan por debajo del mínimo de ePayco (5000) → falla ANTES de llamar APIFY.
        $plan = Plan::create(['name' => 'Mini', 'price' => 1000, 'duration_days' => 30, 'active' => true]);
        $member = $this->member();
        $this->mock(EpaycoApiClient::class, function ($m) {
            $m->shouldReceive('createCheckoutSession')->never();
        });

        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 1000, 'plan_id' => $plan->id, 'member_id' => $member->id,
            'idempotency_key' => 'idem-bad-amt',
        ]);
        $r->assertOk()->assertJsonPath('status', 'failed');
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_bridge_route_returns_controlled_not_generic_404(): void
    {
        // La ruta web del bridge EXISTE: con token inválido responde 403 (no 404).
        $this->get('/payments/epayco/checkout-bridge/CUALQUIERA?exp=1&t=bad')
            ->assertStatus(403);
    }

    public function test_checkout_bridge_renders_valid_html(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeSessionOk();
        $bridgeUrl = $this->startWallet('nequi', $plan, $member)['checkout_bridge_url'];

        // GET al bridge (ruta web firmada) → HTML válido con checkout-v2.js.
        $path = parse_url($bridgeUrl, PHP_URL_PATH) . '?' . parse_url($bridgeUrl, PHP_URL_QUERY);
        $res = $this->get($path);
        $res->assertOk();
        $res->assertSee('checkout-v2.js', false);
        $res->assertSee('sess_ABC123', false); // sessionId en el HTML
        // Token firmado inválido → 403.
        $this->get(parse_url($bridgeUrl, PHP_URL_PATH) . '?exp=1&t=bad')->assertStatus(403);
    }
}
