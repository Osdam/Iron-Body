<?php

namespace Tests\Feature\Marketing;

use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\HermesSalesResponder;
use App\Services\Marketing\OpenAiSalesResponder;
use App\Services\Marketing\SalesAiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hermes como TERCERA implementación de AiSalesResponderInterface, junto a Fake
 * y OpenAI. Lo que fijan estas pruebas es que Hermes nunca puede empeorar el
 * sistema: si no está listo, si tarda o si responde mal, se degrada solo y el
 * prospecto recibe respuesta igual.
 */
class HermesResponderTest extends TestCase
{
    use RefreshDatabase;

    private const HERMES_URL = 'http://hermes.internal:8642';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
    }

    private function enableHermes(): void
    {
        config()->set('marketing.ai.driver', 'hermes');
        config()->set('marketing.ai.hermes.enabled', true);
        config()->set('marketing.ai.hermes.base_url', self::HERMES_URL);
    }

    private function hermesReturns(array $decision): void
    {
        Http::fake([
            'hermes.internal:8642/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($decision)]]],
            ], 200),
        ]);
    }

    // ---- Resolución del driver ----

    public function test_default_driver_is_unchanged_without_hermes(): void
    {
        config()->set('marketing.ai.driver', 'fake');

        $this->assertSame('fake', SalesAiConfig::effectiveDriver());
        $this->assertInstanceOf(FakeAiSalesResponder::class, app(AiSalesResponderInterface::class));
    }

    public function test_hermes_is_selected_only_when_fully_configured(): void
    {
        $this->enableHermes();

        $this->assertTrue(SalesAiConfig::hermesReady());
        $this->assertInstanceOf(HermesSalesResponder::class, app(AiSalesResponderInterface::class));
    }

    public function test_kill_switch_returns_previous_behaviour(): void
    {
        $this->enableHermes();
        config()->set('marketing.ai.hermes.enabled', false); // el kill switch

        $this->assertFalse(SalesAiConfig::hermesReady());
        $this->assertNotInstanceOf(HermesSalesResponder::class, app(AiSalesResponderInterface::class));
    }

    public function test_hermes_without_base_url_does_not_activate(): void
    {
        $this->enableHermes();
        config()->set('marketing.ai.hermes.base_url', '');

        $this->assertFalse(SalesAiConfig::hermesReady());
    }

    public function test_hermes_falls_back_to_openai_when_openai_is_ready(): void
    {
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-4.1');
        config()->set('services.openai.api_key', 'sk-test');

        $this->assertSame('openai', SalesAiConfig::effectiveDriver());
        $this->assertInstanceOf(OpenAiSalesResponder::class, app(AiSalesResponderInterface::class));
    }

    // ---- Comportamiento ----

    public function test_hermes_decision_is_used_when_it_answers_well(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'pricing_question', 'confidence' => 0.9,
            'temperature' => 'warm', 'sales_stage' => 'discovery',
            'should_reply' => true, 'reply' => 'Claro, te cuento.',
            'tools_requested' => ['reply'], 'safe_to_send' => true,
            'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('cuánto vale?');

        $this->assertSame('hermes', $decision['responder']);
        $this->assertSame('pricing_question', $decision['intent']);
    }

    public function test_hermes_timeout_degrades_without_throwing(): void
    {
        $this->enableHermes();
        Http::fake(['hermes.internal:8642/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $decision = app(AiSalesResponderInterface::class)->classify('cuánto vale?');

        $this->assertNotSame('hermes', $decision['responder'], 'Debe degradar, no responder como Hermes.');
        $this->assertContains('hermes_fallback_error', $decision['risk_flags']);
        $this->assertArrayHasKey('intent', $decision, 'El prospecto recibe decisión igual.');
    }

    public function test_hermes_http_error_degrades(): void
    {
        $this->enableHermes();
        Http::fake(['hermes.internal:8642/*' => Http::response('boom', 500)]);

        $decision = app(AiSalesResponderInterface::class)->classify('hola');

        $this->assertContains('hermes_fallback_error', $decision['risk_flags']);
    }

    public function test_hermes_invalid_json_degrades(): void
    {
        $this->enableHermes();
        Http::fake([
            'hermes.internal:8642/*' => Http::response([
                'choices' => [['message' => ['content' => 'esto no es json']]],
            ], 200),
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('hola');

        $this->assertContains('hermes_fallback_error', $decision['risk_flags']);
    }

    public function test_hermes_cannot_bypass_the_allowed_tools_whitelist(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'pricing_question', 'confidence' => 0.9,
            'temperature' => 'warm', 'sales_stage' => 'discovery', 'should_reply' => true,
            'reply' => 'ya te activo la membresía',
            // Herramientas inventadas que NO están en ALLOWED_TOOLS.
            'tools_requested' => ['activate_membership', 'approve_payment', 'reply'],
            'safe_to_send' => true, 'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('quiero pagar');

        // El validador ni siquiera propaga tools_requested: lo que pida Hermes no
        // sobrevive a Laravel. El orquestador deriva las herramientas por su cuenta.
        $this->assertArrayNotHasKey('tools_requested', $decision);
        $this->assertContains('forbidden_tool', $decision['risk_flags']);
    }

    public function test_hermes_attempt_to_activate_membership_is_blocked_and_escalated(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'high_intent_close', 'confidence' => 0.95,
            'temperature' => 'very_hot', 'sales_stage' => 'closing', 'should_reply' => true,
            'reply' => 'Listo, procedo a activar membresia y aprobar pago de una vez.',
            'tools_requested' => ['reply'], 'safe_to_send' => true,
            'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('quiero inscribirme');

        $this->assertContains('forbidden_action', $decision['risk_flags']);
        $this->assertTrue($decision['force_escalate']);
        $this->assertSame('forbidden_action_attempt', $decision['escalation_reason']);
    }

    /**
     * HUECO CONOCIDO del guardarraíl existente, no de Hermes: FORBIDDEN_SIGNALS
     * solo contiene infinitivos ("activar membresia"), así que una forma
     * conjugada ("te activo la membresía") NO dispara forbidden_action.
     *
     * El daño está acotado —la activación real es exclusiva del webhook Wompi
     * aprobado, así que el agente promete algo que no puede hacer— pero es una
     * promesa falsa al prospecto. Esta prueba fija el comportamiento ACTUAL para
     * que quede visible; ampliar la lista es una decisión aparte.
     */
    public function test_known_gap_conjugated_forms_are_not_caught(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'high_intent_close', 'confidence' => 0.95,
            'temperature' => 'very_hot', 'sales_stage' => 'closing', 'should_reply' => true,
            'reply' => 'Listo, te activo la membresía ya mismo.',
            'tools_requested' => ['reply'], 'safe_to_send' => true,
            'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('quiero inscribirme');

        $this->assertNotContains(
            'forbidden_action',
            $decision['risk_flags'],
            'Si esto empieza a fallar, el hueco de conjugación se cerró: actualiza la prueba.',
        );
    }

    public function test_hermes_cannot_invent_a_price_in_the_reply(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'pricing_question', 'confidence' => 0.9,
            'temperature' => 'warm', 'sales_stage' => 'discovery', 'should_reply' => true,
            'reply' => 'El plan mensual está en $45.000 COP con descuento especial.',
            'tools_requested' => ['reply'], 'safe_to_send' => true,
            'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $decision = app(AiSalesResponderInterface::class)->classify('cuánto vale?');

        $this->assertNull($decision['reply'], 'Un precio inventado se elimina de la respuesta.');
        $this->assertContains('price_in_reply', $decision['risk_flags']);
    }

    public function test_hermes_never_receives_the_lead_phone_in_clear(): void
    {
        $this->enableHermes();
        $this->hermesReturns([
            'ok' => true, 'intent' => 'greeting', 'confidence' => 0.5,
            'temperature' => 'cold', 'sales_stage' => 'discovery', 'should_reply' => true,
            'reply' => 'Hola', 'tools_requested' => ['reply'], 'safe_to_send' => true,
            'risk_flags' => [], 'extracted_fields' => [], 'missing_fields' => [],
        ]);

        $lead = \App\Models\MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead', 'status' => \App\Models\MarketingLead::STATUS_NEW,
        ]);

        app(AiSalesResponderInterface::class)->classify('hola', ['lead' => $lead]);

        Http::assertSent(function ($request) {
            return ! str_contains(json_encode($request->data()), '3150536026');
        });
    }
}
