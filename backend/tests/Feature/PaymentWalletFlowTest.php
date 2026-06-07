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
 * Flujo funcional de billeteras ePayco (Nequi/DaviPlata) + webhook + status.
 *
 * El cliente HTTP de ePayco (EpaycoApiClient) se MOCKEA: los tests verifican la
 * lógica de negocio (transacción pendiente, no activar en pending, webhook
 * idempotente que activa membresía, rechazo que no activa, status, SSE y monto
 * autoritativo) SIN llamadas reales a ePayco.
 */
class PaymentWalletFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Modo SANDBOX puro y sin llaves: el webhook se valida por confianza de
        // pruebas (x_cod_response) y NUNCA hace llamadas reales a ePayco. Esto
        // hace los tests deterministas (independientes del .env del entorno).
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

    /** Mockea EpaycoApiClient para que el método [$method] devuelva PENDIENTE. */
    private function fakeApiPending(string $method): void
    {
        $this->mock(EpaycoApiClient::class, function ($m) use ($method) {
            $m->shouldReceive($method)->andReturn([
                'ok' => true,
                'state' => 3, // Pendiente
                'state_text' => null,
                'ref_payco' => 'REF-PENDING-1',
                'transaction_id' => 'TX-1',
                'message' => 'Revisa tu app para aprobar el pago.',
                'requires_external' => false,
                'raw' => [],
            ]);
        });
    }

    public function test_nequi_creates_pending_transaction(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeApiPending('payNequi');

        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-1',
        ]);

        $r->assertOk()
            ->assertJsonPath('method', 'nequi');
        $this->assertContains($r->json('status'), ['pending', 'processing']);

        $tx = PaymentTransaction::where('reference', $r->json('reference'))->first();
        $this->assertNotNull($tx);
        $this->assertSame('nequi', $tx->method);
        $this->assertTrue($tx->isInFlight());
        // PENDIENTE no activa membresía.
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertDatabaseMissing('payments', ['reference' => $tx->reference]);
    }

    public function test_daviplata_creates_pending_transaction(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeApiPending('payDaviplata');

        $r = $this->postJson('/api/payments/epayco/pay-daviplata', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-davi-1',
        ]);

        $r->assertOk()->assertJsonPath('method', 'daviplata');
        $this->assertContains($r->json('status'), ['pending', 'processing']);
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_backend_overrides_manipulated_amount_with_plan_price(): void
    {
        $plan = $this->plan(); // 80000
        $member = $this->member();
        $this->fakeApiPending('payNequi');

        // Flutter manipulado intenta pagar 1000 → el backend manda el precio real.
        $r = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 1000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-amount',
        ]);

        $r->assertOk();
        $tx = PaymentTransaction::where('reference', $r->json('reference'))->first();
        $this->assertEqualsWithDelta(80000.0, (float) $tx->amount, 0.01);
    }

    public function test_approved_webhook_activates_membership_and_is_idempotent(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeApiPending('payNequi');

        $start = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-approve',
        ]);
        $reference = $start->json('reference');

        $payload = [
            'x_extra1' => $reference,
            'x_cod_response' => 1, // Aceptada
            'x_amount' => 80000,
            'x_ref_payco' => 'REF-APPROVED-1',
            'x_transaction_id' => 'TXAP-1',
        ];

        // Webhook 1: aprueba y activa.
        $this->postJson('/api/payments/epayco/confirmation', $payload)
            ->assertOk()->assertJsonPath('received', true);

        $tx = PaymentTransaction::where('reference', $reference)->first();
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, $tx->status);
        $member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertSame(1, Payment::where('reference', $reference)->count());

        // Webhook 2 (reintento de ePayco): idempotente, no duplica nada.
        $this->postJson('/api/payments/epayco/confirmation', $payload)->assertOk();
        $this->assertSame(1, Payment::where('reference', $reference)->count());
    }

    public function test_approval_broadcasts_payment_membership_and_app_state(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeApiPending('payNequi');

        $reference = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-sse',
        ])->json('reference');

        $this->postJson('/api/payments/epayco/confirmation', [
            'x_extra1' => $reference,
            'x_cod_response' => 1,
            'x_amount' => 80000,
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
        $this->fakeApiPending('payNequi');

        $reference = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-reject',
        ])->json('reference');

        $this->postJson('/api/payments/epayco/confirmation', [
            'x_extra1' => $reference,
            'x_cod_response' => 2, // Rechazada
            'x_amount' => 80000,
            'x_ref_payco' => 'REF-REJ-1',
            'x_transaction_id' => 'TXREJ-1',
        ])->assertOk();

        $tx = PaymentTransaction::where('reference', $reference)->first();
        $this->assertSame(PaymentTransaction::STATUS_FAILED, $tx->status);
        $member->refresh();
        $this->assertNotSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertDatabaseMissing('payments', ['reference' => $reference]);
    }

    public function test_status_returns_state_and_unknown_reference_is_404(): void
    {
        $plan = $this->plan();
        $member = $this->member();
        $this->fakeApiPending('payNequi');

        $reference = $this->postJson('/api/payments/epayco/pay-nequi', [
            'amount' => 80000,
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'phone' => '3001234567',
            'idempotency_key' => 'idem-nequi-status',
        ])->json('reference');

        // Status pendiente: NO aprobado (no desbloquea).
        $this->getJson("/api/payments/{$reference}/status")
            ->assertOk()
            ->assertJsonPath('status', fn ($s) => in_array($s, ['pending', 'processing'], true));

        // Referencia inexistente → 404 (la app muestra estado claro, no desbloquea).
        $this->getJson('/api/payments/NO-EXISTE/status')->assertStatus(404);
    }
}
