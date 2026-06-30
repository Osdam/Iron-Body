<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mejora del agente comercial: clasificación rica, scoring 0-100, etapa del lead,
 * objeciones, memoria, escalado humano y gate de pago productivo. Mensajes
 * simulados del brief. Determinista (responder fake), META off, NUNCA activa
 * membresía.
 */
class SalesAgentScenariosTest extends TestCase
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

    // ── Mensajes simulados del brief ──────────────────────────────────────────

    public function test_precio_is_pricing_question(): void
    {
        $this->analyze(['body' => 'precio'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->assertJsonPath('decision.lead_stage', SalesIntents::LEAD_STAGE_INTERESTED)
            ->assertJsonPath('decision.should_generate_payment_link', false);
    }

    public function test_precio_quotes_monthly_plan_without_link_in_sandbox(): void
    {
        // Plan con beneficios reales (fuente de verdad = DB).
        $this->plan->update(['benefits' => json_encode([
            'acceso al gimnasio',
            'zona de pesas y maquinaria',
            'asesoría semi personalizada con entrenador de planta',
        ])]);
        Plan::create(['name' => 'Plan Trimestral', 'price' => 200000, 'duration_days' => 90, 'active' => true]);
        // Renombra el plan mensual al nombre esperado por el negocio.
        $this->plan->update(['name' => 'Plan Mensual']);

        $reply = $this->analyze(['body' => 'precio'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION)
            ->assertJsonPath('decision.payment_readiness', 'sandbox_pending')
            ->json('decision.reply');

        // Cotiza el plan mensual real, con beneficios, SIN ofrecer link.
        $this->assertStringContainsString('Plan Mensual', $reply);
        $this->assertStringContainsString('$80.000 COP', $reply);
        $this->assertStringContainsString('asesoría semi personalizada con entrenador de planta', $reply);
        $this->assertStringNotContainsStringIgnoringCase('link', $reply);
        $this->assertStringContainsString('otros planes', $reply);
    }

    public function test_pricing_keyword_overrides_historical_goal(): void
    {
        // Simula el bug real: OpenAI, por el historial ("perder grasa"), clasifica
        // "precio" como goal_fat_loss. Laravel DEBE forzar pricing_question.
        config()->set('marketing.ai.driver', 'openai');
        config()->set('marketing.ai.openai.enabled', true);
        config()->set('marketing.ai.openai.model', 'gpt-test');
        config()->set('services.openai.api_key', 'sk-test');

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'intent' => SalesIntents::GOAL_FAT_LOSS, 'confidence' => 0.9,
                'reply' => 'Para perder grasa te recomiendo...', 'tools_requested' => ['reply'],
            ])]]]], 200),
            '*' => Http::response([], 200),
        ]);

        $res = $this->analyze(['body' => 'precio'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICING_QUESTION);

        // El objetivo histórico se conserva como extracted_fields.goal.
        $this->assertSame('fat_loss', $res->json('decision.extracted_fields.goal'));
        // Y la respuesta NO ofrece link (sandbox) ni menciona "perder grasa" como cierre.
        $reply = $res->json('decision.reply');
        $this->assertStringNotContainsStringIgnoringCase('link', (string) $reply);
    }

    public function test_quiero_bajar_barriga_is_goal_fat_loss_and_remembers_objective(): void
    {
        $res = $this->analyze(['body' => 'quiero bajar barriga'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::GOAL_FAT_LOSS)
            ->assertJsonPath('decision.lead_stage', SalesIntents::LEAD_STAGE_INFORMED);

        // El score es 0-100 y mayor que cero para un objetivo declarado.
        $score = $res->json('decision.lead_score');
        $this->assertIsInt($score);
        $this->assertGreaterThan(0, $score);
        $this->assertLessThanOrEqual(100, $score);

        // Memoria: objetivo persistido en lead y conversación + resumen.
        $this->assertSame('fat_loss', $this->lead->fresh()->objective);
        $conversation = MarketingConversation::where('lead_id', $this->lead->id)->firstOrFail();
        $this->assertSame('fat_loss', $conversation->detected_objective);
        $this->assertSame(SalesIntents::GOAL_FAT_LOSS, $conversation->last_intent);
        $this->assertSame(SalesIntents::GOAL_FAT_LOSS, $conversation->primary_intent);
        $this->assertStringContainsString('bajar grasa', (string) $conversation->summary);
    }

    public function test_me_da_pena_empezar_is_beginner_fear_objection(): void
    {
        $reply = $this->analyze(['body' => 'me da pena empezar'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::BEGINNER_FEAR)
            ->assertJsonPath('decision.sales_stage', SalesIntents::STAGE_OBJECTION)
            ->json('decision.reply');

        // Respuesta empática (acompañamiento) y SIN precio inventado.
        $this->assertStringNotContainsString('$', (string) $reply);
        $this->assertStringContainsStringIgnoringCase('acompa', (string) $reply);
    }

    public function test_esta_caro_is_price_objection_and_schedules_followup(): void
    {
        $this->analyze(['body' => 'está caro', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PRICE_OBJECTION)
            ->assertJsonPath('decision.should_schedule_followup', true)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_REGISTER_OBJECTION);
    }

    public function test_quiero_pagar_el_mensual_is_payment_intent(): void
    {
        $this->analyze(['body' => 'quiero pagar el mensual'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::PAYMENT_LINK_REQUEST)
            ->assertJsonPath('decision.lead_stage', SalesIntents::LEAD_STAGE_READY_TO_PAY)
            ->assertJsonPath('decision.should_generate_payment_link', true);
    }

    public function test_donde_quedan_is_location_question(): void
    {
        $this->analyze(['body' => 'dónde quedan'])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::LOCATION_QUESTION)
            ->assertJsonPath('decision.lead_stage', SalesIntents::LEAD_STAGE_INFORMED);
    }

    public function test_quiero_hablar_con_alguien_escalates_to_human(): void
    {
        $res = $this->analyze(['body' => 'quiero hablar con alguien', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.intent', SalesIntents::HUMAN_REQUEST)
            ->assertJsonPath('decision.should_escalate', true)
            ->assertJsonPath('decision.lead_stage', SalesIntents::LEAD_STAGE_NEEDS_HUMAN)
            ->assertJsonPath('decision.should_generate_payment_link', false)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_ESCALATE_HUMAN);

        $this->assertSame('human_requested', $res->json('decision.escalation_reason'));
        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }

    public function test_complaint_escalates_and_does_not_close(): void
    {
        $this->analyze(['body' => 'esto es un pésimo servicio, una queja', 'auto_execute' => true])
            ->assertOk()
            ->assertJsonPath('decision.should_escalate', true)
            ->assertJsonPath('decision.should_generate_payment_link', false);

        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }

    // ── Gate de pago productivo ───────────────────────────────────────────────

    public function test_sandbox_wompi_never_generates_link_defers_to_human(): void
    {
        Http::fake();
        // Wompi en sandbox (NO_PRODUCTIVO) → NUNCA se genera/entrega link, ni
        // siquiera en dry_run. Un asesor comparte el medio de pago.
        $res = $this->analyze([
            'body' => 'quiero pagar el mensual', 'plan_id' => $this->plan->id, 'auto_execute' => true,
        ])->assertOk();

        // El reply sugerido NO ofrece un link: deriva a un asesor.
        $reply = $res->json('decision.reply');
        $this->assertStringNotContainsString('http', (string) $reply);
        $this->assertStringContainsStringIgnoringCase('asesor', (string) $reply);

        $exec = collect($res->json('executed'))->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('deferred_to_human', $exec['status']);
        $this->assertSame('wompi_not_production', $exec['reason']);

        // No se generó ninguna transacción de pago (no se entregó link sandbox).
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_production_wompi_prepares_link_without_activating_membership(): void
    {
        Http::fake();
        // Wompi PRODUCTIVO + META off → link preparado en dry_run (no entregado),
        // nunca activa membresía ni marca pago aprobado.
        config()->set('wompi.env', 'production');

        $res = $this->analyze([
            'body' => 'link de pago por favor', 'plan_id' => $this->plan->id, 'auto_execute' => true,
        ])->assertOk();

        $exec = collect($res->json('executed'))->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('executed', $exec['status']);
        $this->assertTrue($exec['dry_run']);
        $this->assertDatabaseHas('payment_transactions', ['provider' => 'wompi', 'method' => 'web_checkout']);
        $this->assertDatabaseCount('payments', 0);
    }
}
