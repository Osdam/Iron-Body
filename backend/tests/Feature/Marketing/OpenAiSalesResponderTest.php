<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 3 — cerebro OpenAI detrás de AiSalesResponderInterface. El modelo SOLO
 * recomienda; Laravel valida (validator) y ejecuta (guardrails). Por defecto se
 * usa fake (no se llama a OpenAI). Errores OpenAI nunca dan 500. Nunca activa
 * membresía ni envía WhatsApp real (META off).
 */
class OpenAiSalesResponderTest extends TestCase
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
        // Default: driver fake (no OpenAI).
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ai.openai.enabled', false);
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

    private function enableOpenAi(): void
    {
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-test');
        config()->set('services.openai.api_key', 'sk-test-xxx');
    }

    private function fakeOpenAi(array $decision): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($decision)]]],
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function analyze(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/analyze-message', array_merge([
            'marketing_lead_id' => $this->lead->id,
        ], $payload), ['Authorization' => 'Bearer '.self::SECRET]);
    }

    // ── Driver / fallback ─────────────────────────────────────────────────────

    public function test_default_uses_fake_responder(): void
    {
        Http::fake();
        $this->analyze(['body' => 'cuánto vale?'])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'fake');
        Http::assertNothingSent();
    }

    public function test_openai_driver_without_api_key_falls_back_without_500(): void
    {
        Http::fake();
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-test');
        config()->set('services.openai.api_key', ''); // sin key

        $this->analyze(['body' => 'cuánto vale?'])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'fake'); // cae a fake, sin 500
        Http::assertNothingSent();
    }

    public function test_valid_openai_json_becomes_valid_decision(): void
    {
        // Intención NO-pricing: el reply del modelo (saneado) pasa tal cual.
        // (Pricing es determinista en Laravel; se prueba aparte en PricingReplyTest.)
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::LOCATION_QUESTION, 'confidence' => 0.9,
            'reply' => 'Estamos en Neiva, ¿quieres que te oriente con un plan?',
            'tools_requested' => ['reply'],
        ]);

        $this->analyze(['body' => '¿dónde quedan?'])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'openai')
            ->assertJsonPath('decision.intent', SalesIntents::LOCATION_QUESTION)
            ->assertJsonPath('decision.reply', 'Estamos en Neiva, ¿quieres que te oriente con un plan?');
    }

    public function test_invalid_openai_json_does_not_500(): void
    {
        $this->enableOpenAi(); // fail_closed=true por defecto
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'esto no es json {']]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $this->analyze(['body' => 'hola'])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'fallback')
            ->assertJsonPath('decision.intent', SalesIntents::UNKNOWN);
    }

    // ── Guardrails sobre la salida del modelo ─────────────────────────────────

    public function test_forbidden_tool_is_blocked(): void
    {
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::PRICING_QUESTION, 'confidence' => 0.8,
            'reply' => 'Te ayudo a elegir.', 'tools_requested' => ['send_email', 'reply'],
        ]);

        $tools = $this->analyze(['body' => 'info'])->assertOk()->json('decision.tools_requested');
        $this->assertNotContains('send_email', $tools); // herramienta no permitida bloqueada
    }

    public function test_membership_activation_attempt_is_blocked_and_escalates(): void
    {
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'confidence' => 0.95,
            'reply' => 'Listo.', 'tools_requested' => ['activate_membership'],
        ]);

        $this->analyze(['body' => 'quiero empezar hoy', 'plan_id' => $this->plan->id, 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.should_escalate', true)
            ->assertJsonPath('decision.should_generate_payment_link', false)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_ESCALATE_HUMAN);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invented_price_in_reply_is_corrected(): void
    {
        $this->enableOpenAi();
        // El modelo INVENTA un precio equivocado; Laravel cotiza el REAL desde DB.
        $this->fakeOpenAi([
            'intent' => SalesIntents::PRICING_QUESTION, 'confidence' => 0.9,
            'reply' => 'La mensualidad cuesta $999.999 al mes.', 'tools_requested' => ['reply'],
        ]);

        $reply = $this->analyze(['body' => 'precio?'])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'openai')
            ->json('decision.reply');

        // El precio inventado por el modelo se descarta; aparece el REAL de DB.
        $this->assertStringNotContainsString('999.999', $reply);
        $this->assertStringContainsString('$80.000 COP', $reply);
    }

    public function test_medical_risk_still_escalates_with_openai(): void
    {
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::MEDICAL_RISK_ESCALATION, 'confidence' => 0.9,
            'reply' => 'Cuéntame.', 'tools_requested' => ['human_takeover'],
        ]);

        $this->analyze(['body' => 'me duele la rodilla', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.should_escalate', true)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_ESCALATE_HUMAN);

        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }

    public function test_do_not_contact_still_blocks_with_openai(): void
    {
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST, 'confidence' => 0.9,
            'reply' => null, 'tools_requested' => ['mark_do_not_contact'],
        ]);

        $this->analyze(['body' => 'no me escriban', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_MARK_DNC);

        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
    }

    public function test_payment_link_auto_execute_uses_dry_run_with_openai(): void
    {
        // Wompi PRODUCTIVO: el agente sí puede preparar el link (en dry_run porque
        // META está off). Con sandbox, el link queda bloqueado (otro test).
        config()->set('wompi.env', 'production');
        $this->enableOpenAi();
        $this->fakeOpenAi([
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST, 'confidence' => 0.95,
            'reply' => 'Claro, te paso el link.', 'tools_requested' => ['payment_link_send'],
        ]);

        $res = $this->analyze(['body' => 'mándame el link', 'plan_id' => $this->plan->id, 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.responder', 'openai');

        $linkExec = collect($res->json('executed'))->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('executed', $linkExec['status']);
        $this->assertTrue($linkExec['dry_run']);
        $this->assertFalse($linkExec['sent']);

        $this->assertDatabaseHas('payment_transactions', ['provider' => 'wompi', 'method' => 'web_checkout']);
        $this->assertDatabaseCount('payments', 0); // nunca activa membresía
    }

    // ── Doctor ────────────────────────────────────────────────────────────────

    public function test_ai_doctor_command_does_not_leak_secrets(): void
    {
        config()->set('services.openai.api_key', 'SECRET_OPENAI_VALUE');

        $code = Artisan::call('marketing:ai-doctor');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('responder efectivo', $output);
        $this->assertStringNotContainsString('SECRET_OPENAI_VALUE', $output);
    }

    public function test_ai_doctor_endpoint_requires_bearer_and_returns_json(): void
    {
        $this->getJson('/api/internal/marketing/ai/doctor')->assertStatus(401);

        $this->getJson('/api/internal/marketing/ai/doctor', ['Authorization' => 'Bearer '.self::SECRET])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.effective_responder', 'fake')
            ->assertJsonStructure(['data' => ['driver', 'openai_ready', 'present', 'suggestions']]);
    }
}
