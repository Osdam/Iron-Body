<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronDecideToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `/ai/decide` — la mitad de solo lectura del contrato con ULTRON.
 *
 * Lo que se fija aquí, por orden de importancia:
 *
 *  1. Que NO escribe. Es la razón de que exista separado del commit.
 *  2. Que no entrega PII ni precios. Lo que no viaja no se filtra, y un modelo
 *     que nunca ve una cifra no puede inventársela.
 *  3. Que el último mensaje gana. Tres mensajes seguidos de la misma persona no
 *     pueden convertirse en tres respuestas.
 */
class UltronDecideTest extends TestCase
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
            'benefits' => json_encode(['Acceso completo', 'Asesoría']),
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

    private function inbound(string $body, ?string $wamid = null): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body,
            'meta_message_id' => $wamid,
            'status' => 'received',
        ]);
    }

    private function decide(?int $messageId = null, ?int $conversationId = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $conversationId ?? $this->conversation->id,
            'message_id' => $messageId,
        ], ['Authorization' => 'Bearer '.self::SECRET]);
    }

    // ── Contrato básico ───────────────────────────────────────────────────────

    public function test_requires_the_internal_bearer(): void
    {
        $m = $this->inbound('hola');

        $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ])->assertStatus(401);
    }

    public function test_returns_the_full_contract(): void
    {
        $m = $this->inbound('hola, cuánto vale?', 'wamid.AAA');

        $res = $this->decide($m->id)->assertOk();

        $res->assertJsonPath('ok', true)
            ->assertJsonPath('superseded', false)
            ->assertJsonPath('source.source_type', 'inbound_message')
            ->assertJsonPath('source.source_event_id', $m->id)
            ->assertJsonPath('source.idempotency_key', 'wamid.AAA')
            ->assertJsonPath('commercial_phase', P::NEW_LEAD)
            ->assertJsonStructure([
                'decision', 'context' => ['lead', 'memory', 'recent_messages', 'knowledge_base', 'active_plans', 'flags'],
                'allowed_transitions', 'allowed_tools', 'decide_token', 'expires_at', 'knowledge_version',
            ]);

        $this->assertNotEmpty($res->json('allowed_transitions'));
        $this->assertIsString($res->json('decide_token'));
    }

    // ── Pureza: la razón de ser del endpoint ──────────────────────────────────

    /**
     * Ni una fila. La llamada al modelo puede ocurrir; la escritura de negocio
     * no. Si esto falla, `/ai/decide` ha dejado de ser seguro de reintentar.
     */
    public function test_decide_has_no_side_effects(): void
    {
        Http::fake();
        $m = $this->inbound('quiero bajar de peso', 'wamid.PURE');

        $antes = [
            'messages' => MarketingMessage::count(),
            'ai_actions' => MarketingAiAction::count(),
            'transactions' => PaymentTransaction::count(),
            'outbound' => MarketingMessage::where('direction', 'outbound')->count(),
            'conv' => $this->conversation->fresh()->toArray(),
            'lead' => $this->lead->fresh()->toArray(),
        ];

        $this->decide($m->id)->assertOk();

        $this->assertSame($antes['messages'], MarketingMessage::count());
        $this->assertSame($antes['ai_actions'], MarketingAiAction::count());
        $this->assertSame($antes['transactions'], PaymentTransaction::count());
        $this->assertSame($antes['outbound'], MarketingMessage::where('direction', 'outbound')->count());
        // Ni la memoria ni la fase se mueven: eso es trabajo del commit.
        $this->assertSame($antes['conv'], $this->conversation->fresh()->toArray());
        $this->assertSame($antes['lead'], $this->lead->fresh()->toArray());
        Http::assertNothingSent();
    }

    // ── PII: lo que no viaja no se filtra ─────────────────────────────────────

    public function test_no_pii_reaches_ultron(): void
    {
        $m = $this->inbound('hola', 'wamid.PII');

        $raw = $this->decide($m->id)->assertOk()->getContent();

        foreach (['3150536026', '573150536026'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw, "El identificador {$pii} no debe salir del CRM.");
        }
        foreach (['phone', 'wa_id', 'meta_user_id', 'phone_number_id', 'access_token'] as $campo) {
            $this->assertStringNotContainsString('"'.$campo.'"', $raw, "El campo {$campo} no pertenece al contrato.");
        }
    }

    // ── Precios: ULTRON elige plan, Laravel cotiza ────────────────────────────

    public function test_active_plans_carry_no_price(): void
    {
        $m = $this->inbound('cuánto vale?');

        $planes = $this->decide($m->id)->assertOk()->json('context.active_plans');

        $this->assertCount(1, $planes);
        $this->assertSame($this->plan->id, $planes[0]['id']);
        $this->assertSame('Mensual', $planes[0]['name']);
        $this->assertSame(30, $planes[0]['duration_days']);
        $this->assertArrayNotHasKey('price', $planes[0], 'ULTRON elige el plan; el precio lo pone Laravel.');
        // Y la cifra tampoco aparece por ninguna otra vía.
        $this->assertStringNotContainsString('80000', json_encode($planes));
    }

    // ── Tools de la v1 ────────────────────────────────────────────────────────

    public function test_v1_tools_exclude_payments_and_followups(): void
    {
        $m = $this->inbound('quiero pagar ya');

        $tools = $this->decide($m->id)->assertOk()->json('allowed_tools');

        $this->assertNotContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $tools);
        $this->assertNotContains(SalesIntents::TOOL_SCHEDULE_FOLLOWUP, $tools);
        $this->assertNotContains(SalesIntents::TOOL_HUMAN_TAKEOVER, $tools);
        $this->assertSame(UltronDecideService::V1_ALLOWED_TOOLS, array_values($tools));
    }

    public function test_payment_handoff_is_not_an_allowed_transition_in_v1(): void
    {
        $m = $this->inbound('quiero pagar');

        $transiciones = $this->decide($m->id)->assertOk()->json('allowed_transitions');

        $this->assertNotContains(P::PAYMENT_HANDOFF, $transiciones);
    }

    // ── Elegibilidad ──────────────────────────────────────────────────────────

    public function test_rejects_outbound_and_non_lead_messages(): void
    {
        $out = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'hola', 'status' => 'dry_run',
        ]);

        $this->decide($out->id)->assertStatus(422)->assertJsonPath('reason', 'message_not_inbound');
    }

    public function test_rejects_a_message_from_another_conversation(): void
    {
        $otroLead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'Otro']);
        $otra = MarketingConversation::create([
            'lead_id' => $otroLead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $ajeno = MarketingMessage::create([
            'conversation_id' => $otra->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola', 'status' => 'received',
        ]);

        $this->decide($ajeno->id)->assertStatus(422)
            ->assertJsonPath('reason', 'message_not_in_conversation');
    }

    public function test_rejects_do_not_contact(): void
    {
        $m = $this->inbound('hola');
        $this->lead->update(['do_not_contact' => true]);

        $this->decide($m->id)->assertStatus(422)->assertJsonPath('reason', 'do_not_contact');
    }

    public function test_rejects_manual_human_takeover(): void
    {
        $m = $this->inbound('hola');
        $this->conversation->update(['human_takeover' => true, 'human_takeover_source' => 'manual']);

        $this->decide($m->id)->assertStatus(422)->assertJsonPath('reason', 'human_takeover');
    }

    public function test_rejects_ai_disabled(): void
    {
        $m = $this->inbound('hola');
        $this->conversation->update(['ai_enabled' => false]);

        $this->decide($m->id)->assertStatus(422)->assertJsonPath('reason', 'ai_disabled');
    }

    // ── Latest inbound wins ───────────────────────────────────────────────────

    /**
     * Tres mensajes en quince segundos. Sin esto, tres respuestas: dos de ellas
     * contestando preguntas que la propia persona ya superó.
     */
    public function test_older_messages_are_superseded_by_the_latest(): void
    {
        $hola = $this->inbound('hola');
        $info = $this->inbound('quiero información');
        $precio = $this->inbound('cuánto vale?');

        $this->decide($hola->id)->assertOk()
            ->assertJsonPath('superseded', true)
            ->assertJsonPath('superseded_by', $precio->id);

        $this->decide($info->id)->assertOk()
            ->assertJsonPath('superseded', true)
            ->assertJsonPath('superseded_by', $precio->id);

        // Solo el último sigue siendo accionable.
        $this->decide($precio->id)->assertOk()
            ->assertJsonPath('superseded', false)
            ->assertJsonPath('ok', true);
    }

    /** Un saliente nuestro NO invalida el entrante que lo provocó. */
    public function test_an_outbound_message_does_not_supersede(): void
    {
        $m = $this->inbound('hola');
        MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'respuesta', 'status' => 'dry_run',
        ]);

        $this->decide($m->id)->assertOk()->assertJsonPath('superseded', false);
    }

    // ── La fase viaja y condiciona ────────────────────────────────────────────

    public function test_current_phase_and_transitions_reflect_stored_state(): void
    {
        $this->conversation->update(['commercial_phase' => P::CLOSING, 'main_barrier' => 'price']);
        $m = $this->inbound('hola');

        $res = $this->decide($m->id)->assertOk();

        $res->assertJsonPath('commercial_phase', P::CLOSING)
            ->assertJsonPath('context.memory.commercial_phase', P::CLOSING)
            ->assertJsonPath('context.memory.main_barrier', 'price');

        // Un «hola» no puede devolver al principio a quien estaba cerrando.
        $this->assertNotContains(P::NEW_LEAD, $res->json('allowed_transitions'));
    }

    // ── Token ─────────────────────────────────────────────────────────────────

    public function test_decide_token_is_valid_now_and_bound_to_this_state(): void
    {
        $m = $this->inbound('hola');
        $res = $this->decide($m->id)->assertOk();

        $token = $res->json('decide_token');
        $tokens = app(UltronDecideToken::class);
        $servicio = app(UltronDecideService::class);
        $conv = $this->conversation->fresh();

        $fase = $servicio->currentPhase($conv);
        $transiciones = app(P::class)->allowedTransitions($fase, $servicio->phaseContext($conv));
        $kv = $res->json('knowledge_version');

        $this->assertTrue($tokens->verify($token, $conv->id, $m->id, $fase, $transiciones, $kv)['valid']);

        // Para otro mensaje, no vale.
        $otro = $tokens->verify($token, $conv->id, $m->id + 999, $fase, $transiciones, $kv);
        $this->assertFalse($otro['valid']);
        $this->assertSame('decide_token_message_mismatch', $otro['reason']);

        // Si la fase cambió mientras tanto, tampoco.
        $cambiada = $tokens->verify($token, $conv->id, $m->id, P::CLOSING, $transiciones, $kv);
        $this->assertFalse($cambiada['valid']);
        $this->assertSame('decide_token_phase_changed', $cambiada['reason']);
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $tokens = app(UltronDecideToken::class);
        $emitido = $tokens->issue(1, 2, P::NEW_LEAD, [P::NEW_LEAD], 'kv1');

        $manipulado = $emitido['token'].'x';
        $r = $tokens->verify($manipulado, 1, 2, P::NEW_LEAD, [P::NEW_LEAD], 'kv1');

        $this->assertFalse($r['valid']);
        $this->assertSame('decide_token_bad_signature', $r['reason']);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $tokens = app(UltronDecideToken::class);
        $emitido = $tokens->issue(1, 2, P::NEW_LEAD, [P::NEW_LEAD], 'kv1');

        $this->travel(UltronDecideToken::TTL_SECONDS + 5)->seconds();

        $r = $tokens->verify($emitido['token'], 1, 2, P::NEW_LEAD, [P::NEW_LEAD], 'kv1');
        $this->assertFalse($r['valid']);
        $this->assertSame('decide_token_expired', $r['reason']);
    }
}
