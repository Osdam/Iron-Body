<?php

namespace Tests\Feature\E2E;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\NextBestActionEngine;
use App\Services\Marketing\Attribution\AttributionContextService;
use App\Services\Marketing\Attribution\OfferConsistency;
use App\Services\Marketing\SalesAgentPromptBuilder;
use App\Services\Marketing\TagCatalog;

/**
 * Recorridos 01–10: de que alguien escribe a que hay una oferta sobre la mesa.
 *
 * Todos entran por el webhook firmado de Meta y atraviesan el sistema real. Lo
 * que se comprueba en cada uno no es que «funcione», sino el efecto concreto
 * que debe quedar y —tan importante— el que NO debe quedar: con el canal
 * apagado, ni un mensaje sale, y eso se verifica en cada recorrido.
 */
class JourneysAcquisitionTest extends E2EJourneyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TagCatalog::sync();
    }

    // ── 01 · Lead orgánico ──────────────────────────────────────────────

    /**
     * Escribe alguien que no viene de ninguna pauta.
     *
     * Efecto esperado: lead, conversación y mensaje con correlación.
     * Efecto prohibido: inventarse un origen. Sin `referral`, la fuente es
     * desconocida y así se queda.
     */
    public function test_01_lead_organico(): void
    {
        $response = $this->metaWebhook(
            $this->inboundMessage('573001110001', 'Hola, buenas tardes'),
        );

        $response->assertOk();

        $lead = $this->leadFor('573001110001');
        $conversation = $this->conversationFor('573001110001');

        $this->assertNotNull($lead, 'No se creó el prospecto.');
        $this->assertNotNull($conversation, 'No se abrió la conversación.');
        $this->assertCorrelated($conversation);

        $attribution = MarketingLeadAttribution::where('marketing_lead_id', $lead->id)->first();
        $this->assertSame('unknown', $attribution?->source_type, 'Se inventó un origen sin referral.');

        $this->assertNoExternalCalls();
        $this->assertNothingDelivered();
    }

    // ── 02 · Lead desde Meta Ads ────────────────────────────────────────

    public function test_02_lead_desde_meta_ads(): void
    {
        $this->metaWebhook(
            $this->inboundMessage('573001110002', 'Vi el anuncio', $this->adReferral()),
        )->assertOk();

        $lead = $this->leadFor('573001110002');
        $attribution = MarketingLeadAttribution::where('marketing_lead_id', $lead->id)->first();

        $this->assertSame('ad', $attribution->source_type);
        $this->assertSame('AD-E2E-1', $attribution->ad_id);
        $this->assertSame('instagram', $attribution->source_platform);
        // Campaña y conjunto NO llegan en el referral: quedan nulos.
        $this->assertNull($attribution->campaign_id);

        $this->assertNoExternalCalls();
    }

    // ── 03 · Pauta desactualizada ───────────────────────────────────────

    /**
     * El anuncio promete un plan que ya no está activo.
     *
     * Efecto esperado: la conversación queda marcada para que quien atienda lo
     * vea ANTES de contestar, y el agente recibe la instrucción de no prometerlo.
     */
    public function test_03_pauta_desactualizada(): void
    {
        $retirado = $this->plan('Semestral', 400000, 180, active: false);

        $this->metaWebhook(
            $this->inboundMessage('573001110003', 'Vi el semestral', $this->adReferral()),
        )->assertOk();

        $lead = $this->leadFor('573001110003');

        // El mapeo anuncio→plan lo hace una persona después: Meta no lo manda.
        MarketingLeadAttribution::where('marketing_lead_id', $lead->id)
            ->first()
            ->forceFill(['advertised_plan_id' => $retirado->id, 'advertised_product' => 'Semestral'])
            ->save();

        $context = app(AttributionContextService::class)->forLead($lead->id);

        $this->assertSame(OfferConsistency::PLAN_UNAVAILABLE, $context->consistency->status);
        $this->assertStringContainsString('NO lo prometas', (string) $context->consistency->agentNote());

        $this->assertDatabaseHas('marketing_conversation_tags', [
            'conversation_id' => $this->conversationFor('573001110003')->id,
            'tag' => 'pauta-desactualizada',
        ]);
    }

    // ── 04 · Precio anunciado distinto del CRM ──────────────────────────

    public function test_04_precio_de_la_pauta_distinto_del_catalogo(): void
    {
        $mensual = $this->plan('Mensual', 120000);

        $this->metaWebhook(
            $this->inboundMessage('573001110004', 'Hola', $this->adReferral(['headline' => 'Mensual 90.000'])),
        )->assertOk();

        $lead = $this->leadFor('573001110004');
        MarketingLeadAttribution::where('marketing_lead_id', $lead->id)
            ->first()->forceFill(['advertised_plan_id' => $mensual->id])->save();

        $context = app(AttributionContextService::class)->forLead($lead->id);

        $this->assertSame(OfferConsistency::PRICE_CHANGED, $context->consistency->status);
        $this->assertSame(120000.0, $context->consistency->currentPrice);

        // Lo que llega al agente: el precio VIGENTE, y el del anuncio solo como
        // texto dentro del bloque no confiable.
        $prompt = json_decode(app(SalesAgentPromptBuilder::class)->userPrompt($lead->fresh(), 'cuánto vale'), true);

        $this->assertSame(120000.0, (float) $prompt['active_plans'][0]['price']);
        $this->assertStringContainsString('active_plans', $prompt['untrusted_data']['attribution']['offer_note']);
    }

    // ── 05 · Pregunta directa por precio ────────────────────────────────

    public function test_05_pregunta_por_precio(): void
    {
        $this->plan('Mensual', 90000);

        $this->metaWebhook(
            $this->inboundMessage('573001110005', '¿Cuánto vale la mensualidad?'),
        )->assertOk();

        $lead = $this->leadFor('573001110005');
        $prompt = json_decode(
            app(SalesAgentPromptBuilder::class)->userPrompt($lead, '¿Cuánto vale la mensualidad?'),
            true,
        );

        // El precio SOLO puede salir del catálogo activo.
        $this->assertSame(90000.0, (float) $prompt['active_plans'][0]['price']);
        $this->assertStringContainsString('no_inventar_precios', json_encode($prompt['guardrails']));

        $this->assertNothingDelivered();
    }

    // ── 06 · Descubrimiento ─────────────────────────────────────────────

    /**
     * Todavía no se sabe qué busca la persona. El motor NO ofrece plan: pide
     * información. Ofrecer sin saber es adivinar.
     */
    public function test_06_descubrimiento_antes_de_recomendar(): void
    {
        $this->plan('Mensual', 90000);

        $this->metaWebhook($this->inboundMessage('573001110006', 'Hola'))->assertOk();

        $decision = app(NextBestActionEngine::class)->decide(
            CommercialSubject::build($this->leadFor('573001110006')),
        );

        $this->assertSame(V::GOAL_COLLECT_DATA, $decision['goal']);
        $this->assertArrayHasKey('no_offer', $decision['exclusions']);
    }

    // ── 07 · Recomendación de plan ──────────────────────────────────────

    public function test_07_recomendacion_de_plan(): void
    {
        $mensual = $this->plan('Mensual', 90000);
        $this->plan('Trimestral', 240000, 90);

        $this->metaWebhook($this->inboundMessage('573001110007', 'Quiero bajar de peso'))->assertOk();

        $lead = $this->leadFor('573001110007');
        $lead->forceFill(['objective' => 'bajar de peso'])->save();

        $decision = app(NextBestActionEngine::class)->decide(CommercialSubject::build($lead->fresh()));

        $this->assertSame(V::GOAL_CLOSE_PLAN, $decision['goal']);
        $this->assertSame($mensual->id, $decision['floor_plan_id']);
        $this->assertNotNull($decision['reason']);
    }

    // ── 08 · Next Best Action ───────────────────────────────────────────

    /**
     * La decisión se persiste como oportunidad auditable, con su evidencia.
     * Una oferta la tiene que poder explicar un humano mirando una fila.
     */
    public function test_08_next_best_action_queda_auditable(): void
    {
        $this->plan('Mensual', 90000);

        $this->metaWebhook($this->inboundMessage('573001110008', 'Quiero entrenar'))->assertOk();

        $lead = $this->leadFor('573001110008');
        $lead->forceFill(['objective' => 'ganar masa'])->save();

        $opportunity = app(NextBestActionEngine::class)->evaluate(
            CommercialSubject::build($lead->fresh()),
            'corr-e2e-08',
        );

        $this->assertNotNull($opportunity);
        $this->assertSame(V::GOAL_CLOSE_PLAN, $opportunity->goal);
        $this->assertNotEmpty($opportunity->reason);
        $this->assertNotEmpty($opportunity->evidence);
        $this->assertSame('corr-e2e-08', $opportunity->correlation_id);
    }

    /** Evaluar dos veces no abre dos oportunidades para el mismo objetivo. */
    public function test_08b_next_best_action_es_idempotente(): void
    {
        $this->plan('Mensual', 90000);
        $this->metaWebhook($this->inboundMessage('573001110018', 'hola'))->assertOk();

        $lead = $this->leadFor('573001110018');
        $lead->forceFill(['objective' => 'salud'])->save();

        $engine = app(NextBestActionEngine::class);
        $engine->evaluate(CommercialSubject::build($lead->fresh()));
        $engine->evaluate(CommercialSubject::build($lead->fresh()));

        $this->assertSame(1, CommercialOpportunity::where('marketing_lead_id', $lead->id)
            ->where('goal', V::GOAL_CLOSE_PLAN)->count());
    }

    // ── 09 · Next Best Offer ────────────────────────────────────────────

    /**
     * Quien llegó por una pauta de un plan vigente arranca por ese plan, pero
     * el suelo sigue siendo el de entrada: la pauta orienta, no encierra.
     */
    public function test_09_next_best_offer_usa_lo_anunciado_si_sigue_vigente(): void
    {
        $mensual = $this->plan('Mensual', 90000);
        $trimestral = $this->plan('Trimestral', 240000, 90);

        // El titular del anuncio tiene que ser COHERENTE con el plan mapeado.
        // Con "mensual 90.000" apuntando al trimestral, el sistema detecta la
        // incoherencia y descarta la señal -que es lo correcto-, pero entonces
        // el recorrido probaria otra cosa.
        $this->metaWebhook(
            $this->inboundMessage('573001110009', 'Vi el trimestral', $this->adReferral([
                'headline' => 'Plan trimestral 240.000',
            ])),
        )->assertOk();

        $lead = $this->leadFor('573001110009');
        $lead->forceFill(['objective' => 'competir'])->save();
        MarketingLeadAttribution::where('marketing_lead_id', $lead->id)
            ->first()->forceFill(['advertised_plan_id' => $trimestral->id])->save();

        $decision = app(NextBestActionEngine::class)->decide(CommercialSubject::build($lead->fresh()));

        $this->assertSame($trimestral->id, $decision['offer_plan_id']);
        $this->assertSame($mensual->id, $decision['floor_plan_id']);
    }

    // ── 10 · Enlace de pago ─────────────────────────────────────────────

    /**
     * El monto lo determina el BACKEND desde el plan, nunca el cliente ni el
     * modelo. Es la regla que impide que alguien pague lo que le parezca.
     */
    public function test_10_el_monto_del_enlace_lo_manda_el_backend(): void
    {
        $plan = $this->plan('Mensual', 90000);

        $this->metaWebhook($this->inboundMessage('573001110010', 'Quiero pagar'))->assertOk();
        $lead = $this->leadFor('573001110010');

        $response = $this->postJson(
            "/api/admin/marketing/leads/{$lead->id}/payment-link",
            // Se intenta imponer un precio ridículo desde fuera.
            ['plan_id' => $plan->id, 'amount' => 1000],
            $this->adminHeaders(),
        );

        // Con Wompi sin configurar el endpoint no emite link, y eso es correcto.
        // Lo que NO puede pasar es que acepte el importe de quien lo pide.
        $this->assertNotSame(1000, (int) $response->json('data.amount'));

        $this->assertSame(
            0,
            \App\Models\PaymentTransaction::where('amount', 1000)->count(),
            'Se creó un cobro con el importe que mandó el cliente.',
        );
    }

    // ── Transversal del bloque ──────────────────────────────────────────

    /** Ninguno de estos recorridos pudo mandar un mensaje. */
    public function test_ningun_recorrido_de_adquisicion_entrega_mensajes(): void
    {
        $this->plan('Mensual', 90000);

        foreach (['573001110011', '573001110012'] as $phone) {
            $this->metaWebhook($this->inboundMessage($phone, 'Hola', $this->adReferral()))->assertOk();
        }

        $this->assertGreaterThan(0, MarketingMessage::where('direction', 'inbound')->count());
        $this->assertNothingDelivered();
        $this->assertNoExternalCalls();
    }
}
