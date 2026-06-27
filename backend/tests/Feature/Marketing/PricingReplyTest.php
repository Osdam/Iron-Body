<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 3.6 — pricing determinista. Si el plan está identificado (plan_id o
 * nombre claro), el reply incluye nombre + precio REAL COP + cierre suave,
 * garantizado por Laravel (no depende de OpenAI). Sin plan claro NO inventa.
 */
class PricingReplyTest extends TestCase
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

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso completo', 'Asesoría', 'Clases grupales']),
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    private function analyze(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/analyze-message', array_merge([
            'marketing_lead_id' => $this->lead->id,
        ], $payload), ['Authorization' => 'Bearer '.self::SECRET]);
    }

    public function test_pricing_with_plan_id_includes_real_price_and_name(): void
    {
        $reply = $this->analyze(['body' => '¿cuánto vale el plan mensual?', 'plan_id' => $this->plan->id])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->json('decision.reply');

        $this->assertStringContainsString('Plan Mensual', $reply);
        $this->assertStringContainsString('$80.000 COP', $reply);
        $this->assertStringContainsString('link seguro de pago', $reply);
    }

    public function test_pricing_with_plan_id_includes_benefits(): void
    {
        $reply = $this->analyze(['body' => 'precio?', 'plan_id' => $this->plan->id])
            ->assertOk()->json('decision.reply');

        $this->assertStringContainsString('Acceso completo', $reply);
    }

    public function test_pricing_resolves_plan_by_name_without_plan_id(): void
    {
        $reply = $this->analyze(['body' => '¿cuánto cuesta el Plan Mensual?'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->json('decision.reply');

        $this->assertStringContainsString('$80.000 COP', $reply);
    }

    public function test_pricing_without_plan_does_not_invent_price(): void
    {
        // Mensaje genérico de precio, sin plan_id ni nombre de plan.
        $reply = $this->analyze(['body' => '¿cuánto vale?'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->assertJsonPath('decision.should_generate_payment_link', false)
            ->json('decision.reply');

        $this->assertStringNotContainsString('$', $reply);
        $this->assertStringNotContainsString('80.000', $reply);
    }

    public function test_pricing_with_plan_id_does_not_generate_link_when_auto_execute_false(): void
    {
        $this->analyze(['body' => 'precio del plan mensual', 'plan_id' => $this->plan->id, 'auto_execute' => false])
            ->assertOk()
            ->assertJsonPath('executed', []);

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_pricing_deterministic_even_when_openai_omits_price(): void
    {
        // OpenAI responde SIN precio; Laravel lo reconstruye desde la DB.
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-test');
        config()->set('services.openai.api_key', 'sk-test');

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'intent' => SalesIntents::PRICING_QUESTION, 'confidence' => 0.9,
                'reply' => 'Nuestro plan mensual incluye muchos beneficios.', 'tools_requested' => ['reply'],
            ])]]]], 200),
            '*' => Http::response([], 200),
        ]);

        $reply = $this->analyze(['body' => 'cuánto vale el plan mensual?', 'plan_id' => $this->plan->id])
            ->assertOk()->json('decision.reply');

        $this->assertStringContainsString('$80.000 COP', $reply);
    }

    public function test_payment_link_request_still_works(): void
    {
        $this->analyze(['body' => 'mándame el link de pago'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PAYMENT_LINK_REQUEST)
            ->assertJsonPath('decision.should_generate_payment_link', true);
    }

    public function test_medical_still_escalates(): void
    {
        $this->analyze(['body' => 'tengo una lesión en la rodilla'])
            ->assertOk()
            ->assertJsonPath('decision.should_escalate', true);
    }

    public function test_do_not_contact_still_blocks(): void
    {
        $this->analyze(['body' => 'no me escriban', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_MARK_DNC);

        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
    }
}
