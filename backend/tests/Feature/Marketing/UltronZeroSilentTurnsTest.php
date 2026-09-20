<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;

/**
 * CERO TURNOS MUDOS.
 *
 * Un entrante atendible tiene que terminar en EXACTAMENTE un desenlace, y la
 * persona tiene que recibir algo. Que el Strategist se equivoque, que el
 * borrador caiga en un guard, que n8n devuelva 422 o que alguien mande una nota
 * de voz no son razones válidas para dejar a alguien sin respuesta: son razones
 * para cambiar de texto.
 *
 * Las dos formas de silencio absoluto están medidas en el canario físico y cada
 * una tiene aquí su prueba en los dos sentidos:
 *
 *  1. EL RECHAZO DEL CRM. `/ai/commit` lanza ANTES de escribir fila, n8n no
 *     considera eso un error y la ejecución acaba en verde. La persona preguntó
 *     y no hubo ni respuesta ni rastro. → bloque «rendición».
 *  2. EL RELEVO FANTASMA. Una nota de voz detrás de una pregunta creaba fila
 *     INBOUND y `supersededBy()` la contaba: la pregunta se descartaba «porque
 *     llegó algo más nuevo» y lo más nuevo no lo iba a contestar nadie.
 *     → bloque «relevo».
 */
class UltronZeroSilentTurnsTest extends TestCase
{
    use RefreshDatabase;
    use UltronTurnEvents;

    private const SECRET = 'test-internal-secret';

    /** Un borrador que los guards del commit rechazan SIEMPRE: afirma un pago que nadie vio. */
    private const BORRADOR_ENVENENADO = 'Listo, ya recibimos tu pago y quedas activo.';

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

        Http::fake();
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body = 'hola', ?string $wamid = 'wamid.Z1'): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    /** Lo que deja una nota de voz, un sticker o una reacción: fila de inbox, sin turno. */
    private function inboundSinTurno(string $body, string $tipo): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => 'wamid.'.$tipo, 'status' => 'received',
            'metadata' => ['message_type' => $tipo],
        ]);
    }

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
                'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento. ¿Qué te gustaría lograr?',
                'confidence' => 0.8,
                'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $overrides);
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function salientes()
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND);
    }

    // ══ 1 · RENDICIÓN ═════════════════════════════════════════════════════════

    /**
     * EL FALLO, EN UNA PRUEBA. Sin la rendición, esta persona no recibe nada y
     * no queda ni una fila que diga que preguntó.
     */
    public function test_a_draft_the_crm_rejects_leaves_no_trace_and_no_answer(): void
    {
        $m = $this->inbound('me interesa, cómo hago para empezar?');
        $pago = $this->payload($m, ['proposal' => ['reply_draft' => self::BORRADOR_ENVENENADO]]);

        $this->commit($pago)->assertStatus(422)->assertJsonPath('code', 'machine_reply_payment_fact');

        $this->assertSame(0, $this->salientes()->count(), 'el borrador envenenado no sale');
        $this->assertSame(0, MarketingAiAction::count(), 'y no queda fila: ése es el agujero');
    }

    /** Y con la rendición, la MISMA llave vuelve y la persona sí recibe algo. */
    public function test_the_same_turn_surrenders_to_laravel_text_and_the_person_is_answered(): void
    {
        $m = $this->inbound('me interesa, cómo hago para empezar?');
        $token = $this->decideFor($m)['decide_token'];

        $this->commit($this->payload($m, ['proposal' => ['reply_draft' => self::BORRADOR_ENVENENADO]], $token))
            ->assertStatus(422);

        // Mismo turno, misma idempotency_key, mismo token: nada de eso se gastó.
        $rendicion = $this->payload($m, [
            'proposal' => ['reply_draft' => self::BORRADOR_ENVENENADO],
            'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
        ], $token);

        $this->commit($rendicion)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('outcome', 'curated_fallback')
            ->assertJsonPath('fallback_mode', 'SAFE_CURATED_REPLY');

        $this->assertSame(1, $this->salientes()->count(), 'sale exactamente un mensaje');

        $salida = (string) $this->salientes()->latest('id')->first()->body;
        $this->assertNotSame(self::BORRADOR_ENVENENADO, $salida, 'el borrador rechazado NO se envía');
        $this->assertStringNotContainsStringIgnoringCase('recibimos tu pago', $salida);
        $this->assertNotSame('', trim($salida));
    }

    /** El turno rendido se audita como lo que es, no como un critic que falló. */
    public function test_the_surrender_is_audited_with_its_reason(): void
    {
        $m = $this->inbound('cuanto vale?');
        $this->commit($this->payload($m, [
            'proposal' => ['intent' => SalesIntents::PRICING_QUESTION, 'reply_draft' => self::BORRADOR_ENVENENADO],
            'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
        ]))->assertOk();

        $accion = MarketingAiAction::latest('id')->first();

        $this->assertSame('machine_reply_payment_fact', $accion->metadata['recovery']['reason']);
        $this->assertSame(2, $accion->metadata['recovery']['attempt']);
        $this->assertSame(['commit_rejected'], $accion->metadata['risk_flags']);
        $this->assertSame('SAFE_CURATED_REPLY', $accion->metadata['fallback_mode']);
        $this->assertStringContainsString('recibimos tu pago', $accion->metadata['discarded_draft'],
            'el borrador descartado se guarda de evidencia');
        $this->assertArrayNotHasKey('critic', $accion->metadata,
            'no se inventa un veredicto del Critic que nadie emitió');

        $conv = $this->conversation->fresh();
        $this->assertTrue((bool) $conv->staff_review_pending);
        $this->assertSame('commit_rejected', $conv->staff_review_reason);
        $this->assertNotSame(P::GOAL_DISCOVERY, $conv->commercial_phase, 'la fase no avanza sobre una rendición');
    }

    /** Una rendición NO es un salvoconducto: el interruptor maestro sigue mandando. */
    public function test_a_surrender_cannot_run_with_ultron_off(): void
    {
        $m = $this->inbound('hola');
        $payload = $this->payload($m, ['recovery' => ['reason' => 'draft_rejected']]);

        config()->set('marketing.ultron.enabled', false);

        $this->commit($payload)->assertStatus(403)->assertJsonPath('code', 'ultron_disabled');
        $this->assertSame(0, $this->salientes()->count());
    }

    /** Ni el cerrojo del canario. */
    public function test_a_surrender_cannot_run_outside_the_canary_conversation(): void
    {
        $m = $this->inbound('hola');
        $payload = $this->payload($m, ['recovery' => ['reason' => 'draft_rejected']]);

        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id + 999);

        $this->commit($payload)->assertStatus(403)->assertJsonPath('code', 'not_canary_conversation');
        $this->assertSame(0, $this->salientes()->count());
    }

    /** Ni el relevo: si la persona ya escribió otra cosa, tampoco sale la rendición. */
    public function test_a_surrender_is_still_blocked_when_the_person_moved_on(): void
    {
        $viejo = $this->inbound('hola', 'wamid.V');
        $payload = $this->payload($viejo, ['recovery' => ['reason' => 'draft_rejected']]);

        $nuevo = $this->inbound('perdón, mejor dime los precios', 'wamid.N');
        $this->abreTurno($nuevo);

        $this->commit($payload)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'superseded');

        $this->assertSame(0, $this->salientes()->count());
    }

    /** Ni el token: una rendición sobre un mundo que ya cambió sigue siendo vieja. */
    public function test_a_surrender_with_a_stale_token_is_refused(): void
    {
        $m = $this->inbound('hola');
        $payload = $this->payload($m, ['recovery' => ['reason' => 'draft_rejected']], 'token.falso');

        $this->commit($payload)->assertStatus(422)->assertJsonPath('code', 'stale_decision');
        $this->assertSame(0, $this->salientes()->count());
    }

    /** El bloque de rendición también es fail-closed en su forma. */
    public function test_the_recovery_block_refuses_fields_that_are_not_in_the_contract(): void
    {
        $m = $this->inbound('hola');

        $this->commit($this->payload($m, [
            'recovery' => ['reason' => 'draft_rejected', 'rejected_draft' => 'texto del cliente'],
        ]))->assertStatus(422)
            ->assertJsonPath('code', 'unexpected_fields')
            ->assertJsonFragment(['fields' => ['recovery.rejected_draft']]);
    }

    /** Y su motivo es un código, no prosa: por ahí no entra el mensaje del cliente. */
    public function test_the_recovery_reason_must_be_a_code(): void
    {
        $m = $this->inbound('hola');

        $this->commit($this->payload($m, [
            'recovery' => ['reason' => 'el modelo dijo que ya habías pagado'],
        ]))->assertStatus(422)->assertJsonValidationErrors('recovery.reason');
    }

    /** Sin bloque de rendición, nada cambia: el camino normal sigue igual. */
    public function test_without_the_recovery_block_the_normal_path_is_untouched(): void
    {
        $m = $this->inbound('hola');

        $this->commit($this->payload($m))->assertOk()->assertJsonPath('outcome', 'dry_run');

        $this->assertSame('Claro, te cuento. ¿Qué te gustaría lograr?', (string) $this->salientes()->first()->body);
        $this->assertFalse((bool) $this->conversation->fresh()->staff_review_pending);
    }

    // ══ 2 · RELEVO ════════════════════════════════════════════════════════════

    /**
     * LA RÁFAGA QUE DEJABA CERO RESPUESTAS. Una nota de voz detrás de la
     * pregunta: el enrutador la aparta antes de ULTRON, así que nadie la va a
     * contestar. No puede quedarse con el turno de la pregunta que sí se iba a
     * contestar.
     */
    public function test_a_voice_note_does_not_steal_the_turn_of_the_question_before_it(): void
    {
        $pregunta = $this->inbound('uy si me interesa, explícame cómo puedo empezar', 'wamid.Q');
        $payload = $this->payload($pregunta);

        $this->inboundSinTurno('', 'audio');   // llega mientras el modelo piensa

        $this->commit($payload)->assertOk()->assertJsonPath('outcome', 'dry_run');

        $this->assertSame(1, $this->salientes()->count(), 'la pregunta sí se contesta');
    }

    public static function noAccionables(): \Generator
    {
        yield 'nota de voz' => ['', 'audio'];
        yield 'foto sin pie' => ['', 'image'];
        yield 'sticker' => ['', 'sticker'];
        yield 'reacción 👍' => ['👍', 'reaction'];
        yield 'ubicación' => ['', 'location'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noAccionables')]
    public function test_no_inbound_without_a_turn_of_its_own_supersedes(string $cuerpo, string $tipo): void
    {
        $pregunta = $this->inbound('cuánto vale el mensual?', 'wamid.Q2');
        $payload = $this->payload($pregunta);

        $this->inboundSinTurno($cuerpo, $tipo);

        $this->commit($payload)->assertOk()->assertJsonPath('outcome', 'dry_run');
        $this->assertSame(1, $this->salientes()->count());
    }

    /** Y al revés, que es igual de importante: el texto que SÍ abre turno releva. */
    public function test_a_newer_text_message_with_its_own_turn_does_supersede(): void
    {
        $viejo = $this->inbound('hola', 'wamid.A');
        $payload = $this->payload($viejo);

        $nuevo = $this->inbound('perdón, mejor los precios', 'wamid.B');
        $this->abreTurno($nuevo);

        $this->commit($payload)->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'superseded')
            ->assertJsonPath('superseded_by', $nuevo->id);

        $this->assertSame(0, $this->salientes()->count(), 'contestará el turno del mensaje nuevo');
    }

    /** Un evento que n8n nunca llegó a recibir promete un turno que no habrá. */
    public function test_an_event_that_never_reached_n8n_does_not_supersede(): void
    {
        $viejo = $this->inbound('cuánto vale?', 'wamid.A2');
        $payload = $this->payload($viejo);

        $nuevo = $this->inbound('y tienen parqueadero?', 'wamid.B2');
        $this->abreTurno($nuevo, MarketingAutomationEvent::STATUS_FAILED);

        $this->commit($payload)->assertOk()->assertJsonPath('outcome', 'dry_run');
        $this->assertSame(1, $this->salientes()->count());
    }

    /** Un saliente nuestro tampoco releva: contestar no es que la persona escriba. */
    public function test_our_own_outbound_never_supersedes(): void
    {
        $m = $this->inbound('hola');
        $payload = $this->payload($m);

        MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI,
            'body' => 'respuesta previa', 'status' => 'dry_run',
        ]);

        $this->commit($payload)->assertOk()->assertJsonPath('outcome', 'dry_run');
    }
}
