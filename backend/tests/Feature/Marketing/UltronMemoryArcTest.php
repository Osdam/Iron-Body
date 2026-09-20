<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\NoveltyGuard;
use App\Services\Marketing\Ultron\ReferenceResolver as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El arco del canario del 17-09, turno a turno, por el recorrido real
 * decide → commit. El modelo se sustituye por propuestas escritas a mano, de
 * modo que se pueda afirmar qué hace el CRM ante cada propuesta: la buena y
 * la que falló aquel día.
 *
 *   1. «quiero informacion de los planes»      → precio + beneficios, oferta de detalles
 *   2. «uy que bueno cuentame mas»             → referente: más info; repetir = 422
 *   3. «si por favor»                          → acepta «explicar cómo empezar»; derivar = 422
 *   4. «cuántos entrenadores tiene el gimnasio?» → pregunta abierta; se responde
 *   5. «no quiero alguien del equipo, contesta tú» → rechazo de humano; derivar = 422
 */
class UltronMemoryArcTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Asesoría semi personalizada con entrenador', 'Reserva de clases grupales']),
        ]);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h());
    }

    private function commit(MarketingMessage $m, array $proposal, ?string $token = null): TestResponse
    {
        $token ??= $this->decide($m)->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => [],
            ], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    private function memory(): array
    {
        return (array) $this->conversation->fresh()->memory;
    }

    private function outbound(): int
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count();
    }

    public function test_the_historical_arc_keeps_its_referent_and_never_hands_off(): void
    {
        // ── 1. «quiero informacion de los planes» ────────────────────────────
        $m1 = $this->inbound('hola quiero informacion de los planes', 'arc.1');
        $d1 = $this->decide($m1)->assertOk();
        $this->assertSame(R::NONE, $d1->json('context.resolved_reference.type'));
        $this->assertSame([], $d1->json('context.novelty.must_not_repeat.price_of_plans'));

        $this->commit($m1, [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro. Tenemos el {{PLAN_NAME}} por {{PLAN_PRICE}}: acceso ilimitado al gimnasio y reserva de clases grupales. ¿Quieres que te cuente más detalles?',
        ], $d1->json('decide_token'))->assertOk();

        $mem = $this->memory();
        $this->assertSame($this->plan->id, $mem['prices_delivered'][0]['plan_id']);
        $this->assertEqualsCanonicalizing(['Acceso ilimitado al gimnasio', 'Reserva de clases grupales'], array_column($mem['benefits_delivered'], 'benefit'));
        $this->assertSame([$this->plan->id], $mem['plans_discussed']);
        $this->assertSame('plan_details', $mem['last_agent_offer']['kind']);
        $this->assertSame('¿Quieres que te cuente más detalles?', $mem['last_agent_question']['text']);
        $this->assertSame('offer', $mem['pending_reference']['type']);

        // ── 2. «uy que bueno cuentame mas» ────────────────────────────────────
        $m2 = $this->inbound('uy que bueno cuentame mas', 'arc.2');
        $d2 = $this->decide($m2)->assertOk();
        $this->assertSame(R::MORE_INFO, $d2->json('context.resolved_reference.type'));
        $this->assertSame($this->plan->id, $d2->json('context.resolved_reference.plan_id'));
        $this->assertSame([$this->plan->id], $d2->json('context.novelty.must_not_repeat.price_of_plans'));
        $this->assertContains('Acceso ilimitado al gimnasio', $d2->json('context.novelty.must_not_repeat.benefits'));
        $this->assertContains('beneficio no contado del plan '.$this->plan->id.': Asesoría semi personalizada con entrenador', $d2->json('context.novelty.fresh_candidates'));

        // Información nueva, y una oferta concreta al final.
        $this->commit($m2, [
            'intent' => SalesIntents::GENERAL_INFO, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Además del acceso, incluye asesoría semi personalizada con entrenador: te acompañan en la ejecución y ajustan la rutina según avanzas. ¿Quieres que te explique cómo empezar?',
        ], $d2->json('decide_token'))->assertOk();

        $mem = $this->memory();
        $this->assertSame('explain_how_to_start', $mem['last_agent_offer']['kind']);
        $this->assertContains('Asesoría semi personalizada con entrenador', array_column($mem['benefits_delivered'], 'benefit'));
        $this->assertSame(R::MORE_INFO, $mem['last_reference_resolution']['type']);

        // ── 2b. «y que mas?» con el modelo repitiendo lo del turno 1 ─────────
        // Lo que pasó aquel día: volver a mandar precio y beneficios. Hoy el
        // texto no sale, la fase no avanza y el turno queda consumido.
        $m2b = $this->inbound('y que mas?', 'arc.2b');
        $this->commit($m2b, [
            'intent' => SalesIntents::GENERAL_INFO, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro. Tenemos el {{PLAN_NAME}} por {{PLAN_PRICE}}: acceso ilimitado al gimnasio y reserva de clases grupales. ¿Quieres que te cuente más detalles?',
        ])->assertOk()->assertJsonPath('outcome', 'no_reply')->assertJsonPath('blocked_reason', 'repeated_reply');
        $this->assertSame(2, $this->outbound(), 'la repetición no salió');
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase, 'la fase no avanza sobre un texto que no salió');
        $this->assertSame('explain_how_to_start', $this->memory()['last_agent_offer']['kind'], 'la oferta del turno 2 sigue vigente: nada nuestro salió después');

        // ── 3. «si por favor» ─────────────────────────────────────────────────
        $m3 = $this->inbound('si por favor', 'arc.3');
        $d3 = $this->decide($m3)->assertOk();
        $this->assertSame(R::ACCEPT_OFFER, $d3->json('context.resolved_reference.type'));
        $this->assertSame('explain_how_to_start', $d3->json('context.resolved_reference.offer_kind'));
        $this->assertNotContains(P::HUMAN_HANDOFF, $d3->json('allowed_transitions'), 'nadie pidió un humano');

        // Aquel día: «Te conecto con alguien del equipo para que te explique cómo empezar». Hoy: 422.
        $this->commit($m3, [
            'intent' => SalesIntents::HUMAN_REQUEST, 'next_state' => P::HUMAN_HANDOFF,
            'reply_draft' => 'Listo. Te conecto con alguien del equipo para que te explique cómo empezar.',
        ], $d3->json('decide_token'))->assertStatus(422)->assertJsonPath('code', 'unauthorized_handoff');

        // Lo correcto: explicar cómo empezar, sin volver a presentar el plan.
        $this->commit($m3, [
            'intent' => SalesIntents::GENERAL_INFO, 'next_state' => P::CLOSING, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Perfecto. Para empezar vienes con tu documento, te hacen la valoración inicial y el equipo confirma el medio de pago al final. ¿Qué día te queda bien pasar?',
        ])->assertOk();

        $mem = $this->memory();
        $this->assertSame(R::ACCEPT_OFFER, $mem['last_reference_resolution']['type']);
        $this->assertContains('how_to_start', array_column($mem['facts_delivered'], 'key'));
        $this->assertNull($mem['last_agent_offer'], 'la oferta aceptada deja de estar pendiente');

        // ── 4. «cuántos entrenadores tiene el gimnasio?» ──────────────────────
        $m4 = $this->inbound('tienes informacion de cuantos entrenadores tiene el gimnasio?', 'arc.4');
        $d4 = $this->decide($m4)->assertOk();
        $this->assertContains('cuantos entrenadores hay', $d4->json('context.novelty.fresh_candidates'));
        $this->assertNotContains(P::HUMAN_HANDOFF, $d4->json('allowed_transitions'));

        // Aquel día: «esa info la tiene el equipo humano. Te conecto». Hoy: 422 por el texto.
        $this->commit($m4, [
            'intent' => SalesIntents::GENERAL_INFO,
            'reply_draft' => 'Tranquilo, esa info la tiene el equipo humano. Te conecto con alguien para que te cuente bien todo.',
        ], $d4->json('decide_token'))->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF);
        $this->assertSame(3, $this->outbound());

        // ── 5. «no quiero alguien del equipo, contesta tú» ───────────────────
        // La pregunta 4 sigue sin respuesta nuestra: la memoria lo sabe sin haberlo guardado.
        $m5 = $this->inbound('no quiero alguien del equipo, contesta tú', 'arc.5');
        $d5 = $this->decide($m5)->assertOk();
        $this->assertSame(R::REFUSE_HUMAN, $d5->json('context.resolved_reference.type'));
        $this->assertSame('lead_verbatim', $d5->json('context.resolved_reference.unresolved_question.source'), 'texto de la persona: cita, no hecho');
        $this->assertStringContainsString('cuantos entrenadores', $d5->json('context.resolved_reference.unresolved_question.text'));
        $this->assertStringContainsString('cuantos entrenadores', $d5->json('context.memory.structured.unresolved_question.text'));
        $this->assertNotContains(P::HUMAN_HANDOFF, $d5->json('allowed_transitions'), 'rechazar a una persona no es pedirla');

        $this->commit($m5, [
            'intent' => SalesIntents::HUMAN_REQUEST, 'next_state' => P::HUMAN_HANDOFF,
            'reply_draft' => 'Te entiendo. ¿Quieres que te pase con alguien del equipo que te pueda contar?',
        ], $d5->json('decide_token'))->assertStatus(422)->assertJsonPath('code', 'unauthorized_handoff');

        $this->commit($m5, [
            'intent' => SalesIntents::GENERAL_INFO,
            'reply_draft' => 'Claro, te respondo yo: tenemos cuatro entrenadores de planta, todos certificados, y uno de ellos te hace la valoración cuando llegas.',
        ])->assertOk();

        // ── Cierre del arco ───────────────────────────────────────────────────
        $mem = $this->memory();
        $this->assertContains('trainers_count', array_column($mem['facts_delivered'], 'key'));
        $this->assertSame(4, $this->outbound(), 'cuatro respuestas salieron, ninguna repetida, ninguna derivó');
        $this->assertNotSame(P::HUMAN_HANDOFF, $this->conversation->fresh()->commercial_phase);
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);

        // Repetición material: ninguna salida es casi copia de otra.
        $bodies = MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', 'outbound')->orderBy('id')->pluck('body')->all();
        $guard = app(NoveltyGuard::class);
        for ($i = 0; $i < count($bodies); $i++) {
            for ($j = $i + 1; $j < count($bodies); $j++) {
                $this->assertLessThan(0.5, $guard->similarity($bodies[$i], $bodies[$j]), "salidas {$i} y {$j} se parecen demasiado");
            }
        }
        // Y la siguiente relectura de la memoria ya no ve pregunta pendiente.
        $this->assertNull($this->decide($this->inbound('vale', 'arc.6'))->json('context.memory.structured.unresolved_question'));
    }

    /** Un turno sin respuesta nuestra deja huella de a qué se refería la persona. */
    public function test_a_silent_turn_still_records_the_reference(): void
    {
        $m = $this->inbound('No me vuelvan a escribir', 'arc.dnc');
        $this->commit($m, ['next_state' => P::DO_NOT_CONTACT, 'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_MARK_DNC], 'reply_draft' => 'Listo, no te escribimos mas.'])
            ->assertOk()->assertJsonPath('outcome', 'no_reply');

        $mem = $this->memory();
        $this->assertSame(R::NONE, $mem['last_reference_resolution']['type']);
        $this->assertNotNull($mem['updated_at']);
    }
}
