<?php

namespace Tests\Feature\Marketing\Torture;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\StaffReviewAuthority;
use App\Services\Marketing\Ultron\UltronDecideService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TANDA 4 — EL PROTOCOLO. El sobre también es hostil.
 *
 * Las otras tandas juzgan lo que la propuesta DICE. Aquí se juzga otra cosa:
 * que este commit sea de verdad ESTE turno, de ESTA conversación, y una sola
 * vez. Un modelo puede equivocarse de texto y eso se ve; equivocarse de sobre
 * —repetir una clave, traer el token de otra conversación, contestar a un
 * mensaje que la persona ya superó— no se ve en el texto y produce el mismo
 * daño: alguien recibe dos veces lo mismo, o recibe lo que era de otro.
 *
 * Cada escenario afirma las dos mitades: qué contesta el endpoint y qué quedó
 * al otro lado (cuántos mensajes salieron, en qué fase quedó la conversación,
 * qué fila de `marketing_ai_actions` se escribió). Un 422 que igualmente mandó
 * el mensaje no es un 422: es un mensaje.
 *
 * Tres respuestas distintas que conviene no confundir, porque significan
 * cosas distintas para n8n:
 *   422 con `errors`  → la FORMA no cumple el contrato (validación).
 *   422 con `code`    → la forma cumple, la autoridad no (`unexpected_fields`,
 *                       `stale_decision`, `illegal_transition`…).
 *   200 con `outcome` → se ejecutó el turno y Laravel decidió callarse
 *                       (`blocked`, `no_reply` + `blocked_reason`).
 */
class ProtocolAuthorityTortureTest extends TortureCase
{
    // ═══ FAMILIA 1 — LA FORMA ES FAIL-CLOSED ═════════════════════════════════

    /**
     * Un campo de más no se ignora en silencio.
     *
     * Es la diferencia entre enterarse el primer día de que el workflow empezó
     * a mandar un teléfono y descubrir seis meses después que llevaba medio año
     * mandando algo que nadie leía.
     *
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $critic
     * @param  array<string,mixed>  $envelope
     * @param  string[]  $esperados
     */
    #[DataProvider('unexpectedFieldProvider')]
    public function test_a_field_outside_the_contract_rejects_the_whole_request(
        array $proposal,
        array $critic,
        array $envelope,
        array $esperados,
    ): void {
        $m = $this->inbound('hola, quiero informacion');

        $r = $this->commit($m, $proposal, $critic, $envelope);

        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'unexpected_fields');
        $this->assertSame($esperados, $r->json('fields'), 'El rechazo debe NOMBRAR los campos que sobran.');

        $this->assertSame(0, $this->outbound()->count(), 'Un sobre inválido no puede acabar en un mensaje.');
        $this->assertSame(0, MarketingAiAction::count(), 'Ni en una fila de acción.');
    }

    /** @return array<string, array{array,array,array,string[]}> */
    public static function unexpectedFieldProvider(): array
    {
        return [
            'destinatario en la raiz' => [[], [], ['phone' => '3009998877'], ['phone']],
            'precio en la propuesta' => [['price' => 1000], [], [], ['proposal.price']],
            'firma en el critic' => [[], ['reviewer' => 'gpt-4'], [], ['critic.reviewer']],
            'los tres a la vez' => [
                ['send_to' => '573000000000'], ['reviewer' => 'gpt-4'], ['activate_membership' => true],
                ['activate_membership', 'proposal.send_to', 'critic.reviewer'],
            ],
        ];
    }

    /**
     * El vocabulario de la propuesta es cerrado: lo que no está en el enum no
     * llega al servicio, y por tanto no puede producir ningún efecto.
     *
     * @param  array<string,mixed>  $veneno
     */
    #[DataProvider('malformedProposalProvider')]
    public function test_a_proposal_outside_the_closed_vocabulary_never_reaches_the_service(
        array $veneno,
        string $campoConError,
    ): void {
        $m = $this->inbound('hola, quiero informacion');

        $r = $this->commit($m, $veneno);

        $r->assertStatus(422)->assertJsonValidationErrors([$campoConError]);
        // Un fallo de FORMA no trae `code`: es lo que distingue el 422 del
        // validador del 422 de autoridad, y n8n reacciona distinto a cada uno.
        $this->assertNull($r->json('code'), 'Un fallo de validación no lleva `code`.');

        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, MarketingAiAction::count());
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase);
    }

    /** @return array<string, array{array,string}> */
    public static function malformedProposalProvider(): array
    {
        return [
            'fase inventada' => [['next_state' => 'VENDIDO'], 'proposal.next_state'],
            'intencion inventada' => [['intent' => 'activar_membresia'], 'proposal.intent'],
            'confianza de 2' => [['confidence' => 2], 'proposal.confidence'],
            'confianza de -1' => [['confidence' => -1], 'proposal.confidence'],
            'confianza como palabra' => [['confidence' => 'alta'], 'proposal.confidence'],
            /*
             * Más herramientas que las que existen.
             *
             * El número se CUENTA, no se escribe. Estuvo escrito —«cinco»— y
             * envejeció el día que el vocabulario pasó de cuatro a cinco: lo
             * que este caso probaba dejó de ser «más de las que hay» para
             * pasar a ser «todas las que hay», que es una propuesta legítima.
             * Derivándolo del vocabulario, el caso sigue diciendo lo mismo
             * cuando llegue la sexta.
             */
            'más herramientas que el vocabulario' => [['tools_requested' => [...UltronDecideService::TOOL_VOCABULARY, SalesIntents::TOOL_STAFF_REVIEW]], 'proposal.tools_requested'],
            'herramienta desconocida' => [['tools_requested' => ['refund_money']], 'proposal.tools_requested.0'],
            'herramienta en mayusculas' => [['tools_requested' => ['STAFF_REVIEW']], 'proposal.tools_requested.0'],
            'borrador de 4001' => [['reply_draft' => str_repeat('a', 4001)], 'proposal.reply_draft'],
            'borrador numerico' => [['reply_draft' => 42], 'proposal.reply_draft'],
            'preguntar lo no preguntable' => [['information_needed' => ['precio']], 'proposal.information_needed.0'],
            'information_needed como objeto' => [['information_needed' => ['a' => 'objective']], 'proposal.information_needed'],
            'objetivo numerico' => [['strategy_goal' => 7], 'proposal.strategy_goal'],
            'barrera inventada' => [['main_barrier' => 'pereza'], 'proposal.main_barrier'],
            'plan con id cero' => [['recommended_plan_id' => 0], 'proposal.recommended_plan_id'],
            'question_needed a medias' => [['question_needed' => 'quiza'], 'proposal.question_needed'],
        ];
    }

    /**
     * Mandar la lista de herramientas como objeto no amplía lo que se puede
     * ejecutar: sigue siendo la intersección con el menú del turno.
     *
     * NOTA: el contrato es asimétrico —`information_needed` e `issues` exigen
     * lista y esto no—, pero la autoridad aguanta porque cada valor pasa igual
     * por la allowlist. Queda anotado en findings.
     */
    public function test_the_shape_of_tools_requested_never_widens_the_tool_vocabulary(): void
    {
        $m = $this->inbound('hola, quiero informacion');

        $r = $this->commit($m, [
            'tools_requested' => ['primera' => SalesIntents::TOOL_STAFF_REVIEW, 'segunda' => SalesIntents::TOOL_PAYMENT_LINK_SEND],
            // La causa va aparte de la forma: lo que se mide es que mandar la
            // lista como objeto no amplía el menú, no si hay motivo.
            'staff_review_reason' => StaffReviewAuthority::TEAM_ONLY_OPERATION,
        ]);

        $r->assertOk();
        // `payment_link_send` cae porque el motor de pagos está apagado: el
        // permiso lo da el turno, no la forma en que se pidió.
        $this->assertSame([SalesIntents::TOOL_STAFF_REVIEW], $r->json('applied.tools_executed'));
        $this->assertSame([SalesIntents::TOOL_PAYMENT_LINK_SEND], $r->json('applied.tools_rejected'));
        $this->assertSame(0, $this->paymentLinkMessages()->count(), 'Ningún enlace de pago pudo colarse.');
    }

    // ═══ FAMILIA 2 — EL TOKEN ATA DECIDE CON COMMIT ══════════════════════════

    /**
     * El token describe un instante: esta conversación, este mensaje, esta
     * fase. Si no coincide, la propuesta contesta a un mundo que ya no existe
     * y no se ejecuta a ciegas.
     */
    #[DataProvider('badTokenProvider')]
    public function test_a_commit_whose_token_does_not_match_this_turn_is_refused(string $caso, string $motivo): void
    {
        [$token, $m] = $this->brokenTokenAndTurn($caso);

        $r = $this->commit($m, [], [], ['decide_token' => $token, 'idempotency_key' => 'k-'.$caso]);

        $r->assertStatus(422)
            ->assertJsonPath('code', 'stale_decision')
            ->assertJsonPath('detail.reason', $motivo);

        $this->assertSame(0, $this->outbound()->count(), 'Un token que no ata este turno no manda nada.');
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** @return array<string, array{string,string}> */
    public static function badTokenProvider(): array
    {
        return [
            'de otra conversacion' => ['otra_conversacion', 'decide_token_conversation_mismatch'],
            'de otro mensaje de este chat' => ['otro_mensaje', 'decide_token_message_mismatch'],
            'con la firma cambiada' => ['firma_alterada', 'decide_token_bad_signature'],
            'con el cuerpo cambiado' => ['cuerpo_alterado', 'decide_token_bad_signature'],
            'que no es un token' => ['basura', 'decide_token_malformed'],
            'caducado' => ['caducado', 'decide_token_expired'],
        ];
    }

    /** Sin billete no se entra, y da igual si falta o viene vacío. */
    #[DataProvider('missingTokenProvider')]
    public function test_a_commit_without_a_usable_decide_token_never_reaches_the_service(?string $token): void
    {
        $m = $this->inbound('hola');

        $payload = [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => 'k-sin-billete',
            'conversation_id' => $this->conversation->id,
            'proposal' => $this->benignProposal(),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ];
        if ($token !== null) {
            $payload['decide_token'] = $token;
        }

        $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->h())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decide_token']);

        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** @return array<string, array{?string}> */
    public static function missingTokenProvider(): array
    {
        return ['ausente del sobre' => [null], 'presente pero vacio' => ['']];
    }

    // ═══ FAMILIA 3 — IDEMPOTENCIA Y REPETICIÓN ═══════════════════════════════

    /** Una reentrega del mismo evento no es un segundo mensaje para la persona. */
    public function test_the_same_idempotency_key_never_runs_twice(): void
    {
        $m = $this->inbound('cuentame de los planes');
        $this->commit($m, ['reply_draft' => 'Primera respuesta de este turno.'])->assertOk();

        $r = $this->commit($m, ['reply_draft' => 'Primera respuesta de este turno.']);

        $r->assertStatus(409)->assertJsonPath('code', 'already_committed');
        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame(1, MarketingAiAction::count());
    }

    /**
     * Y si la segunda llega con OTRA propuesta, gana la primera: ya se ejecutó.
     * Lo contrario permitiría reescribir a posteriori lo que la persona ya leyó.
     */
    public function test_the_same_idempotency_key_with_a_different_proposal_keeps_the_first_answer(): void
    {
        $m = $this->inbound('cuentame de los planes');
        $this->commit($m, ['reply_draft' => 'Primera respuesta de este turno.'])->assertOk();

        $r = $this->commit($m, [
            'reply_draft' => 'Olvida lo anterior, te escribo otra cosa completamente distinta.',
            'next_state' => P::CLOSING,
        ]);

        $r->assertStatus(409)
            ->assertJsonPath('code', 'already_committed')
            ->assertJsonPath('detail.ai_action_id', MarketingAiAction::first()->id);

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('Primera respuesta de este turno.', $this->lastOutboundBody());
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase, 'La segunda no movió la fase.');
    }

    /**
     * Este caso NO defiende una regla: documenta un hueco.
     *
     * La idempotencia está anclada SOLO a la clave que elige quien llama. Dos
     * commits del mismo turno con claves distintas producen dos mensajes para
     * una sola pregunta de la persona; lo único que se interpone es la guardia
     * de repetición, y sólo si los textos se parecen. Ver findings.
     */
    public function test_idempotency_is_anchored_only_to_the_key_the_caller_chooses(): void
    {
        $m = $this->inbound('cuentame de los planes');
        $this->commit($m, ['reply_draft' => 'Primera respuesta de este turno.'])->assertOk();

        $r = $this->commit($m, [
            'reply_draft' => 'Y ademas tenemos clases grupales por la manana y por la tarde.',
        ], [], ['idempotency_key' => 'otra-clave-para-el-mismo-turno']);

        $r->assertOk();
        $this->assertSame(2, $this->outbound()->count(), 'Hoy salen DOS mensajes para un solo mensaje entrante.');
        $this->assertSame([$m->id, $m->id], MarketingAiAction::orderBy('id')->pluck('source_event_id')->all(),
            'Las dos filas apuntan al mismo turno: la evidencia del hueco.');
    }

    /** Dos veces el mismo texto en el mismo rato no sale: se anota y se calla. */
    public function test_a_reply_too_similar_to_a_recent_one_is_not_sent_again(): void
    {
        $a = $this->inbound('hola');
        $this->commit($a, ['reply_draft' => 'Claro, te cuento con gusto lo que necesites.'])->assertOk();

        $b = $this->inbound('y que mas me puedes decir');
        $r = $this->commit($b, ['reply_draft' => 'Claro, te cuento con gusto lo que necesites.']);

        // 200, no 422: el turno se ejecutó. Lo que ya no se hace es callarse:
        // la repetición no sale, pero sale otra cosa.
        $r->assertOk();

        $this->assertSame(2, $this->outbound()->count(), 'la persona se quedó sin respuesta');
        $this->assertNotSame(
            'Claro, te cuento con gusto lo que necesites.',
            MarketingMessage::where('conversation_id', $this->conversation->id)
                ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body,
            'La persona no puede leer dos veces lo mismo.',
        );
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase,
            'La fase no avanza sobre una respuesta que no se envió.');
    }

    /** Contestar al mensaje de antes es contestar a una pregunta ya superada. */
    public function test_a_commit_pointing_at_an_older_message_of_the_chat_is_blocked_as_superseded(): void
    {
        $viejo = $this->inbound('hola');
        $this->commit($viejo, ['reply_draft' => 'Respuesta al primer mensaje.'])->assertOk();
        $nuevo = $this->inbound('perdon, mejor dime los horarios');
        $this->abreTurno($nuevo);

        $r = $this->commit($nuevo, ['reply_draft' => 'Respuesta pensada para el mensaje anterior.'], [], [
            'source_event_id' => $viejo->id, 'idempotency_key' => 'k-cruzada',
        ]);

        $r->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'superseded')
            ->assertJsonPath('superseded_by', $nuevo->id);

        $this->assertSame(1, $this->outbound()->count());
        $this->assertSame('skipped', MarketingAiAction::latest('id')->first()->status);
    }

    /** Un mensaje de otro chat no se responde aquí, ni allí. */
    public function test_a_commit_pointing_at_a_message_of_another_conversation_answers_in_neither(): void
    {
        [$otraConv, $ajeno] = $this->otherConversation('3007776655');
        $m = $this->inbound('hola');

        $r = $this->commit($m, [], [], ['source_event_id' => $ajeno->id, 'idempotency_key' => 'k-msg-ajeno']);

        $r->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', 'message_not_in_conversation');

        $this->assertSame(0, $this->outbound()->count(), 'Nada salió por este chat.');
        $this->assertSame(0, MarketingMessage::where('conversation_id', $otraConv->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), 'Ni por el ajeno.');
    }

    /** El origen decide cómo se calcula la idempotencia: no se acepta uno que la v1 no emite. */
    #[DataProvider('unknownSourceTypeProvider')]
    public function test_a_source_type_the_v1_does_not_emit_is_refused(string $source): void
    {
        $m = $this->inbound('hola');

        $r = $this->commit($m, [], [], ['source_type' => $source]);

        $r->assertStatus(422)->assertJsonValidationErrors(['source_type']);
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** @return array<string, array{string}> */
    public static function unknownSourceTypeProvider(): array
    {
        // `followup` existe en el vocabulario pero es de la v2: reservado no es permitido.
        return ['reservado para la v2' => ['followup'], 'inventado' => ['sms_blast'], 'vacio' => ['']];
    }

    // ═══ FAMILIA 4 — QUIÉN MANDA EN LA CONVERSACIÓN ══════════════════════════

    /**
     * Entre decide y commit pasan segundos. Lo que cambie en ese hueco gana a
     * cualquier propuesta: es el motivo por el que estos dos endpoints existen
     * separados.
     *
     * @param  array<string,mixed>  $cambios
     */
    #[DataProvider('authorityProvider')]
    public function test_authority_gained_while_the_model_thought_silences_the_answer(
        string $sujeto,
        array $cambios,
        string $motivo,
    ): void {
        $m = $this->inbound('hola, cuentame de los planes');
        $token = $this->decide($m)->json('decide_token');

        // El cambio ocurre DESPUÉS de decidir: así es como pasa de verdad.
        $this->{$sujeto}->forceFill($cambios)->save();

        $r = $this->commit($m, ['reply_draft' => 'Una respuesta que no debería llegar.'], [], ['decide_token' => $token]);

        $r->assertOk()
            ->assertJsonPath('outcome', 'blocked')
            ->assertJsonPath('blocked_reason', $motivo)
            ->assertJsonPath('message_id', null);

        $this->assertSame(0, $this->outbound()->count(), 'Nadie puede recibir el mensaje de un turno bloqueado.');
        $accion = MarketingAiAction::latest('id')->first();
        $this->assertSame('skipped', $accion->status);
        $this->assertSame($motivo, $accion->metadata['blocked_reason'], 'La fila deja por qué se calló.');
    }

    /** @return array<string, array{string,array,string}> */
    public static function authorityProvider(): array
    {
        return [
            'un asesor tomó el chat' => ['conversation', ['human_takeover' => true, 'human_takeover_source' => 'manual'], 'human_takeover'],
            'apagaron la IA' => ['conversation', ['ai_enabled' => false], 'ai_disabled'],
            'cerraron la conversación' => ['conversation', ['status' => 'closed'], 'conversation_closed'],
            'la persona pidió que no le escriban' => ['lead', ['do_not_contact' => true], 'do_not_contact'],
            'la persona retiró el consentimiento' => ['lead', ['consent_status' => MarketingLead::CONSENT_DENIED], 'do_not_contact'],
        ];
    }

    /**
     * Este caso NO defiende una regla: documenta un hueco.
     *
     * `ineligibleReason()` sólo se para ante `human_takeover_source === 'manual'`.
     * Con la bandera puesta por cualquier otra vía la máquina sigue contestando:
     * hoy lo que salva a producción es la SEGUNDA bandera (`ai_enabled`), que la
     * herramienta de escalado apaga a la vez. Ver findings.
     */
    public function test_a_takeover_not_flagged_as_manual_does_not_reach_the_eligibility_gate(): void
    {
        $this->conversation->forceFill([
            'human_takeover' => true, 'human_takeover_source' => 'agent_escalation', 'ai_enabled' => true,
        ])->save();
        $m = $this->inbound('hola');

        $r = $this->commit($m, ['reply_draft' => 'La maquina sigue hablando con un asesor al mando.']);

        $r->assertOk()->assertJsonPath('outcome', 'dry_run');
        $this->assertSame(1, $this->outbound()->count(), 'Hoy el mensaje SALE pese al human_takeover.');
        $this->assertTrue((bool) $this->conversation->fresh()->human_takeover);
    }

    /**
     * Este caso NO defiende una regla: documenta un hueco.
     *
     * Una derivación autorizada —la persona pidió hablar con alguien y el
     * backend lo corroboró en su texto— sólo escribe la FASE. Nadie toma el
     * chat, la IA sigue encendida, y el turno siguiente la máquina contesta
     * como si no hubiera prometido nada. Ver findings.
     */
    public function test_an_authorised_handoff_does_not_stop_the_next_machine_turn(): void
    {
        $pide = $this->inbound('quiero hablar con una persona por favor');
        $this->commit($pide, ['next_state' => P::HUMAN_HANDOFF, 'reply_draft' => 'Claro, te paso con alguien del equipo.'])
            ->assertOk()
            ->assertJsonPath('commercial_phase.to', P::HUMAN_HANDOFF);

        $c = $this->conversation->fresh();
        $this->assertFalse((bool) $c->human_takeover, 'Nadie tomó la conversación.');
        $this->assertTrue((bool) $c->ai_enabled, 'Y la IA sigue encendida.');

        $siguiente = $this->inbound('vale, gracias, y donde quedan?');
        $r = $this->commit($siguiente, ['next_state' => P::RECOMMENDATION, 'reply_draft' => 'Estamos muy cerca del parque principal.']);

        $r->assertOk()->assertJsonPath('commercial_phase.to', P::RECOMMENDATION);
        $this->assertSame(2, $this->outbound()->count(), 'La máquina contestó después de prometer una persona.');
    }

    /** Si el chat se mudó de canal, el mensaje no encuentra por dónde salir. */
    public function test_a_conversation_moved_to_another_channel_delivers_nothing(): void
    {
        $m = $this->inbound('hola');
        $token = $this->decide($m)->json('decide_token');
        $this->conversation->forceFill(['channel' => 'instagram'])->save();

        $r = $this->commit($m, ['reply_draft' => 'Un mensaje que no tiene por donde salir.'], [], ['decide_token' => $token]);

        $r->assertOk()->assertJsonPath('outcome', 'failed')->assertJsonPath('message_id', null);
        $this->assertSame(0, $this->outbound()->count());
    }

    // ═══ FAMILIA 5 — EL VEREDICTO DEL CRITIC ═════════════════════════════════

    /**
     * El Critic habla en claves fijas para poder medirlo. Prosa libre o una
     * dimensión inventada no es un veredicto: es ruido, y cierra la puerta.
     *
     * @param  array<string,mixed>  $veneno
     */
    #[DataProvider('malformedCriticProvider')]
    public function test_a_verdict_outside_the_critic_vocabulary_never_reaches_the_service(
        array $veneno,
        string $campoConError,
    ): void {
        $m = $this->inbound('hola');

        $r = $this->commit($m, [], $veneno);

        $r->assertStatus(422)->assertJsonValidationErrors([$campoConError]);
        $this->assertNull($r->json('code'));
        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** @return array<string, array{array,string}> */
    public static function malformedCriticProvider(): array
    {
        return [
            'fallo duro inventado' => [['hard_fail' => 'porque_si'], 'critic.hard_fail'],
            'dimension inventada' => [['issues' => ['vibes']], 'critic.issues.0'],
            'nueve issues' => [['issues' => ['relevance', 'empathy', 'naturalness', 'context_use', 'sales_judgment', 'pressure_control', 'next_step', 'question_discipline', 'repetition']], 'critic.issues'],
            'issues como objeto' => [['issues' => ['primera' => 'relevance']], 'critic.issues'],
            'septimo intento' => [['attempt' => 7], 'critic.attempt'],
            'notas de 3000' => [['notes' => str_repeat('n', 3000)], 'critic.notes'],
            'veredicto a medias' => [['verdict' => 'maybe'], 'critic.verdict'],
        ];
    }

    /**
     * Un veredicto que se contradice se resuelve por el lado que no envía: el
     * borrador del modelo muere y sale, si existe, el texto curado de Laravel.
     */
    public function test_a_pass_that_declares_a_hard_fail_takes_the_failure_path(): void
    {
        $m = $this->inbound('cuanto vale el plan');

        $r = $this->commit($m, ['reply_draft' => 'Este borrador no puede salir jamas.'], [
            'verdict' => 'pass', 'hard_fail' => 'invented_price',
        ]);

        $r->assertOk()
            ->assertJsonPath('outcome', 'curated_fallback')
            ->assertJsonPath('fallback_mode', 'SAFE_CURATED_REPLY');

        $this->assertSame(1, $this->outbound()->count());
        $this->assertStringNotContainsString('no puede salir jamas', $this->lastOutboundBody(),
            'El borrador que el Critic tumbó no sale ni disfrazado.');
        /*
         * Y NO queda para una persona: salió el texto curado, así que quien
         * preguntó tiene respuesta. El hard_fail se juzga arriba —el camino
         * fue el del fallo y el borrador no salió—; marcar la conversación es
         * otra decisión, y depende de si alguien se quedó sin contestar.
         */
        $this->assertFalse((bool) $this->conversation->fresh()->staff_review_pending);
        $this->assertSame('fail', MarketingAiAction::latest('id')->first()->metadata['critic']['verdict']);
    }

    /**
     * Sin veredicto no se apunta un aprobado.
     *
     * n8n emite todas las claves y manda en null las que no aplican: un bloque
     * ausente y uno todo en null son lo mismo —nadie juzgó— y anotarlos como
     * `pass` envenenaría la única métrica que mide al Critic.
     */
    #[DataProvider('unjudgedCriticProvider')]
    public function test_a_verdict_nobody_emitted_is_not_recorded_as_an_approval(?array $critic): void
    {
        $m = $this->inbound('hola');

        $payload = [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => $this->benignProposal(),
        ];
        if ($critic !== null) {
            $payload['critic'] = $critic;
        }

        $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->h())->assertOk();

        $this->assertSame(1, $this->outbound()->count(), 'El turno se ejecuta igual: el Critic es opcional.');
        $this->assertArrayNotHasKey('critic', MarketingAiAction::latest('id')->first()->metadata,
            'Pero no se inventa un veredicto que nadie emitió.');
    }

    /** @return array<string, array{?array}> */
    public static function unjudgedCriticProvider(): array
    {
        return [
            'bloque ausente' => [null],
            'bloque todo en null' => [['verdict' => null, 'attempt' => null, 'score' => null, 'issues' => null, 'hard_fail' => null]],
        ];
    }

    // ═══ FAMILIA 6 — LA FASE ═════════════════════════════════════════════════

    /**
     * Una fase del enum no es una fase alcanzable desde donde está la
     * conversación. El grafo lo recalcula Laravel con los hechos de AHORA.
     */
    #[DataProvider('illegalPhaseProvider')]
    public function test_a_phase_that_is_legal_in_the_enum_but_not_from_here_is_refused(string $destino): void
    {
        $m = $this->inbound('hola, cuentame de los planes');

        $r = $this->commit($m, ['next_state' => $destino, 'reply_draft' => 'Un texto cualquiera de este turno.']);

        $r->assertStatus(422)
            ->assertJsonPath('code', 'illegal_transition')
            ->assertJsonPath('detail.proposed', $destino)
            ->assertJsonPath('detail.from', P::RECOMMENDATION);

        $this->assertSame(0, $this->outbound()->count());
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase);
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** @return array<string, array{string}> */
    public static function illegalPhaseProvider(): array
    {
        return [
            // Sin motor de pagos, el traspaso a cobro describe algo que el sistema no puede hacer.
            'saltar al cobro' => [P::PAYMENT_HANDOFF],
            // Nadie gana una venta desde una pregunta de información.
            'darse la venta por ganada' => [P::WON],
            'volver al principio' => [P::NEW_LEAD],
        ];
    }

    /**
     * Derivar a una persona no es una fase del embudo: es una excepción que
     * autoriza Laravel leyendo lo que la persona escribió de verdad.
     */
    #[DataProvider('unauthorisedHandoffProvider')]
    public function test_a_handoff_the_person_never_asked_for_is_refused(array $veneno, string $negativa): void
    {
        $m = $this->inbound('hola, cuanto cuesta el gimnasio');

        // El menú del turno ni siquiera menciona la derivación.
        $this->assertNotContains(P::HUMAN_HANDOFF, $this->decide($m)->json('allowed_transitions'));

        $r = $this->commit($m, $veneno + ['reply_draft' => 'Te paso con alguien del equipo.']);

        $r->assertStatus(422)
            ->assertJsonPath('code', 'unauthorized_handoff')
            ->assertJsonPath('detail.handoff_refusal', $negativa);

        $this->assertSame(0, $this->outbound()->count(), 'Ni se deriva ni se promete derivar.');
        $this->assertSame(P::RECOMMENDATION, $this->conversation->fresh()->commercial_phase);
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
    }

    /** @return array<string, array{array,string}> */
    public static function unauthorisedHandoffProvider(): array
    {
        return [
            'la fase, sin motivo' => [['next_state' => P::HUMAN_HANDOFF], 'handoff_reason_missing'],
            'un motivo que el modelo no puede afirmar' => [
                ['next_state' => P::HUMAN_HANDOFF, 'human_handoff_reason' => 'policy_required_escalation'],
                'handoff_reason_not_proposable_by_model',
            ],
            'el motivo correcto sin prueba en el texto' => [
                ['next_state' => P::HUMAN_HANDOFF, 'human_handoff_reason' => 'explicit_human_request'],
                'handoff_not_corroborated',
            ],
            'la bandera suelta, sin cambiar de fase' => [['human_handoff_requested' => true], 'handoff_reason_missing'],
        ];
    }

    /**
     * Este caso NO defiende una regla: documenta un hueco.
     *
     * `DO_NOT_CONTACT` es alcanzable desde cualquier fase y no se corrobora con
     * nada —al revés que `HUMAN_HANDOFF`, que exige prueba en el texto—. Una
     * sola palabra del modelo deja la conversación en una fase TERMINAL: el
     * lead queda como perdido, la persona sigue siendo contactable (nadie pidió
     * nada) y todos los turnos siguientes mueren en `illegal_transition`. Ver
     * findings.
     */
    public function test_one_enum_value_from_the_model_freezes_the_conversation_for_good(): void
    {
        $m = $this->inbound('hola, cuanto cuesta el gimnasio');

        $this->commit($m, ['next_state' => P::DO_NOT_CONTACT, 'reply_draft' => 'Con gusto te cuento lo que necesites.'])
            ->assertOk()
            ->assertJsonPath('commercial_phase.to', P::DO_NOT_CONTACT);

        $c = $this->conversation->fresh();
        $this->assertSame(P::DO_NOT_CONTACT, $c->commercial_phase);
        $this->assertSame('lost', $c->lead_stage);
        $this->assertFalse((bool) $this->lead->fresh()->do_not_contact, 'Nadie pidió nada: el opt-out real no existe.');
        $this->assertSame(1, $this->outbound()->count(), 'Y la respuesta salió igual.');

        $siguiente = $this->inbound('sigo interesado, me puedes ayudar?');
        $this->assertSame([P::DO_NOT_CONTACT], $this->decide($siguiente)->json('allowed_transitions'));

        $r = $this->commit($siguiente, ['next_state' => P::RECOMMENDATION, 'reply_draft' => 'Claro que si, te ayudo con lo que necesites.']);
        $r->assertStatus(422)->assertJsonPath('code', 'illegal_transition');
        $this->assertSame(1, $this->outbound()->count(), 'La conversación quedó muda para siempre.');
    }

    /**
     * Este caso NO defiende una regla: documenta un hueco.
     *
     * `mark_do_not_contact` está en el menú de todos los turnos y nadie
     * contrasta la petición con el texto: sobre un «quiero pagar hoy» el modelo
     * puede silenciar a esa persona de forma permanente. El guardrail SÍ añade
     * la herramienta cuando detecta la intención, pero nunca la quita cuando no
     * la hay. Ver findings.
     */
    public function test_marking_a_person_as_do_not_contact_needs_no_corroboration(): void
    {
        $m = $this->inbound('quiero pagar el plan mensual hoy mismo');

        $r = $this->commit($m, [
            'reply_draft' => 'Perfecto, te ayudo con eso ahora mismo.',
            'tools_requested' => [SalesIntents::TOOL_MARK_DNC],
        ]);

        $r->assertOk();
        $this->assertSame([SalesIntents::TOOL_MARK_DNC], $r->json('applied.tools_executed'));
        $lead = $this->lead->fresh();
        $this->assertTrue((bool) $lead->do_not_contact, 'Sin que nadie lo pidiera.');
        $this->assertSame(MarketingLead::CONSENT_DENIED, $lead->consent_status);

        // Y a partir de aquí la persona ya no puede ser atendida por el canal.
        $siguiente = $this->inbound('hola? sigo esperando');
        $this->decide($siguiente)->assertStatus(422)->assertJsonPath('reason', 'do_not_contact');
    }

    // ═══ FAMILIA 7 — CONCURRENCIA ════════════════════════════════════════════

    /**
     * Dos commits del mismo turno a la vez.
     *
     * La forma honesta de simularlo en este entorno: tomar el MISMO cerrojo de
     * conversación que usa la ingesta antes de llamar, que es exactamente el
     * estado en el que estaría el segundo commit mientras el primero trabaja.
     * Lo que importa es que el segundo no duplique nada, y no lo hace.
     *
     * Lo que no es correcto es CÓMO lo dice: hoy la espera se agota con un 500
     * pelado, sin `code` que n8n pueda distinguir de un error de contrato. Ver
     * findings.
     */
    public function test_a_turn_whose_conversation_is_busy_never_doubles_the_answer(): void
    {
        $m = $this->inbound('hola, cuentame de los planes');
        $token = $this->decide($m)->json('decide_token');

        $cerrojo = Cache::lock('marketing:conversation:'.$this->conversation->id, 60);
        $cerrojo->get();

        try {
            $r = $this->commit($m, ['reply_draft' => 'Una respuesta que no debe duplicarse.'], [], ['decide_token' => $token]);

            $this->assertSame(500, $r->status(), 'Hoy la espera agotada sale como 500 pelado (ver findings).');
            $this->assertNull($r->json('code'), 'Sin código que n8n pueda interpretar.');
            $this->assertSame(0, $this->outbound()->count(), 'Lo que sí se cumple: no hay mensaje duplicado.');
            $this->assertSame(0, MarketingAiAction::count(), 'Ni fila de acción a medias.');
        } finally {
            $cerrojo->release();
        }
    }

    // ── Utilidades del caso ──────────────────────────────────────────────────

    /**
     * El turno que se va a cometer y el token averiado que lo acompaña.
     *
     * Los dos se construyen juntos porque el orden importa: el mensaje que se
     * comete tiene que ser el ÚLTIMO de la conversación, o el commit moriría
     * antes por «superado» y nunca llegaría a mirar el token.
     *
     * @return array{string, MarketingMessage}
     */
    private function brokenTokenAndTurn(string $caso): array
    {
        if ($caso === 'otra_conversacion') {
            [$otraConv, $otroMsg] = $this->otherConversation('3009998877');
            $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
                'conversation_id' => $otraConv->id, 'message_id' => $otroMsg->id,
            ], $this->h())->json('decide_token');

            return [$token, $this->inbound('hola, cuentame de los planes')];
        }

        if ($caso === 'otro_mensaje') {
            // El token de un turno anterior de ESTA persona, emitido cuando ese
            // turno todavía era el último.
            $anterior = $this->inbound('hola');
            $token = (string) $this->decide($anterior)->json('decide_token');

            return [$token, $this->inbound('perdon, mejor dime los horarios')];
        }

        $m = $this->inbound('hola, cuentame de los planes');

        if ($caso === 'basura') {
            return ['esto-no-es-un-token', $m];
        }

        $bueno = (string) $this->decide($m)->json('decide_token');

        return [match ($caso) {
            'firma_alterada' => substr($bueno, 0, -1).(str_ends_with($bueno, 'a') ? 'b' : 'a'),
            'cuerpo_alterado' => $this->flipBodyChar($bueno),
            // El token describe un instante y dura dos minutos.
            'caducado' => tap($bueno, fn () => $this->travel(121)->seconds()),
        }, $m];
    }

    /** Un carácter distinto en el cuerpo firmado: la firma deja de cuadrar. */
    private function flipBodyChar(string $token): string
    {
        [$cuerpo, $firma] = explode('.', $token, 2);

        return substr($cuerpo, 0, -1).(str_ends_with($cuerpo, 'A') ? 'B' : 'A').'.'.$firma;
    }

    /**
     * Una segunda conversación REAL, con su lead y su mensaje entrante.
     *
     * @return array{MarketingConversation, MarketingMessage}
     */
    private function otherConversation(string $telefono): array
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $telefono,
            'meta_user_id' => '57'.$telefono, 'name' => 'Otra persona', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION,
        ]);
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola, escribo desde otro chat',
            'meta_message_id' => 'otra.'.$telefono.'.'.uniqid(), 'status' => 'received',
        ]);

        return [$conversation, $message];
    }
}
