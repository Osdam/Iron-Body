<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El cerrojo de derivación en `/ai/commit`, contra el recorrido real.
 *
 * Todo lo que llega desde n8n es una PROPUESTA. La pregunta de cada test es la
 * misma: si Strategist, Composer, Critic y el propio workflow se equivocan a la
 * vez y piden pasar la conversación a una persona, ¿qué hace el CRM? Y la
 * segunda, igual de importante: cuando la petición es legítima, ¿sigue pasando?
 */
class UltronHandoffHardGuardTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso'])]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION,
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h());
    }

    private function commit(MarketingMessage $m, array $proposal): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge([
                'strategy_goal' => 'close',
                'next_state' => P::RECOMMENDATION,
                'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento como empezar.',
                'recommended_plan_id' => null,
                'main_barrier' => null,
                'next_best_action' => 'explain_steps',
                'confidence' => 0.8,
                'tools_requested' => [],
            ], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    /** Nada cambió: ni mensaje, ni acción, ni revisión, ni fase, ni persona al mando. */
    private function assertNoSideEffects(): void
    {
        $c = $this->conversation->fresh();

        $this->assertSame(P::RECOMMENDATION, $c->commercial_phase, 'la fase no se movió');
        $this->assertFalse((bool) $c->staff_review_pending, 'no se pidió revisión');
        $this->assertFalse((bool) $c->human_takeover, 'nadie tomó el mando');
        $this->assertTrue((bool) $c->ai_enabled, 'la IA sigue encendida');
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), 'no salió nada');
        $this->assertSame(0, MarketingAiAction::count(), 'no quedó acción registrada');
        Http::assertNothingSent();
    }

    // ── Lo que se bloquea ─────────────────────────────────────────────────────

    /** La conversación real del canario: quería pagar, el modelo quiso derivar. */
    public function test_handoff_without_a_reason_is_refused_with_no_side_effects(): void
    {
        $m = $this->inbound('Listo, quiero pagar el mensual', 'g.01');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'reply_draft' => 'Listo. Te conecto con alguien del equipo para que te explique cómo empezar.'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unauthorized_handoff')
            ->assertJsonPath('detail.handoff_refusal', 'handoff_reason_missing');

        $this->assertNoSideEffects();
    }

    /** «Lo pidió» sin que lo haya pedido. */
    public function test_an_explicit_request_the_person_never_made_is_refused(): void
    {
        $m = $this->inbound('si por favor', 'g.02');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'human_handoff_requested' => true,
            'human_handoff_reason' => HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'human_handoff_evidence' => 'si por favor',
            'reply_draft' => 'Te comunico con un asesor ahora mismo.'])
            ->assertStatus(422)
            ->assertJsonPath('detail.handoff_refusal', 'handoff_not_corroborated');

        $this->assertNoSideEffects();
    }

    /** La negación cuenta: «no quiero hablar con una persona» no es pedirla. */
    public function test_a_negated_request_is_refused(): void
    {
        $m = $this->inbound('No quiero hablar con una persona, contesta tú', 'g.03');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'human_handoff_requested' => true,
            'human_handoff_reason' => HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'reply_draft' => 'Te comunico con un asesor ahora mismo.'])
            ->assertStatus(422)
            ->assertJsonPath('detail.handoff_refusal', 'handoff_not_corroborated');

        $this->assertNoSideEffects();
    }

    /** El modelo no puede vestir de «política» un turno comercial normal. */
    public function test_the_model_cannot_claim_a_backend_only_reason(): void
    {
        $m = $this->inbound('Cuantos entrenadores tienen?', 'g.04');

        foreach ([
            HumanHandoffAuthority::POLICY_REQUIRED_ESCALATION,
            HumanHandoffAuthority::CRITICAL_CAPABILITY_NOT_AVAILABLE,
            HumanHandoffAuthority::UNRESOLVABLE_PAYMENT_INCIDENT,
        ] as $motivo) {
            $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::GENERAL_INFO,
                'human_handoff_requested' => true, 'human_handoff_reason' => $motivo,
                'reply_draft' => 'Esa info la tiene el equipo. Te conecto con alguien para que te cuente.'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'unauthorized_handoff')
                ->assertJsonPath('detail.handoff_refusal', 'handoff_reason_not_proposable_by_model');
        }

        $this->assertNoSideEffects();
    }

    /** Un motivo fuera de la allowlist no llega ni al servicio: lo para el contrato. */
    public function test_an_invented_reason_does_not_pass_validation(): void
    {
        $m = $this->inbound('Cuantos entrenadores tienen?', 'g.05');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF,
            'human_handoff_requested' => true, 'human_handoff_reason' => 'customer_wants_to_pay',
            'reply_draft' => 'Te conecto con alguien.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proposal.human_handoff_reason']);

        $this->assertNoSideEffects();
    }

    /** La bandera sola, sin cambiar de fase, también pasa por el cerrojo. */
    public function test_the_requested_flag_alone_is_guarded_too(): void
    {
        $m = $this->inbound('quiero inscribirme', 'g.06');

        $this->commit($m, ['next_state' => P::RECOMMENDATION, 'human_handoff_requested' => true,
            'reply_draft' => 'Claro, te cuento como empezar.'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unauthorized_handoff');

        $this->assertNoSideEffects();
    }

    /** Y aunque la fase no cambie, el TEXTO que ofrece traspaso tampoco sale. */
    public function test_a_transfer_offer_in_an_ordinary_turn_is_stopped_by_the_outbound_guard(): void
    {
        $m = $this->inbound('quiero inscribirme', 'g.07');

        $this->commit($m, ['next_state' => P::RECOMMENDATION,
            'reply_draft' => '¿Quieres que te pase con alguien del equipo que te pueda contar?'])
            ->assertStatus(422)
            ->assertJsonPath('code', OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF);

        $this->assertNoSideEffects();
    }

    /** El menú de decide no ofrece derivar en un turno comercial. */
    public function test_decide_does_not_offer_handoff_on_an_ordinary_turn(): void
    {
        foreach (['quiero información de los planes', 'cómo pago?', 'quiero inscribirme', '80 mil está caro'] as $i => $texto) {
            $menu = $this->decide($this->inbound($texto, "g.08.{$i}"))->assertOk()->json('allowed_transitions');
            $this->assertNotContains(P::HUMAN_HANDOFF, $menu, "«{$texto}» no debería ofrecer derivar");
        }
    }

    // ── Lo que sigue pasando ──────────────────────────────────────────────────

    /** Petición real: el menú la ofrece, el commit la acepta y el token NO caduca. */
    public function test_a_corroborated_request_passes_and_the_token_stays_valid(): void
    {
        $m = $this->inbound('Pásame con un asesor, por favor', 'g.10');

        $this->assertContains(P::HUMAN_HANDOFF, $this->decide($m)->json('allowed_transitions'));

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'human_handoff_requested' => true,
            'human_handoff_reason' => HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW],
            'reply_draft' => 'Claro. Ya le aviso a alguien del equipo para que te escriba.'])
            ->assertOk();

        $c = $this->conversation->fresh();
        $this->assertSame(P::HUMAN_HANDOFF, $c->commercial_phase);
        $this->assertTrue((bool) $c->staff_review_pending);
        $this->assertSame(1, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }

    /**
     * Hallazgo del reviewer: la persona lo pidió, el menú lo ofreció, y el
     * modelo propuso derivar pero OLVIDÓ el motivo. Laravel corrobora por sí
     * mismo: la petición está en el texto, así que se deriva igual. Lo
     * contrario sería castigar con silencio a quien pidió hablar con alguien.
     */
    public function test_a_real_request_is_honoured_even_if_the_model_forgets_the_reason(): void
    {
        $m = $this->inbound('Quiero hablar con una persona, por favor', 'g.10b');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW],
            'reply_draft' => 'Claro. Ya le aviso a alguien del equipo para que te escriba.'])
            ->assertOk();

        $c = $this->conversation->fresh();
        $this->assertSame(P::HUMAN_HANDOFF, $c->commercial_phase);
        $this->assertTrue((bool) $c->staff_review_pending);
        $this->assertSame(1, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }

    /** Y al revés: el motivo del modelo no concede lo que el texto no concede. */
    public function test_the_model_reason_never_grants_more_than_the_text_does(): void
    {
        $m = $this->inbound('quiero pagar ya', 'g.10c');

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::HUMAN_REQUEST,
            'human_handoff_requested' => true,
            'human_handoff_reason' => HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
            'human_handoff_evidence' => 'quiero hablar con una persona',
            'reply_draft' => 'Te comunico con un asesor ahora mismo.'])
            ->assertStatus(422)->assertJsonPath('code', 'unauthorized_handoff');

        $this->assertNoSideEffects();
    }

    /**
     * El camino del critic fallido envía texto curado de Laravel sin pasar por
     * el guard. Si ese texto ofreciera un traspaso, tampoco sale: revisión y
     * silencio, nunca «te paso con alguien» en un turno normal.
     */
    public function test_a_curated_fallback_that_offers_a_transfer_is_not_sent(): void
    {
        $this->mock(SalesConversationReplyService::class, function ($mock) {
            $mock->shouldReceive('replyFor')->andReturn('Claro, te paso con alguien del equipo.');
            $mock->shouldIgnoreMissing();
        });

        $m = $this->inbound('quiero inscribirme', 'g.13');
        $token = $this->decide($m)->json('decide_token');

        $r = $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => ['strategy_goal' => 'close', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'borrador rechazado por el critic', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'explain_steps', 'confidence' => 0.8, 'tools_requested' => []],
            'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.2, 'notes' => 'robotico'],
        ], $this->h())->assertOk();

        $this->assertSame('NO_REPLY_AND_HANDOFF', $r->json('fallback_mode'));
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending);
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
    }

    /** Si Laravel ya marcó el lead, el modelo no tiene que justificar nada. */
    public function test_a_lead_laravel_marked_needs_human_can_be_handed_off_without_a_model_reason(): void
    {
        $this->lead->update(['status' => MarketingLead::STATUS_NEEDS_HUMAN]);
        $m = $this->inbound('ya pagué y no aparece', 'g.11');

        $this->assertContains(P::HUMAN_HANDOFF, $this->decide($m)->json('allowed_transitions'));

        $this->commit($m, ['next_state' => P::HUMAN_HANDOFF, 'intent' => SalesIntents::FRAUD_OR_PAYMENT_CLAIM,
            'tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW],
            'reply_draft' => 'Entiendo. Ya le paso tu caso al equipo para que lo revisen.'])
            ->assertOk();

        $this->assertSame(P::HUMAN_HANDOFF, $this->conversation->fresh()->commercial_phase);
    }

    /** Sin derivar, el turno comercial normal sigue saliendo como siempre. */
    public function test_an_ordinary_turn_is_unaffected(): void
    {
        $m = $this->inbound('quiero inscribirme', 'g.12');

        $this->commit($m, ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE,
            'reply_draft' => 'Perfecto. Para empezar vienes con tu documento y el equipo confirma el medio de pago al final.'])
            ->assertOk();

        $this->assertSame(1, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
        $this->assertSame(P::CLOSING, $this->conversation->fresh()->commercial_phase);
    }
}
