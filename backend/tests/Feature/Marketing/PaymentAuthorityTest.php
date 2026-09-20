<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\SalesAgentGuardrailService;
use App\Services\Marketing\SalesAgentOrchestratorService;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesPaymentReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dos preguntas que durante meses tuvieron la misma respuesta y dejaron de
 * tenerla:
 *
 *   1. ¿Puede la pasarela cobrar de verdad?        → isProductionReady()
 *   2. ¿Autoriza el negocio a que lo ofrezca       → el flag
 *      una máquina, sola, sin que intervenga nadie?
 *
 * Mientras Wompi estuvo en sandbox las dos valían «no» y la diferencia daba
 * igual. El día que Wompi pasó a producción, la primera pasó a «sí» y el agente
 * habría empezado a repartir links de pago él solo, sin que nadie hubiera
 * decidido que podía. Estas pruebas fijan que hacen falta las dos.
 *
 * Y fijan la separación que importa: el flag gobierna la AUTOMATIZACIÓN. Un
 * administrador generando un link desde el CRM tiene sus propios permisos y no
 * pasa por aquí.
 */
class PaymentAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingLead $lead;

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

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    /** @param bool|null $flag null = no tocar el valor por defecto de config. */
    private function readiness(bool $wompiProduction, ?bool $flag = null): SalesPaymentReadinessService
    {
        config()->set('wompi.env', $wompiProduction ? 'production' : 'sandbox');
        if ($flag !== null) {
            config()->set('marketing.ultron.payment_links_enabled', $flag);
        }

        return app(SalesPaymentReadinessService::class);
    }

    private function analyze(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/analyze-message', array_merge([
            'marketing_lead_id' => $this->lead->id,
        ], $payload), ['Authorization' => 'Bearer '.self::SECRET]);
    }

    // ── A·B·C — la autoridad, en sus cuatro combinaciones ─────────────────────

    public function test_a_production_ready_without_permission_cannot_offer_links(): void
    {
        $r = $this->readiness(wompiProduction: true, flag: false);

        $this->assertTrue($r->isProductionReady(), 'La pasarela sí puede cobrar…');
        $this->assertFalse($r->canGenerateAutomaticLink(), '…pero nadie autorizó a la máquina.');
    }

    public function test_b_production_ready_with_permission_can_offer_links(): void
    {
        $r = $this->readiness(wompiProduction: true, flag: true);

        $this->assertTrue($r->isProductionReady());
        $this->assertTrue($r->canGenerateAutomaticLink());
    }

    public function test_c_permission_alone_is_not_enough(): void
    {
        $r = $this->readiness(wompiProduction: false, flag: true);

        $this->assertFalse($r->isProductionReady());
        $this->assertFalse($r->canGenerateAutomaticLink(), 'Un permiso no hace que la pasarela cobre.');
    }

    public function test_neither_is_also_false(): void
    {
        $r = $this->readiness(wompiProduction: false, flag: false);

        $this->assertFalse($r->canGenerateAutomaticLink());
    }

    // ── §10 — fail closed sin variable de entorno ─────────────────────────────

    /**
     * Sin `MARKETING_ULTRON_PAYMENT_LINKS_ENABLED` en el entorno, el valor
     * efectivo tiene que ser false. Se lee el fichero de configuración de verdad,
     * no el valor que otra prueba haya podido dejar puesto en memoria.
     */
    public function test_flag_defaults_to_false_without_env(): void
    {
        $fresh = require base_path('config/marketing.php');

        $this->assertArrayHasKey('ultron', $fresh);
        $this->assertFalse($fresh['ultron']['payment_links_enabled'], 'El default tiene que ser fail closed.');
        $this->assertFalse($fresh['ultron']['enabled']);

        // Y con Wompi productivo pero sin tocar el flag, sigue sin poder ofrecer.
        config()->set('marketing.ultron.payment_links_enabled', $fresh['ultron']['payment_links_enabled']);
        $this->assertFalse($this->readiness(wompiProduction: true)->canGenerateAutomaticLink());
    }

    // ── G — el prompt recibe el flag correcto ─────────────────────────────────

    public function test_g_prompt_flag_follows_the_service(): void
    {
        $builder = app(SalesAgentPromptBuilder::class);

        $this->readiness(wompiProduction: true, flag: false);
        $off = json_decode($builder->userPrompt($this->lead, 'hola'), true);
        $this->assertFalse($off['flags']['can_offer_link']);
        // El diagnóstico interno sigue diciendo la verdad sobre la pasarela.
        $this->assertSame(SalesPaymentReadinessService::STATE_PRODUCTION_READY, $off['flags']['payment_readiness']);

        $this->readiness(wompiProduction: true, flag: true);
        $on = json_decode($builder->userPrompt($this->lead, 'hola'), true);
        $this->assertTrue($on['flags']['can_offer_link']);
    }

    // ── F — el prompt no afirma nada estático sobre la infraestructura ────────

    /**
     * La regla que este test protege no es de estilo. El prompt afirmaba «Wompi
     * aún NO está productivo» mientras el contexto de la misma petición llevaba
     * `can_offer_link: true`: dos verdades opuestas en el mismo mensaje. Una
     * frase así envejece sin que nadie se entere.
     */
    public function test_f_prompt_has_no_static_infrastructure_claims(): void
    {
        $prompt = app(SalesAgentPromptBuilder::class)->systemPrompt();

        foreach (['Wompi', 'productivo', 'no está productivo', 'sí está productivo'] as $prohibida) {
            $this->assertStringNotContainsStringIgnoringCase(
                $prohibida,
                $prompt,
                "El prompt no puede afirmar un estado de infraestructura: «{$prohibida}».",
            );
        }

        // Lo que sí debe decir: que obedezca al flag.
        $this->assertStringContainsString('can_offer_link', $prompt);
        $this->assertStringContainsString('payment_readiness', $prompt);
    }

    // ── E — la decisión no puede colar la herramienta ─────────────────────────

    /**
     * Defensa determinista, no confianza en el prompt: una decisión que pide
     * `payment_link_send` con el permiso apagado pierde la herramienta al pasar
     * por el guardrail, y queda la marca de por qué.
     */
    public function test_e_guardrail_strips_payment_tool_when_not_allowed(): void
    {
        $this->readiness(wompiProduction: true, flag: false);

        $decision = app(SalesAgentGuardrailService::class)->apply([
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'should_reply' => true,
            'reply' => 'Claro, te ayudo con eso.',
            'should_generate_payment_link' => true,
            'recommended_action' => SalesIntents::ACTION_GENERATE_PAYMENT_LINK,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND, SalesIntents::TOOL_SCHEDULE_FOLLOWUP],
            'risk_flags' => [],
        ], $this->lead);

        $this->assertNotContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $decision['tools_requested']);
        // Lo que no era el link sigue ahí: se quita una herramienta, no todas.
        $this->assertContains(SalesIntents::TOOL_SCHEDULE_FOLLOWUP, $decision['tools_requested']);
        $this->assertFalse($decision['should_generate_payment_link']);
        $this->assertSame(SalesIntents::ACTION_REPLY, $decision['recommended_action']);
        $this->assertContains('payment_link_not_allowed', $decision['risk_flags']);
    }

    /** Con permiso, el guardrail no toca la herramienta. */
    public function test_guardrail_keeps_payment_tool_when_allowed(): void
    {
        $this->readiness(wompiProduction: true, flag: true);

        $decision = app(SalesAgentGuardrailService::class)->apply([
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'should_reply' => true,
            'reply' => 'Claro, te ayudo con eso.',
            'should_generate_payment_link' => true,
            'recommended_action' => SalesIntents::ACTION_GENERATE_PAYMENT_LINK,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            'risk_flags' => [],
        ], $this->lead);

        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $decision['tools_requested']);
        $this->assertNotContains('payment_link_not_allowed', $decision['risk_flags']);
    }

    // ── D — el ejecutor tampoco lo permite ────────────────────────────────────

    /**
     * La última barrera, la que importa si alguien no pasó por el guardrail:
     * `execute()` con la herramienta puesta a mano. No se genera transacción ni
     * link, y el motivo distingue «no hay permiso» de «la pasarela no está lista».
     */
    public function test_d_exec_payment_link_creates_nothing_without_permission(): void
    {
        Http::fake();
        $this->readiness(wompiProduction: true, flag: false);

        $conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        $executed = app(SalesAgentOrchestratorService::class)->execute(
            $this->lead,
            $conversation,
            [
                'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
                'reply' => null,
                'safe_to_send' => false,
                'should_send_message' => false,
            ],
            $this->plan,
        );

        $link = collect($executed)->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('deferred_to_human', $link['status']);
        // El motivo NO puede ser «wompi_not_production»: mandaría a revisar la
        // pasarela, que está perfectamente.
        $this->assertSame('automatic_links_disabled', $link['reason']);

        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    /** Con la pasarela en sandbox el motivo sigue siendo el de siempre. */
    public function test_exec_payment_link_reason_still_points_at_wompi_in_sandbox(): void
    {
        Http::fake();
        $this->readiness(wompiProduction: false, flag: true);

        $conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        $executed = app(SalesAgentOrchestratorService::class)->execute(
            $this->lead, $conversation,
            ['tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND], 'reply' => null,
                'safe_to_send' => false, 'should_send_message' => false],
            $this->plan,
        );

        $link = collect($executed)->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND);
        $this->assertSame('wompi_not_production', $link['reason']);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    // ── El recorrido completo, de punta a punta ───────────────────────────────

    public function test_payment_intent_does_not_produce_a_link_without_permission(): void
    {
        Http::fake();
        config()->set('wompi.env', 'production');
        config()->set('marketing.ultron.payment_links_enabled', false);

        $res = $this->analyze([
            'body' => 'quiero pagar el mensual', 'plan_id' => $this->plan->id, 'auto_execute' => true,
        ])->assertOk();

        $res->assertJsonPath('decision.should_generate_payment_link', false)
            ->assertJsonPath('decision.recommended_action', SalesIntents::ACTION_REPLY)
            // Wompi está listo, pero el agente no puede ofrecerlo: se marca para
            // el equipo igual que cuando la pasarela no estaba disponible.
            ->assertJsonPath('decision.needs_staff_review', true);

        $this->assertNotContains(
            SalesIntents::TOOL_PAYMENT_LINK_SEND,
            $res->json('decision.tools_requested'),
        );
        $this->assertNull(collect($res->json('executed'))->firstWhere('tool', SalesIntents::TOOL_PAYMENT_LINK_SEND));

        // La respuesta no menciona ningún link y no se creó transacción alguna.
        $reply = (string) $res->json('decision.reply');
        $this->assertStringNotContainsString('http', $reply);
        $this->assertStringNotContainsStringIgnoringCase('link', $reply);
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    // ── H — el camino humano no se toca ───────────────────────────────────────

    /**
     * El flag es de la automatización. El endpoint interno de link de pago tiene
     * su propio guardrail (`SalesPaymentGuardrailService`) y no consulta la
     * autoridad del agente: con el permiso apagado sigue funcionando igual.
     */
    /**
     * Este caso afirmaba lo contrario —«el permiso del agente no gobierna este
     * camino»— y por eso la bandera apagada no era una bandera: bastaba con
     * llamar a la ruta interna directamente. No era un flujo humano: el secreto
     * de automatización identifica una MÁQUINA, la comparta quien la comparta.
     *
     * Desde RC-2 la bandera manda sobre todos los orígenes, así que lo que se
     * fija aquí es que este camino tampoco acuña: 403, sin fila de cobro y sin
     * membresía. Encender la bandera lo devuelve, y eso lo cubre
     * {@see PaymentLinkHardGateTest}.
     */
    public function test_h_the_direct_internal_route_is_no_exception_to_the_flag(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        config()->set('wompi.env', 'production');
        config()->set('marketing.ultron.payment_links_enabled', false);

        $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id,
            'plan_id' => $this->plan->id,
        ], ['Authorization' => 'Bearer '.self::SECRET])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'payment_links_disabled');

        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
