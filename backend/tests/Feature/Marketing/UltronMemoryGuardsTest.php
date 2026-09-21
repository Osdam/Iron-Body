<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lo que la revisión independiente del PUNTO 1 encontró y no puede volver:
 * la memoria no guarda PII ni precios, la guardia de novedad no se lleva por
 * delante el opt-out ni una repetición pedida, el texto curado no se repite,
 * y un JSON de memoria corrupto no tumba decide.
 */
class UltronMemoryGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingLead $lead;

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

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado al gimnasio'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h());
    }

    private function commit(MarketingMessage $m, array $proposal, array $critic = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge(['strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.8, 'tools_requested' => []], $proposal),
            'critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9], $critic),
        ], $this->h());
    }

    private function outbound(): int
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count();
    }

    /** Hallazgo bloqueante: la réplica no puede llevarse el opt-out por delante. */
    public function test_a_repeated_reply_still_runs_the_tools_of_the_turn(): void
    {
        $texto = 'Listo, tranquilo. No hay problema. Si después te animas, aquí te ayudamos.';
        $this->commit($this->inbound('no me interesa', 'g.1'), ['intent' => SalesIntents::NOT_INTERESTED, 'reply_draft' => $texto])->assertOk();

        $r = $this->commit($this->inbound('ya no me escriban mas por favor', 'g.2'), [
            'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST, 'next_state' => P::DO_NOT_CONTACT,
            'tools_requested' => [SalesIntents::TOOL_MARK_DNC], 'reply_draft' => $texto,
        ])->assertOk();

        $this->assertSame('no_reply', $r->json('outcome'));
        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact, 'el opt-out se ejecutó aunque el texto no saliera');
        $this->assertSame(1, $this->outbound());
    }

    /** Quien pide que se lo repitan, lo recibe repetido. */
    public function test_an_explicit_repeat_request_bypasses_the_novelty_guard(): void
    {
        $direccion = 'Estamos en la Cl. 24 Sur #33-53, Neiva. ¿Vas a ir por primera vez?';
        $this->commit($this->inbound('dónde quedan?', 'r.1'), ['intent' => SalesIntents::LOCATION_QUESTION, 'reply_draft' => $direccion])->assertOk();

        $this->commit($this->inbound('me repites la dirección porfa? la borré', 'r.2'), ['intent' => SalesIntents::LOCATION_QUESTION, 'reply_draft' => $direccion])
            ->assertOk()->assertJsonPath('outcome', 'dry_run');

        $this->assertSame(2, $this->outbound());
    }

    /**
     * El texto curado no se repite palabra por palabra, PERO SE CONTESTA.
     *
     * Este test fijaba el fallo como si fuera lo deseado: exigía que el
     * segundo turno acabara en `NO_REPLY_AND_HANDOFF`, con un solo saliente y
     * la conversación marcada para una persona. O sea, exigía el silencio.
     *
     * Se vio lo que valía en una prueba física: alguien pidió información tres
     * veces seguidas y no recibió NADA las tres, porque el texto curado ya se
     * había gastado en el primer turno y la guarda de duplicados lo anulaba.
     * No repetirse es una preferencia de estilo; contestar es el trabajo.
     *
     * Lo que se fija ahora es lo que de verdad importaba: que no salga la
     * misma frase dos veces Y que la persona reciba algo las dos.
     */
    public function test_a_curated_fallback_is_not_repeated_but_is_still_answered(): void
    {
        $fail = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.2];
        $a = $this->commit($this->inbound('dónde están?', 'c.1'), ['intent' => SalesIntents::LOCATION_QUESTION, 'reply_draft' => 'borrador malo'], $fail)->assertOk();
        $b = $this->commit($this->inbound('dónde queda el gym?', 'c.2'), ['intent' => SalesIntents::LOCATION_QUESTION, 'reply_draft' => 'otro borrador malo'], $fail)->assertOk();

        $this->assertSame('SAFE_CURATED_REPLY', $a->json('fallback_mode'));
        $this->assertSame('SAFE_CURATED_REPLY', $b->json('fallback_mode'), 'el segundo turno se quedó mudo');
        $this->assertSame(2, $this->outbound(), 'alguien preguntó dos veces y sólo recibió una respuesta');

        $textos = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')->pluck('body')->all();
        /*
         * Se mide la SIMILITUD, no la identidad literal.
         *
         * `assertNotSame` pasaría con un casi-duplicado: bastaría que una
         * variante futura del último recurso se pareciera un 0,9 a la anterior
         * sin ser idéntica. El umbral es el mismo que usa la guarda de
         * novedad, así que el test dice lo que el sistema promete.
         */
        $this->assertLessThan(
            0.85,
            app(\App\Services\Marketing\Ultron\NoveltyGuard::class)->similarity($textos[0], $textos[1]),
            'la segunda respuesta es casi la misma que la primera',
        );

        // Y que al agente se le atragante la redacción no es trabajo para una persona.
        $this->assertFalse((bool) $this->conversation->fresh()->staff_review_pending);
    }

    /** La memoria no guarda teléfonos, documentos ni correos de la persona. */
    public function test_memory_never_stores_the_persons_identifiers(): void
    {
        $this->commit($this->inbound('Hola, soy Andres Ramirez, mi cel es 3001234567, mi cédula 1.098.765.432 y mi correo andres@gmail.com, ¿me pueden inscribir?', 'p.1'),
            ['intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Claro. Para empezar vienes con tu documento y el equipo confirma el medio de pago al final.'])->assertOk();

        $json = json_encode($this->conversation->fresh()->memory);
        $this->assertStringNotContainsString('3001234567', $json);
        $this->assertStringNotContainsString('1.098.765.432', $json);
        $this->assertStringNotContainsString('andres@gmail.com', $json);
        $this->assertStringContainsString('[numero]', $json);
        $this->assertStringContainsString('[correo]', $json);
        $this->assertSame('lead_verbatim', $this->conversation->fresh()->memory['last_user_question']['source']);
    }

    /** Ni el precio, aunque la última frase del agente lo llevara puesto. */
    public function test_memory_never_stores_a_price_and_decide_never_shows_one(): void
    {
        $this->commit($this->inbound('cuánto vale?', 'q.1'), [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro, el {{PLAN_NAME}} te queda en {{PLAN_PRICE}}, ¿quieres que te explique cómo empezar?',
        ])->assertOk();

        $mem = $this->conversation->fresh()->memory;
        $this->assertSame('explain_how_to_start', $mem['last_agent_offer']['kind']);
        $this->assertStringNotContainsString('80', json_encode($mem), 'ninguna cifra del precio en la memoria');
        $this->assertSame([$this->plan->id], array_column($mem['prices_delivered'], 'plan_id'));

        // Lo que este punto añade al contexto no lleva cifras. (recent_messages
        // sí lleva el texto ya enviado, con el precio puesto: exposición
        // preexistente, anotada para la auditoría de seguridad del punto 12.)
        $d = $this->decide($this->inbound('si por favor', 'q.2'));
        foreach (['context.memory', 'context.novelty', 'context.resolved_reference'] as $k) {
            $this->assertStringNotContainsString('80.000', json_encode($d->json($k)), $k);
            $this->assertStringNotContainsString('80000', json_encode($d->json($k)), $k);
        }
    }

    /** Un JSON de memoria parcial o corrupto no tumba decide. */
    public function test_a_corrupt_memory_json_does_not_break_decide(): void
    {
        $this->conversation->forceFill(['memory' => ['version' => 1, 'plans_discussed' => null, 'prices_delivered' => 'x', 'last_agent_offer' => 'no-es-objeto']])->save();

        $this->decide($this->inbound('hola', 'k.1'))->assertOk();
    }
}
