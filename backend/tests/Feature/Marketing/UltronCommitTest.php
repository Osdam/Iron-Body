<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `/ai/commit` — la única puerta por la que ULTRON ejecuta algo.
 *
 * Todo lo que llega es una SUGERENCIA, incluido el texto. Lo que estas pruebas
 * fijan es que ninguna sugerencia puede convertirse en una acción saltándose lo
 * que ya gobernaba al cerebro local: ni un precio inventado, ni una transición
 * ilegal, ni una respuesta a alguien que pidió que no le escriban.
 *
 * Y fijan la ventana de los quince segundos: entre `/decide` y `/commit` el
 * mundo cambia, y lo que vale es el estado de después.
 */
class UltronCommitTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

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

        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso completo']),
        ]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150536026', 'meta_user_id' => '573150536026',
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body = 'hola', ?string $wamid = 'wamid.T1'): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    /** Pide contexto de verdad: el token tiene que salir del propio endpoint. */
    private function decideFor(MarketingMessage $m): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->headers())->assertOk()->json();
    }

    private function payload(MarketingMessage $m, array $overrides = [], ?string $token = null): array
    {
        $token ??= $this->decideFor($m)['decide_token'];

        return array_replace_recursive([
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id ?? ('msg:'.$m->id),
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'collect_data',
                'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento. ¿Qué te gustaría lograr?',
                'recommended_plan_id' => null,
                'main_barrier' => null,
                'next_best_action' => 'ask_discovery',
                'confidence' => 0.82,
                'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $overrides);
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    // ── Camino feliz ──────────────────────────────────────────────────────────

    public function test_a_valid_proposal_is_executed_and_persisted(): void
    {
        Http::fake();
        $m = $this->inbound();

        $res = $this->commit($this->payload($m))->assertOk();

        $res->assertJsonPath('ok', true)
            ->assertJsonPath('outcome', 'dry_run')          // META off
            ->assertJsonPath('commercial_phase.from', P::NEW_LEAD)
            ->assertJsonPath('commercial_phase.to', P::GOAL_DISCOVERY);

        // La transición SOLO se persiste cuando el commit se acepta.
        $this->assertSame(P::GOAL_DISCOVERY, $this->conversation->fresh()->commercial_phase);
        // Y el CRM legado sigue coherente.
        $this->assertSame(SalesIntents::LEAD_STAGE_INFORMED, $this->conversation->fresh()->lead_stage);

        $accion = MarketingAiAction::where('idempotency_key', 'wamid.T1')->sole();
        $this->assertSame('inbound_message', $accion->source_type);
        $this->assertSame($m->id, (int) $accion->source_event_id);
        $this->assertSame('ultron', $accion->metadata['origin']);
        $this->assertSame(P::NEW_LEAD, $accion->metadata['previous_phase']);
        $this->assertSame(P::GOAL_DISCOVERY, $accion->metadata['next_phase']);

        // El saliente existe, es de la IA y no salió a Meta.
        $out = MarketingMessage::where('direction', 'outbound')->sole();
        $this->assertSame(MarketingMessage::SENDER_AI, $out->sender_type);
        $this->assertSame('ultron', $out->metadata['origin']);
        Http::assertNothingSent();
    }

    /**
     * El cerrojo del canario, en la puerta de SALIDA.
     *
     * Que no nazca un evento de otra conversación es el camino normal, pero no
     * el único: quien tenga el secreto —o ejecute el workflow a mano, o pinche
     * datos en n8n— puede llamar al commit con cualquier `conversation_id`, y
     * de aquí sale un WhatsApp de verdad. Con el canario puesto, esa llamada se
     * queda sin ejecutar y sin dejar rastro en la conversación ajena.
     */
    public function test_with_the_canary_set_a_commit_for_another_conversation_is_refused(): void
    {
        Http::fake();
        $m = $this->inbound();

        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id + 1000);

        $this->commit($this->payload($m))
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_canary_conversation');

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count(), 'no sale ni un mensaje');
        $this->assertSame(0, MarketingAiAction::count(), 'ni queda una fila en el historial de esa conversación');
        Http::assertNothingSent();
    }

    /** Y la conversación del canario sí ejecuta, que es de lo que se trata. */
    public function test_with_the_canary_set_to_this_conversation_the_commit_runs(): void
    {
        Http::fake();
        $m = $this->inbound();

        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);

        $this->commit($this->payload($m))->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_requires_the_internal_bearer(): void
    {
        $m = $this->inbound();
        $p = $this->payload($m);

        $this->postJson('/api/internal/marketing/ai/commit', $p)->assertStatus(401);
    }

    // ── Idempotencia ──────────────────────────────────────────────────────────

    public function test_a_duplicate_commit_is_rejected_with_409(): void
    {
        Http::fake();
        $m = $this->inbound();
        $p = $this->payload($m);

        $primera = $this->commit($p)->assertOk();
        $id = $primera->json('ai_action_id');

        $this->commit($p)->assertStatus(409)
            ->assertJsonPath('code', 'already_committed')
            ->assertJsonPath('detail.ai_action_id', $id);

        // Un solo saliente: el reintento no escribió al cliente dos veces.
        $this->assertSame(1, MarketingMessage::where('direction', 'outbound')->count());
    }

    /**
     * La restricción UNIQUE no es decorativa: es lo que hace que dos procesos
     * que pasaron la comprobación lógica a la vez no puedan escribir los dos.
     */
    public function test_the_unique_constraint_backs_the_logical_check(): void
    {
        $m = $this->inbound();

        MarketingAiAction::create([
            'lead_id' => $this->lead->id, 'conversation_id' => $this->conversation->id,
            'action_type' => 'reply', 'status' => 'executed',
            'source_type' => 'inbound_message', 'source_event_id' => $m->id,
            'idempotency_key' => 'wamid.T1',
        ]);

        $this->commit($this->payload($m))->assertStatus(409)
            ->assertJsonPath('code', 'already_committed');
    }

    // ── Transiciones ──────────────────────────────────────────────────────────

    public function test_an_illegal_transition_is_rejected(): void
    {
        Http::fake();
        $this->conversation->update(['commercial_phase' => P::CLOSING]);
        $m = $this->inbound();

        $this->commit($this->payload($m, ['proposal' => ['next_state' => P::NEW_LEAD]]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'illegal_transition')
            ->assertJsonPath('detail.proposed', P::NEW_LEAD)
            ->assertJsonPath('detail.from', P::CLOSING);

        // La fase no se movió y no salió nada.
        $this->assertSame(P::CLOSING, $this->conversation->fresh()->commercial_phase);
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_an_unknown_phase_is_rejected_by_validation(): void
    {
        $m = $this->inbound();

        $this->commit($this->payload($m, ['proposal' => ['next_state' => 'FASE_INVENTADA']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proposal.next_state');
    }

    // ── Herramientas ──────────────────────────────────────────────────────────

    /**
     * Lo que no está en el vocabulario es un error de forma (422). `payment_link_send`
     * SÍ está desde el PUNTO 7: sin permiso vigente no se rechaza el turno, se
     * descarta la herramienta (tools_rejected) y no se genera nada.
     */
    public function test_a_forbidden_tool_is_rejected_by_validation(): void
    {
        $m = $this->inbound();

        foreach (['schedule_followup', 'human_takeover'] as $desconocida) {
            $this->commit($this->payload($m, ['proposal' => ['tools_requested' => [$desconocida]]]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('proposal.tools_requested.0');
        }

        $r = $this->commit($this->payload($m, ['proposal' => ['tools_requested' => ['payment_link_send']]]))->assertOk();
        $this->assertContains('payment_link_send', $r->json('applied.tools_rejected'), 'sin permiso, la herramienta se descarta, no el turno');
        $this->assertNotContains('payment_link_send', $r->json('applied.tools_executed'));
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_allowed_v1_tools_are_executed(): void
    {
        Http::fake();
        $m = $this->inbound();

        $this->commit($this->payload($m, [
            'proposal' => ['tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW]],
        ]))->assertOk()->assertJsonPath('applied.tools_executed.0', SalesIntents::TOOL_STAFF_REVIEW);

        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending);
    }

    public function test_mark_do_not_contact_is_honoured(): void
    {
        Http::fake();
        $m = $this->inbound('no me vuelvan a escribir');

        $this->commit($this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
                'next_state' => P::DO_NOT_CONTACT,
                'tools_requested' => [SalesIntents::TOOL_MARK_DNC],
            ],
        ]))->assertOk()
            // No se le contesta —eso sería insistir— pero el opt-out SÍ se registra.
            ->assertJsonPath('outcome', 'no_reply')
            ->assertJsonPath('applied.tools_executed.0', SalesIntents::TOOL_MARK_DNC);

        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
        $this->assertSame(MarketingLead::CONSENT_DENIED, $this->lead->fresh()->consent_status);
        $this->assertSame(P::DO_NOT_CONTACT, $this->conversation->fresh()->commercial_phase);
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── Campos que n8n no puede expresar ──────────────────────────────────────

    /**
     * No se ignoran en silencio: se rechaza la petición entera. Si el workflow
     * empieza a mandar un teléfono o un precio, quiero enterarme la primera vez.
     */
    public function test_forged_sender_data_is_rejected(): void
    {
        $m = $this->inbound();

        foreach ([
            ['phone' => '3001234567'],
            ['to' => '573150536026'],
            ['phone_number_id' => '1223144674217717'],
            ['provider_message_id' => 'wamid.FAKE'],
        ] as $inyeccion) {
            $res = $this->commit(array_merge($this->payload($m), $inyeccion));
            $res->assertStatus(422)->assertJsonPath('code', 'unexpected_fields');
        }

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_price_and_payment_fields_are_rejected(): void
    {
        $m = $this->inbound();

        $res = $this->commit($this->payload($m, ['proposal' => ['price' => 45000]]));

        $res->assertStatus(422)->assertJsonPath('code', 'unexpected_fields');
        $this->assertContains('proposal.price', $res->json('fields'));
    }

    // ── Precios ───────────────────────────────────────────────────────────────

    public function test_an_invented_price_in_the_draft_is_rejected(): void
    {
        Http::fake();
        $m = $this->inbound();

        foreach (['El plan cuesta $45.000 COP', 'Son 120000 al mes', 'Quedan 95.000 pesos'] as $draft) {
            $this->commit($this->payload($m, ['proposal' => ['reply_draft' => $draft]]))
                ->assertStatus(422)
                ->assertJsonPath('code', OutboundContentGuard::CODE_INVENTED_PRICE);
        }

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
        Http::assertNothingSent();
    }

    /**
     * El orden que hace que las dos reglas convivan: validar con el marcador
     * puesto, sustituir después. Si se hiciera al revés, la propia expresión
     * regular mataría el precio bueno.
     */
    public function test_the_price_placeholder_is_resolved_from_the_database(): void
    {
        Http::fake();
        $m = $this->inbound('cuánto vale?');

        $res = $this->commit($this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::PRICING_QUESTION,
                'next_state' => P::QUALIFICATION,
                'reply_draft' => 'El {{PLAN_NAME}} está en {{PLAN_PRICE}} por {{PLAN_DURATION}}. ¿Te sirve?',
                'recommended_plan_id' => $this->plan->id,
            ],
        ]))->assertOk();

        $final = $res->json('reply_final');
        $this->assertStringContainsString('$80.000 COP', $final);
        $this->assertStringContainsString('Mensual', $final);
        $this->assertStringContainsString('un mes', $final);
        $this->assertStringNotContainsString('{{', $final);

        // Trazabilidad: de dónde salió la cifra.
        $res->assertJsonPath('price_source.plan_id', $this->plan->id)
            ->assertJsonPath('price_source.price', 80000)
            ->assertJsonPath('price_source.resolved_at_commit', true);

        // Y lo que se envió lleva el precio real.
        $out = MarketingMessage::where('direction', 'outbound')->sole();
        $this->assertStringContainsString('$80.000 COP', $out->body);
    }

    public function test_an_unknown_placeholder_is_rejected(): void
    {
        $m = $this->inbound();

        $res = $this->commit($this->payload($m, [
            'proposal' => ['reply_draft' => 'Te doy un {{DESCUENTO_SECRETO}} especial.'],
        ]))->assertStatus(422);

        $res->assertJsonPath('code', 'unknown_placeholder');
        $this->assertContains('DESCUENTO_SECRETO', $res->json('detail.unknown'));
    }

    public function test_a_placeholder_without_a_plan_is_rejected(): void
    {
        $m = $this->inbound();

        $this->commit($this->payload($m, [
            'proposal' => ['reply_draft' => 'Está en {{PLAN_PRICE}}.', 'recommended_plan_id' => null],
        ]))->assertStatus(422)->assertJsonPath('code', 'plan_required_for_placeholder');
    }

    /** El plan se desactiva entre decide y commit: no se recalcula en silencio. */
    public function test_an_inactive_plan_is_rejected(): void
    {
        Http::fake();
        $m = $this->inbound('cuánto vale?');
        $payload = $this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::PRICING_QUESTION,
                'next_state' => P::QUALIFICATION,
                'reply_draft' => 'El mensual está en {{PLAN_PRICE}}.',
                'recommended_plan_id' => $this->plan->id,
            ],
        ]);

        $this->plan->update(['active' => false]);

        $this->commit($payload)->assertStatus(422)->assertJsonPath('code', 'plan_inactive');
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── Lo que cambia entre decide y commit ───────────────────────────────────

    public function test_do_not_contact_beats_the_proposal(): void
    {
        Http::fake();
        $m = $this->inbound();
        $payload = $this->payload($m);

        $this->lead->update(['do_not_contact' => true]);

        $this->commit($payload)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'do_not_contact');

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
        Http::assertNothingSent();
    }

    public function test_human_takeover_beats_the_proposal(): void
    {
        Http::fake();
        $m = $this->inbound();
        $payload = $this->payload($m);

        $this->conversation->update(['human_takeover' => true, 'human_takeover_source' => 'manual']);

        $this->commit($payload)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'human_takeover');

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    /** Escribió otra vez mientras el modelo pensaba: esta respuesta ya no va. */
    public function test_a_superseded_message_is_not_answered(): void
    {
        Http::fake();
        $primero = $this->inbound('hola', 'wamid.A');
        $payload = $this->payload($primero);

        $segundo = $this->inbound('cuánto vale?', 'wamid.B');

        $this->commit($payload)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'superseded')
            ->assertJsonPath('superseded_by', $segundo->id);

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_a_stale_decide_token_is_rejected(): void
    {
        Http::fake();
        $m = $this->inbound();
        $payload = $this->payload($m);

        // La fase cambió por otro camino después de emitir el token.
        $this->conversation->update(['commercial_phase' => P::RECOMMENDATION]);

        $this->commit($payload)->assertStatus(422)
            ->assertJsonPath('code', 'stale_decision')
            ->assertJsonPath('detail.reason', 'decide_token_phase_changed');

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_an_expired_decide_token_is_rejected(): void
    {
        Http::fake();
        $m = $this->inbound();
        $payload = $this->payload($m);

        $this->travel(200)->seconds();

        $this->commit($payload)->assertStatus(422)
            ->assertJsonPath('code', 'stale_decision')
            ->assertJsonPath('detail.reason', 'decide_token_expired');
    }

    public function test_a_forged_token_is_rejected(): void
    {
        $m = $this->inbound();

        $this->commit($this->payload($m, [], 'inventado.token'))
            ->assertStatus(422)->assertJsonPath('code', 'stale_decision');
    }

    // ── Critic fallido ────────────────────────────────────────────────────────

    /**
     * El borrador rechazado no se envía. No es que se valide y falle: es que no
     * entra en la variable que se manda.
     */
    public function test_critic_failure_never_sends_the_draft(): void
    {
        Http::fake();
        // Fase explícita de partida: así «no avanza» es una afirmación con contenido.
        $this->conversation->update(['commercial_phase' => P::DISCOVERY]);
        $m = $this->inbound('hola');

        $res = $this->commit($this->payload($m, [
            'proposal' => ['next_state' => P::RECOMMENDATION,
                'reply_draft' => 'Un texto robótico y malísimo que el critic tumbó.'],
            'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.2],
        ]))->assertOk();

        $this->assertContains($res->json('outcome'), ['curated_fallback', 'handoff']);

        $enviado = MarketingMessage::where('direction', 'outbound')->first();
        if ($enviado !== null) {
            $this->assertStringNotContainsString('robótico y malísimo', (string) $enviado->body);
        }

        // La fase NO avanza a RECOMMENDATION: el critic dijo que no servía.
        $this->assertSame(P::DISCOVERY, $this->conversation->fresh()->commercial_phase);
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending);
    }

    public function test_critic_failure_with_a_curated_reply_sends_the_safe_text(): void
    {
        Http::fake();
        $m = $this->inbound('me da pena ir al gimnasio');

        $res = $this->commit($this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::BEGINNER_FEAR,
                'reply_draft' => 'Texto rechazado por el critic.',
            ],
            'critic' => ['verdict' => 'fail', 'attempt' => 2],
        ]))->assertOk();

        $res->assertJsonPath('outcome', 'curated_fallback')
            ->assertJsonPath('fallback_mode', 'SAFE_CURATED_REPLY');

        $this->assertNotNull($res->json('reply_final'));
        $this->assertStringNotContainsString('rechazado por el critic', (string) $res->json('reply_final'));
        $this->assertSame('critic_failed', $this->conversation->fresh()->staff_review_reason);
    }

    public function test_critic_failure_without_a_safe_reply_answers_nothing(): void
    {
        Http::fake();
        $m = $this->inbound('no me escriban más');

        $res = $this->commit($this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
                'next_state' => P::DO_NOT_CONTACT,
                'reply_draft' => 'Algo que el critic tumbó.',
            ],
            'critic' => ['verdict' => 'fail', 'attempt' => 2],
        ]))->assertOk();

        $res->assertJsonPath('outcome', 'handoff')
            ->assertJsonPath('fallback_mode', 'NO_REPLY_AND_HANDOFF')
            ->assertJsonPath('reply_final', null);

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
        $this->assertSame('critic_failed_no_safe_reply', $this->conversation->fresh()->staff_review_reason);
    }

    /** Un critic que falla NUNCA apaga la IA: eso solo lo hace una persona. */
    public function test_critic_failure_does_not_switch_the_ai_off(): void
    {
        Http::fake();
        $m = $this->inbound('hola');

        $this->commit($this->payload($m, [
            'critic' => ['verdict' => 'fail', 'attempt' => 2],
        ]))->assertOk();

        $conv = $this->conversation->fresh();
        $this->assertTrue((bool) $conv->ai_enabled);
        $this->assertFalse((bool) $conv->human_takeover);
    }

    // ── Contenido inseguro ────────────────────────────────────────────────────

    public function test_forbidden_and_unsafe_drafts_are_rejected(): void
    {
        Http::fake();
        $m = $this->inbound();

        $this->commit($this->payload($m, [
            'proposal' => ['reply_draft' => 'Listo, voy a activar membresia ya mismo.'],
        ]))->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_FORBIDDEN_ACTION);

        $this->commit($this->payload($m, [
            'proposal' => ['reply_draft' => 'Con esto tienes resultados asegurados.'],
        ]))->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_UNSAFE_CLAIM);

        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    // ── El saliente es de la IA, siempre ──────────────────────────────────────

    public function test_the_outbound_is_always_sender_type_ai(): void
    {
        Http::fake();
        $m = $this->inbound();

        $this->commit($this->payload($m))->assertOk();

        $out = MarketingMessage::where('direction', 'outbound')->sole();
        $this->assertSame(MarketingMessage::SENDER_AI, $out->sender_type);
        $this->assertNull($out->sender_user_id, 'Un mensaje de ULTRON no tiene autor humano.');
    }

    // ── Pagos: imposibles en la v1 ────────────────────────────────────────────

    public function test_no_path_can_create_a_payment_in_v1(): void
    {
        Http::fake();
        $m = $this->inbound('quiero pagar ya');

        $this->commit($this->payload($m, [
            'proposal' => [
                'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
                'next_state' => P::CLOSING,
                'recommended_plan_id' => $this->plan->id,
                'reply_draft' => 'Perfecto. Te ayudo con eso.',
            ],
        ]))->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * La bandera del negocio sola no basta: sin Wompi configurado (claves, checkout)
     * el link no es posible, la herramienta se descarta y no se genera nada. El
     * camino positivo, con Wompi productivo, vive en UltronPaymentLinkTest.
     */
    public function test_the_business_flag_alone_does_not_make_a_link_possible(): void
    {
        Http::fake();
        config()->set('marketing.ultron.payment_links_enabled', true);
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', null);
        config()->set('wompi.integrity_secret', null);

        $m = $this->inbound('quiero pagar');

        $r = $this->commit($this->payload($m, [
            'proposal' => ['tools_requested' => ['payment_link_send']],
        ]))->assertOk();

        $this->assertContains('payment_link_send', $r->json('applied.tools_rejected'));
        $this->assertSame(0, PaymentTransaction::count());
    }
}
