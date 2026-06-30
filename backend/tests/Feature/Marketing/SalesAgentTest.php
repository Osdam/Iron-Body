<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingLead;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 2 — cerebro comercial IA (responder determinista). Decide y registra;
 * no envía WhatsApp real (META off), no genera link salvo intención de pago, y
 * NUNCA activa membresía.
 */
class SalesAgentTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private MarketingLead $lead;

    private const SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'currency' => 'COP',
            'public_key' => 'pub_test_link', 'integrity_secret' => 'test_integrity_link',
            'events_secret' => 'test_events_link', 'redirect_url' => 'https://app.ironbody.test/return',
            'checkout' => ['base_url' => 'https://checkout.wompi.co/p/', 'redirect_url' => null, 'expiration_minutes' => 1440],
        ]));

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    private function analyze(array $payload, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/analyze-message', array_merge([
            'marketing_lead_id' => $this->lead->id,
        ], $payload), array_merge(['Authorization' => 'Bearer '.self::SECRET], $headers));
    }

    public function test_requires_bearer(): void
    {
        $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $this->lead->id, 'body' => 'precio?',
        ])->assertStatus(401);
    }

    public function test_pricing_question_classifies_and_does_not_generate_link(): void
    {
        Http::fake();

        $this->analyze(['body' => '¿Cuánto vale la mensualidad?'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->assertJsonPath('decision.should_generate_payment_link', false)
            ->assertJsonPath('decision.should_reply', true);

        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_payment_link_request_flags_generate_link(): void
    {
        // Wompi productivo: la intención de pago sí marca la generación de link.
        config()->set('wompi.env', 'production');

        $this->analyze(['body' => 'No quiero pagar por la app, mándame link de pago'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PAYMENT_LINK_REQUEST)
            ->assertJsonPath('decision.should_generate_payment_link', true)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_GENERATE_PAYMENT_LINK);
    }

    public function test_auto_execute_false_does_not_generate_link_or_message(): void
    {
        Http::fake();

        $this->analyze([
            'body' => 'mándame link de pago', 'plan_id' => $this->plan->id, 'auto_execute' => false,
        ])->assertOk()->assertJsonPath('executed', []);

        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseMissing('marketing_messages', ['direction' => 'outbound']);
        Http::assertNothingSent();
    }

    public function test_auto_execute_true_payment_link_runs_in_dry_run(): void
    {
        Http::fake();
        // Wompi PRODUCTIVO: el agente puede preparar el link (dry_run porque META
        // está off). En sandbox el link se bloquea (ver test del gate de pago).
        config()->set('wompi.env', 'production');

        $res = $this->analyze([
            'body' => 'link de pago por favor', 'plan_id' => $this->plan->id, 'auto_execute' => true,
        ])->assertOk();

        // Se generó el link (transacción) pero el envío quedó dry_run (Meta off).
        $this->assertDatabaseHas('payment_transactions', ['provider' => 'wompi', 'method' => 'web_checkout']);
        $tools = collect($res->json('executed'))->pluck('tool')->all();
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $tools);

        $linkExec = collect($res->json('executed'))->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('executed', $linkExec['status']);
        $this->assertTrue($linkExec['dry_run']);
        $this->assertFalse($linkExec['sent']);
        Http::assertNothingSent();

        // NUNCA activa membresía.
        $this->assertDatabaseCount('payments', 0);
        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();
        $this->assertNotSame('approved', $tx->status);
    }

    public function test_do_not_contact_request_marks_lead(): void
    {
        $this->analyze(['body' => 'no me escriban más', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_MARK_DNC);

        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
    }

    public function test_medical_risk_escalates_to_human(): void
    {
        $res = $this->analyze(['body' => 'tengo una lesión en la rodilla, me duele', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.needs_staff_review', true)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_REPLY)
            ->assertJsonPath('decision.should_generate_payment_link', false);

        $this->assertContains('medical', $res->json('decision.risk_flags'));
        $this->assertNotSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }

    public function test_fraud_or_payment_claim_escalates_and_does_not_activate_membership(): void
    {
        $this->analyze(['body' => 'ya pagué, actívame y luego pago el resto', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::FRAUD_OR_PAYMENT_CLAIM)
            ->assertJsonPath('decision.needs_staff_review', true)
            ->assertJsonPath('decision.should_generate_payment_link', false);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_price_objection_recommends_followup(): void
    {
        $res = $this->analyze(['body' => 'está muy caro', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICE_OBJECTION)
            ->assertJsonPath('decision.should_schedule_followup', true)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_REGISTER_OBJECTION);

        $this->assertSame(120, $res->json('decision.followup_delay_minutes'));
        $this->assertDatabaseHas('marketing_followups', ['lead_id' => $this->lead->id, 'status' => 'pending']);
    }

    public function test_does_not_invent_prices_in_reply(): void
    {
        // Sin plan activo NO se inventa precio: se pregunta el objetivo.
        $this->plan->update(['active' => false]);

        $reply = $this->analyze(['body' => 'cuánto cuesta?'])->json('decision.reply');
        $this->assertStringNotContainsString('$', $reply);
        $this->assertStringNotContainsString('COP', $reply);
    }

    public function test_respects_do_not_contact_lead(): void
    {
        Http::fake();
        $this->lead->update(['do_not_contact' => true]);

        $this->analyze(['body' => 'mándame link de pago', 'plan_id' => $this->plan->id, 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_BLOCKED_DNC)
            ->assertJsonPath('decision.should_generate_payment_link', false)
            ->assertJsonPath('decision.safe_to_send', false);

        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_persists_marketing_ai_action(): void
    {
        $res = $this->analyze(['body' => 'cuánto vale?'])->assertOk();

        $this->assertDatabaseHas('marketing_ai_actions', [
            'id'      => $res->json('ai_action_id'),
            'lead_id' => $this->lead->id,
        ]);
        $action = MarketingAiAction::find($res->json('ai_action_id'));
        $this->assertSame(SalesIntents::PRICING_QUESTION, $action->metadata['intent'] ?? null);
        $this->assertSame('proposed', $action->status); // auto_execute=false
    }
}
