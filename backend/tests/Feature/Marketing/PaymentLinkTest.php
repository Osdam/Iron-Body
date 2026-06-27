<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 — Link de pago Wompi por WhatsApp/Meta. Verifica que el agente pueda
 * GENERAR un link (monto autoritativo del backend) pero que la membresía SOLO se
 * active por el webhook Wompi aprobado (flujo seguro existente). Guardrails:
 * do_not_contact, monto prohibido en payload, plan inactivo, sin config.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private MarketingLead $lead;

    private const INTERNAL_SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env'              => 'sandbox',
            'currency'         => 'COP',
            'public_key'       => 'pub_test_link',
            'integrity_secret' => 'test_integrity_link',
            'events_secret'    => 'test_events_link',
            'redirect_url'     => 'https://app.ironbody.test/return',
            'checkout'         => [
                'base_url'           => 'https://checkout.wompi.co/p/',
                'redirect_url'       => null,
                'expiration_minutes' => 1440,
            ],
        ]));

        config()->set('automation.internal_secret', self::INTERNAL_SECRET);
        config()->set('marketing.payment_links.source', 'marketing_agent');

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp',
            'source'  => 'inbound',
            'phone'   => '3215542105',
            'name'    => 'Lead Demo',
            'status'  => MarketingLead::STATUS_INTERESTED,
        ]);
    }

    private function internalHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::INTERNAL_SECRET];
    }

    private function generate(array $payload = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/payment-links', array_merge([
            'marketing_lead_id' => $this->lead->id,
            'plan_id'           => $this->plan->id,
        ], $payload), array_merge($this->internalHeaders(), $headers));
    }

    public function test_generates_payment_link_for_valid_lead_and_active_plan(): void
    {
        $res = $this->generate();

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('amount', 80000)
            ->assertJsonPath('currency', 'COP')
            ->assertJsonPath('safe_to_send', true);

        $url = $res->json('payment_url');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        $this->assertStringContainsString('public-key=pub_test_link', $url);
        $this->assertStringContainsString('amount-in-cents=8000000', $url);
        $this->assertStringContainsString('signature:integrity=', $url);
        $this->assertStringContainsString('reference='.$res->json('reference'), $url);

        $this->assertDatabaseHas('payment_transactions', [
            'provider' => 'wompi',
            'method'   => 'web_checkout',
            'plan_id'  => $this->plan->id,
            'amount'   => 80000,
        ]);
    }

    public function test_does_not_generate_if_do_not_contact(): void
    {
        $this->lead->update(['do_not_contact' => true]);

        $this->generate()
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'lead_do_not_contact');

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_rejects_amount_sent_in_payload(): void
    {
        $this->generate(['amount' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'amount_not_allowed');

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_does_not_generate_for_inactive_plan(): void
    {
        $this->plan->update(['active' => false]);

        $this->generate()
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_inactive');

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_records_marketing_metadata_and_source(): void
    {
        $this->generate()->assertOk();

        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();
        $this->assertNotNull($tx);
        $this->assertSame('marketing_agent', $tx->metadata['source'] ?? null);
        $this->assertSame($this->lead->id, $tx->metadata['marketing_lead_id'] ?? null);
    }

    public function test_generating_link_does_not_activate_membership(): void
    {
        $this->generate()->assertOk();

        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();
        // La transacción queda 'created' (NO aprobada) y NO se crea pago legado.
        $this->assertSame(PaymentStateMachine::CREATED, $tx->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_approved_wompi_webhook_is_what_activates_membership(): void
    {
        // Lead con miembro+usuario enlazados (para que la activación cree Payment).
        $user = User::create([
            'name' => 'Oscar', 'email' => 'oscar@example.com', 'password' => bcrypt('x'),
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'pending',
        ]);
        $member = Member::create([
            'full_name' => 'Oscar', 'email' => 'oscar@example.com', 'document_number' => '1004301550',
            'phone' => '3215542105', 'status' => Member::STATUS_ACTIVE, 'user_id' => $user->id,
        ]);
        $this->lead->update(['member_id' => $member->id]);

        $this->generate()->assertOk();
        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();

        // Antes del webhook: sin pago legado.
        $this->assertDatabaseCount('payments', 0);

        $payload = [
            'event' => 'transaction.updated',
            'data'  => ['transaction' => [
                'id' => 'wompi-link-tx-1', 'status' => 'APPROVED',
                'reference' => $tx->reference, 'amount_in_cents' => 8000000, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => ''],
            'timestamp' => 1700000000,
        ];
        $checksum = (new WompiSignatureService(['events_secret' => 'test_events_link']))
            ->computeWebhookChecksum($payload, 'test_events_link');
        $payload['signature']['checksum'] = strtoupper($checksum);

        $this->postJson('/api/webhooks/wompi', $payload)->assertOk();

        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    public function test_internal_endpoint_requires_bearer(): void
    {
        $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id,
            'plan_id'           => $this->plan->id,
        ])->assertStatus(401);
    }

    public function test_returns_controlled_error_when_checkout_not_configured(): void
    {
        config()->set('wompi', array_merge((array) config('wompi'), [
            'public_key'       => '',
            'integrity_secret' => '',
        ]));

        $this->generate()
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'wompi_checkout_not_configured');

        // No se crean datos corruptos.
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    /**
     * Regresión del bug 22P02: order_id es bigint → JAMÁS debe recibir un string
     * (p. ej. "mkt-lead-1-plan-1"). La referencia textual va en `reference`; el
     * dedup va en `idempotency_key` (string). order_id queda null.
     */
    public function test_does_not_pass_string_to_bigint_order_id(): void
    {
        $this->generate()->assertOk();

        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();
        $this->assertNull($tx->order_id, 'order_id debe quedar null en links de marketing');
        $this->assertIsString($tx->reference);
        $this->assertNotSame('', $tx->reference);
        // El dedup vive en idempotency_key (string), no en order_id.
        $this->assertStringStartsWith('mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-', (string) $tx->idempotency_key);
    }

    /** Idempotencia: dos llamadas (lead+plan) reutilizan la transacción en vuelo. */
    public function test_reuses_inflight_transaction_for_same_lead_plan(): void
    {
        $first  = $this->generate()->assertOk();
        $second = $this->generate()->assertOk();

        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, PaymentTransaction::where('provider', 'wompi')->count());
    }
}
