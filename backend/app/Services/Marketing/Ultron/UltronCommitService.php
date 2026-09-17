<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentDecisionValidator;
use App\Services\Marketing\SalesAgentGuardrailService;
use App\Services\Marketing\SalesConversationMemoryService;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesIntents;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * La única puerta por la que una propuesta de ULTRON llega a ejecutarse.
 *
 * n8n propone; aquí se decide. Todo lo que entra es una SUGERENCIA, incluido el
 * texto: nada de lo que manda el workflow se ejecuta sin volver a pasar por el
 * validador y los guardrails que ya gobiernan al cerebro local.
 *
 * El orden importa y no es negociable:
 *
 *   0  validar la forma del request
 *   1  idempotencia (clave UNIQUE en base de datos, no una comprobación lógica)
 *   2  cerrojo de conversación — el MISMO que usan la ingesta y el job
 *   3  recargar lead/conversación/mensaje de AHORA
 *   4  revalidar los hechos duros: DNC, takeover, ai_enabled, propiedad
 *   5  ¿llegó algo más nuevo? entonces esta respuesta ya no va
 *   6  recalcular transiciones legales AHORA, no fiarse de las del decide
 *   7  comprobar el token: ¿decidió sobre el mundo que sigue existiendo?
 *   8  adaptar la propuesta al objeto de decisión canónico
 *   9  validar contenido (sin cifras: el draft todavía lleva marcadores)
 *  10  resolver el plan y SUSTITUIR los marcadores por el precio real
 *  11  guardrails
 *  12  persistir decisión, fase y memoria
 *  13  ejecutar las herramientas que sobrevivieron
 *  14  enviar por el outbox de siempre
 *
 * Entre el paso 3 y el 7 se comprueba todo lo que puede haber cambiado mientras
 * el modelo pensaba. Son quince segundos en los que un asesor puede haber
 * tomado la conversación o el cliente puede haber pedido que no le escriban, y
 * ejecutar una decisión tomada antes de eso es exactamente el fallo que este
 * servicio existe para evitar.
 */
class UltronCommitService
{
    /** Cuánto se espera por el turno de la conversación antes de rendirse. */
    private const LOCK_WAIT_SECONDS = 10;

    private const LOCK_TTL_SECONDS = 60;

    public function __construct(
        private readonly UltronDecideService $decide,
        private readonly UltronDecideToken $tokens,
        private readonly UltronDraftPlaceholders $placeholders,
        private readonly CommercialPhaseMachine $phases,
        private readonly SalesAgentDecisionValidator $validator,
        private readonly SalesAgentGuardrailService $guardrail,
        private readonly OutboundContentGuard $contentGuard,
        private readonly HumanHandoffAuthority $handoff,
        private readonly SalesConversationMemoryService $memory,
        private readonly SalesConversationReplyService $replies,
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly MarketingKnowledgeBaseService $knowledge,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  ya validado en su FORMA por el controlador
     * @return array<string,mixed>
     *
     * @throws UltronCommitException
     */
    public function commit(array $payload): array
    {
        $conversationId = (int) $payload['conversation_id'];
        $idempotencyKey = (string) $payload['idempotency_key'];

        // 1) ¿Esto ya se hizo? Antes incluso de pedir el cerrojo.
        if ($previa = $this->findCommitted($idempotencyKey)) {
            throw UltronCommitException::make(
                'already_committed',
                'Esta decisión ya se ejecutó. No se repite.',
                409,
                ['ai_action_id' => (int) $previa->id, 'status' => $previa->status],
            );
        }

        // 2) Un solo commit a la vez por conversación. Mismo cerrojo que la
        // ingesta y el job del agente: así ULTRON entra en la exclusión mutua
        // que ya existe en lugar de esquivarla por llegar vía HTTP.
        return Cache::lock('marketing:conversation:'.$conversationId, self::LOCK_TTL_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->execute($payload));
    }

    /** @return array<string,mixed> */
    private function execute(array $payload): array
    {
        $conversationId = (int) $payload['conversation_id'];
        $sourceEventId = (int) $payload['source_event_id'];
        $idempotencyKey = (string) $payload['idempotency_key'];
        $proposal = (array) ($payload['proposal'] ?? []);
        $critic = (array) ($payload['critic'] ?? []);

        // Dentro del cerrojo, por si otro commit ganó la carrera mientras
        // esperábamos turno.
        if ($previa = $this->findCommitted($idempotencyKey)) {
            throw UltronCommitException::make(
                'already_committed',
                'Esta decisión ya se ejecutó. No se repite.',
                409,
                ['ai_action_id' => (int) $previa->id, 'status' => $previa->status],
            );
        }

        // 3) El estado de AHORA, no el que había cuando se pidió el contexto.
        $conversation = MarketingConversation::with('lead')->find($conversationId);
        $message = MarketingMessage::find($sourceEventId);

        if ($conversation === null || $message === null) {
            throw UltronCommitException::make('not_found', 'La conversación o el mensaje ya no existen.', 404);
        }

        $lead = $conversation->lead;
        Context::add('conversation_id', $conversation->id);

        // 4) Los hechos duros ganan a cualquier propuesta.
        if ($reason = $this->decide->ineligibleReason($conversation, $message)) {
            return $this->blocked($conversation, $message, $payload, $reason);
        }

        // 5) Alguien escribió después. Esta respuesta contesta a una pregunta
        // que la persona ya superó: no sale.
        if ($newer = $this->decide->supersededBy($conversation, $message)) {
            return $this->blocked($conversation, $message, $payload, 'superseded', ['superseded_by' => $newer]);
        }

        // 6) Transiciones legales recalculadas con los hechos de ahora.
        $currentPhase = $this->decide->currentPhase($conversation);
        $phaseContext = $this->decide->phaseContext($conversation, $message);
        $allowedTransitions = $this->phases->allowedTransitions($currentPhase, $phaseContext);

        // 7) ¿El token decidió sobre este mismo mundo?
        $token = $this->tokens->verify(
            $payload['decide_token'] ?? null,
            (int) $conversation->id,
            (int) $message->id,
            $currentPhase,
            $allowedTransitions,
            $this->knowledge->version(),
        );

        if (! $token['valid']) {
            throw UltronCommitException::make(
                'stale_decision',
                'La decisión se tomó sobre un estado que ya cambió. Vuelve a pedir contexto.',
                422,
                ['reason' => $token['reason']],
            );
        }

        // ── Camino del critic fallido ─────────────────────────────────────────
        if (($critic['verdict'] ?? 'pass') === 'fail') {
            return $this->handleCriticFailure($conversation, $message, $payload, $currentPhase);
        }

        // 8) La propuesta se convierte en el objeto de decisión canónico. Un
        // adaptador explícito y pequeño: meter a la fuerza un objeto de n8n
        // dentro del validador haría que cualquier cambio del workflow se
        // colara como cambio de contrato.
        $draft = trim((string) ($proposal['reply_draft'] ?? ''));

        if ($draft === '') {
            throw UltronCommitException::make('empty_reply_draft', 'La propuesta no trae respuesta.');
        }

        $nextState = (string) ($proposal['next_state'] ?? $currentPhase);

        /*
         * 8.bis) CERROJO DE DERIVACIÓN. La última barrera, y la que sigue en pie
         * aunque Strategist, Composer, Critic y el propio n8n se equivoquen a la
         * vez: se ejecuta ANTES de cualquier efecto —sin mensaje, sin acción,
         * sin `needs_staff_review`, sin `human_takeover`— y no delega en nada
         * que haya viajado desde el workflow salvo como propuesta a verificar.
         *
         * Va ANTES de la legalidad de la transición a propósito. El menú que
         * firmó el token se calculó con los hechos de Laravel; el veredicto de
         * aquí puede sumar uno más (la persona lo pidió y el motivo lo
         * corrobora), y ese hecho extra sólo entra en la comprobación de
         * legalidad, nunca en el fingerprint: si lo tocara, toda derivación
         * legítima moriría como `stale_decision`.
         */
        $handoffDecision = $this->autorizarHandoff($conversation, $message, $proposal);
        $handoffPropuesto = $nextState === CommercialPhaseMachine::HUMAN_HANDOFF
            || (bool) ($proposal['human_handoff_requested'] ?? false);

        if ($handoffPropuesto && ! $handoffDecision['allowed']) {
            throw UltronCommitException::make(
                'unauthorized_handoff',
                'Derivar a una persona no está autorizado en esta conversación. Resuelve tú.',
                422,
                ['handoff_refusal' => $handoffDecision['refusal'], 'proposed_state' => $nextState],
            );
        }

        $handoffAutorizado = $handoffPropuesto && $handoffDecision['allowed'];

        $contextoLegalidad = $phaseContext;
        $contextoLegalidad['needs_human'] = ($phaseContext['needs_human'] ?? false) || $handoffAutorizado;

        if (! $this->phases->canTransition($currentPhase, $nextState, $contextoLegalidad)) {
            throw UltronCommitException::make(
                'illegal_transition',
                'Esa transición de fase no está permitida desde donde está la conversación.',
                422,
                ['proposed' => $nextState, 'from' => $currentPhase, 'allowed' => $allowedTransitions],
            );
        }

        $placeholdersDesconocidos = $this->placeholders->unknown($draft);
        if ($placeholdersDesconocidos !== []) {
            throw UltronCommitException::make(
                'unknown_placeholder',
                'La respuesta usa marcadores que no existen.',
                422,
                ['unknown' => $placeholdersDesconocidos, 'allowed' => UltronDraftPlaceholders::ALLOWED],
            );
        }

        /*
         * 9) VALIDACIÓN DEL BORRADOR. Todavía lleva marcadores, así que no hay
         * ninguna cifra: si aparece un precio aquí, lo escribió n8n.
         *
         * Primero el guard de salida, que es el mismo que protege
         * `send-message`. Así las DOS puertas por las que entra texto de máquina
         * comparten filtro y código de error, y n8n recibe un motivo que sabe
         * distinguir en vez de un «rechazado» genérico.
         */
        try {
            $this->contentGuard->assertSafe($draft, MarketingMessage::SENDER_AI, [
                'endpoint' => 'internal.marketing.ai.commit',
                'conversation_id' => (int) $conversation->id,
            ], $handoffAutorizado);
        } catch (SalesGuardrailException $e) {
            throw UltronCommitException::make($e->errorCode, $e->getMessage(), 422);
        }

        /*
         * El segundo argumento es el mensaje que la persona escribió de verdad.
         *
         * Sin él, «este turno pide un humano» es una afirmación que llega desde
         * n8n y que nadie puede contrastar: así se escaló un «si por favor» y un
         * «no quiero alguien del equipo». Con él, la etiqueta del modelo es una
         * propuesta y el texto es la prueba.
         */
        $sanitized = $this->validator->sanitize([
            'intent' => $proposal['intent'] ?? SalesIntents::UNKNOWN,
            'confidence' => $proposal['confidence'] ?? 0.5,
            'reply' => $draft,
            'tools_requested' => (array) ($proposal['tools_requested'] ?? []),
            'extracted_fields' => [],
            'missing_fields' => [],
        ], (string) $message->body);

        if ($sanitized['reply'] === null) {
            // El validador tiró el texto: precio inventado, promesa prohibida o
            // intento de acción vetada. No se negocia ni se reintenta.
            ChannelLog::warning('ultron.commit.draft_rejected', [
                'risk_flags' => $sanitized['risk_flags'],
                'conversation_id' => $conversation->id,
            ]);

            throw UltronCommitException::make(
                'draft_rejected',
                'La respuesta propuesta no cumple las reglas de contenido.',
                422,
                ['risk_flags' => $sanitized['risk_flags']],
            );
        }

        // 10) El plan y su precio REAL, leídos ahora.
        $plan = $this->resolvePlan($proposal['recommended_plan_id'] ?? null, $sanitized['reply']);
        $replyFinal = $this->placeholders->resolve($sanitized['reply'], $plan);

        // 11) Guardrails sobre la decisión ya ensamblada.
        $decision = $this->guardrail->apply([
            'ok' => true,
            'intent' => $sanitized['intent'],
            'confidence' => $sanitized['confidence'],
            'reply' => $replyFinal,
            'should_reply' => true,
            'should_send_message' => true,
            'should_generate_payment_link' => false,
            'should_schedule_followup' => false,
            'followup_delay_minutes' => null,
            'should_escalate' => false,
            'needs_staff_review' => (bool) $sanitized['force_escalate'],
            'staff_review_reason' => $sanitized['escalation_reason'],
            'escalation_reason' => $sanitized['escalation_reason'],
            'risk_flags' => $sanitized['risk_flags'],
            'extracted_fields' => [],
            'missing_fields' => [],
            'recommended_action' => SalesIntents::ACTION_REPLY,
            'tools_requested' => $this->allowedTools((array) ($proposal['tools_requested'] ?? [])),
            'safe_to_send' => false,
            'responder' => 'ultron',
        ], $lead);

        // 12) Persistir. La fase solo avanza cuando el commit se acepta.
        $action = $this->persist($conversation, $message, $payload, $decision, $nextState, $plan, [
            'source' => 'external_draft',
            'price_enriched' => $plan !== null && $replyFinal !== $sanitized['reply'],
        ]);

        $this->applyPhase($conversation, $nextState, $proposal, $plan);
        $this->memory->remember($conversation->fresh(), $decision, (string) $message->body);

        // 13) Las herramientas se ejecutan ANTES de mirar si hay respuesta.
        //
        // No es un detalle de orden. Cuando alguien pide que no le escriban, el
        // guardrail hace dos cosas a la vez: deja `reply` en null —correcto, no
        // se le contesta— y deja `mark_do_not_contact` en la lista. Si se
        // comprobara primero si hay algo que enviar, se saldría antes de marcar
        // el opt-out y la petición de la persona se perdería: exactamente lo
        // contrario de lo que pidió.
        $executed = $this->runTools($conversation, $decision);

        if (! ($decision['safe_to_send'] ?? false)) {
            $action->forceFill(['status' => 'skipped'])->save();

            ChannelLog::info('ultron.commit.no_reply', [
                'ai_action_id' => $action->id,
                'conversation_id' => $conversation->id,
                'recommended_action' => $decision['recommended_action'] ?? null,
            ]);

            return [
                'ok' => true,
                'ai_action_id' => (int) $action->id,
                'outcome' => 'no_reply',
                'blocked_reason' => $decision['recommended_action'] ?? 'guardrail_blocked',
                'message_id' => null,
                'provider_message_id' => null,
                'commercial_phase' => ['from' => $currentPhase, 'to' => $nextState],
                'reply_final' => null,
                'applied' => ['tools_executed' => array_values(array_map(fn ($e) => $e['tool'], $executed))],
            ];
        }

        // 14) Envío por el camino de siempre.
        $send = $this->dispatcher->dispatchWhatsapp(
            $lead->fresh(),
            $conversation->channel,
            $replyFinal,
            ['kind' => 'reply', 'origin' => 'ultron', 'ai_action_id' => $action->id],
            MarketingMessage::SENDER_AI,
        );

        $outcome = $send['sent'] ? 'sent' : ($send['dry_run'] ? 'dry_run' : 'failed');
        $this->finalise($action, $outcome, $send);

        ChannelLog::info('ultron.commit.done', [
            'ai_action_id' => $action->id,
            'conversation_id' => $conversation->id,
            'outcome' => $outcome,
            'phase_from' => $currentPhase,
            'phase_to' => $nextState,
        ]);

        return [
            'ok' => true,
            'ai_action_id' => (int) $action->id,
            'outcome' => $outcome,
            'message_id' => $send['message_id'],
            'provider_message_id' => $send['provider_message_id'],
            'commercial_phase' => ['from' => $currentPhase, 'to' => $nextState],
            'reply_final' => $replyFinal,
            'price_source' => $plan === null ? null : [
                'plan_id' => (int) $plan->id,
                'price' => (float) $plan->price,
                'resolved_at_commit' => true,
            ],
            'applied' => [
                'tools_executed' => array_values(array_map(fn ($e) => $e['tool'], $executed)),
                'tools_rejected' => array_values(array_diff(
                    (array) ($proposal['tools_requested'] ?? []),
                    (array) $decision['tools_requested'],
                )),
            ],
        ];
    }

    // ── Critic fallido ────────────────────────────────────────────────────────

    /**
     * El borrador que el critic rechazó NO se envía. Ni se valida, ni se
     * enriquece: se guarda como evidencia y muere ahí.
     *
     * Lo que sale en su lugar lo decide una regla, no un modelo: si existe un
     * texto curado para la intención validada, se usa; si no existe, no se
     * responde y se marca para el equipo. La existencia del texto curado ES el
     * discriminante, y ya estaba escrita.
     *
     * @return array<string,mixed>
     */
    private function handleCriticFailure(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $currentPhase,
    ): array {
        $proposal = (array) ($payload['proposal'] ?? []);
        $lead = $conversation->lead;

        $intent = (string) ($proposal['intent'] ?? SalesIntents::UNKNOWN);
        if (! in_array($intent, SalesAgentDecisionSchema::INTENTS, true)) {
            $intent = SalesIntents::UNKNOWN;
        }

        $curado = $intent === SalesIntents::DO_NOT_CONTACT_REQUEST
            ? null
            : $this->replies->replyFor($intent, ['lead' => $lead, 'channel' => $conversation->channel]);

        $modo = $curado !== null && trim($curado) !== '' ? 'SAFE_CURATED_REPLY' : 'NO_REPLY_AND_HANDOFF';
        $reason = $modo === 'SAFE_CURATED_REPLY' ? 'critic_failed' : 'critic_failed_no_safe_reply';

        $action = $this->persist(
            $conversation,
            $message,
            $payload,
            [
                'intent' => $intent,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                'recommended_action' => SalesIntents::ACTION_REPLY,
                'risk_flags' => ['critic_failed'],
                'tools_requested' => [],
                'needs_staff_review' => true,
                'staff_review_reason' => $reason,
            ],
            // La fase NO avanza: el critic dijo que la respuesta no servía, así
            // que la conversación no ha progresado.
            $currentPhase,
            null,
            [
                'critic' => [
                    'verdict' => 'fail',
                    'attempt' => (int) ($payload['critic']['attempt'] ?? 2),
                    'score' => $payload['critic']['score'] ?? null,
                ],
                'fallback_mode' => $modo,
                // Evidencia de lo que se descartó, para poder revisarlo. No sale.
                'discarded_draft' => mb_substr((string) ($proposal['reply_draft'] ?? ''), 0, 500),
            ],
        );

        $conversation->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason' => $reason,
        ])->save();

        if ($modo === 'NO_REPLY_AND_HANDOFF') {
            $action->forceFill(['status' => 'skipped'])->save();

            ChannelLog::info('ultron.commit.critic_failed', [
                'ai_action_id' => $action->id, 'mode' => $modo, 'conversation_id' => $conversation->id,
            ]);

            return [
                'ok' => true,
                'ai_action_id' => (int) $action->id,
                'outcome' => 'handoff',
                'fallback_mode' => $modo,
                'message_id' => null,
                'provider_message_id' => null,
                'commercial_phase' => ['from' => $currentPhase, 'to' => $currentPhase],
                'reply_final' => null,
            ];
        }

        $send = $this->dispatcher->dispatchWhatsapp(
            $lead->fresh(),
            $conversation->channel,
            $curado,
            ['kind' => 'reply', 'origin' => 'ultron_curated_fallback', 'ai_action_id' => $action->id],
            MarketingMessage::SENDER_AI,
        );

        $outcome = $send['sent'] || $send['dry_run'] ? 'curated_fallback' : 'failed';
        $this->finalise($action, $outcome, $send);

        ChannelLog::info('ultron.commit.critic_failed', [
            'ai_action_id' => $action->id, 'mode' => $modo,
            'conversation_id' => $conversation->id, 'outcome' => $outcome,
        ]);

        return [
            'ok' => true,
            'ai_action_id' => (int) $action->id,
            'outcome' => $outcome,
            'fallback_mode' => $modo,
            'message_id' => $send['message_id'],
            'provider_message_id' => $send['provider_message_id'],
            'commercial_phase' => ['from' => $currentPhase, 'to' => $currentPhase],
            'reply_final' => $curado,
        ];
    }

    // ── Piezas ────────────────────────────────────────────────────────────────

    private function findCommitted(string $key): ?MarketingAiAction
    {
        return MarketingAiAction::where('idempotency_key', $key)->first();
    }

    /**
     * Herramientas que sobreviven: la intersección con lo que la v1 permite.
     * Nunca la unión.
     *
     * @param  string[]  $requested
     * @return string[]
     */
    private function allowedTools(array $requested): array
    {
        return array_values(array_intersect($requested, UltronDecideService::V1_ALLOWED_TOOLS));
    }

    /**
     * El plan que ULTRON recomendó, si lo recomendó y el texto lo necesita.
     *
     * Se vuelve a leer de la base de datos: entre `/decide` y `/commit` pueden
     * haber desactivado el plan o cambiado la tarifa, y el precio que sale al
     * cliente tiene que ser el de ahora.
     */
    private function resolvePlan(mixed $planId, string $draft): ?Plan
    {
        $necesita = $this->placeholders->needsPlan($draft);

        if ($planId === null || $planId === '') {
            if ($necesita) {
                throw UltronCommitException::make(
                    'plan_required_for_placeholder',
                    'La respuesta cotiza un plan pero la propuesta no dice cuál.',
                );
            }

            return null;
        }

        $plan = Plan::find((int) $planId);

        if ($plan === null) {
            throw UltronCommitException::make('plan_not_found', 'El plan recomendado ya no existe.', 422, [
                'recommended_plan_id' => (int) $planId,
            ]);
        }

        if (! (bool) $plan->active) {
            // No se recalcula a otro plan en silencio: sería contestar una
            // recomendación que ULTRON nunca hizo.
            throw UltronCommitException::make('plan_inactive', 'El plan recomendado ya no está activo.', 422, [
                'recommended_plan_id' => (int) $plan->id,
            ]);
        }

        /*
         * Último cerrojo antes de que una cifra salga hacia una persona.
         *
         * El catálogo que ve ULTRON ya está filtrado, pero esto no depende de
         * ese filtro a propósito: aquí llega un id que mandó un modelo, y la
         * pregunta «¿se le puede vender esto a alguien?» tiene que contestarla
         * Laravel mirando la fila, no confiando en quién la eligió. Sin esto,
         * proponer el plan contable de precio 0 bastaría para que el gimnasio
         * cotizara «$0» por WhatsApp.
         */
        if (! $plan->isSellable()) {
            throw UltronCommitException::make('plan_not_sellable', 'Ese plan no está a la venta.', 422, [
                'recommended_plan_id' => (int) $plan->id,
            ]);
        }

        return $plan;
    }

    private function persist(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        array $decision,
        string $nextState,
        ?Plan $plan,
        array $extraMetadata = [],
    ): MarketingAiAction {
        $proposal = (array) ($payload['proposal'] ?? []);

        $metadata = array_merge([
            'message_id' => (int) $message->id,
            'origin' => 'ultron',
            'intent' => $decision['intent'] ?? null,
            'strategy_goal' => $proposal['strategy_goal'] ?? null,
            'next_best_action' => $proposal['next_best_action'] ?? null,
            'main_barrier' => $proposal['main_barrier'] ?? null,
            'recommended_plan_id' => $plan?->id,
            'previous_phase' => $this->decide->currentPhase($conversation),
            'next_phase' => $nextState,
            'risk_flags' => $decision['risk_flags'] ?? [],
            'tools_requested' => $decision['tools_requested'] ?? [],
            'needs_staff_review' => $decision['needs_staff_review'] ?? false,
            'knowledge_version' => $this->knowledge->version(),
        ], $extraMetadata);

        try {
            return MarketingAiAction::create([
                'lead_id' => $conversation->lead_id,
                'conversation_id' => $conversation->id,
                'action_type' => $decision['recommended_action'] ?? SalesIntents::ACTION_REPLY,
                'reason' => $decision['staff_review_reason'] ?? ($decision['intent'] ?? null),
                'confidence' => $decision['confidence'] ?? null,
                'status' => 'executed',
                'source_type' => (string) $payload['source_type'],
                'source_event_id' => (int) $payload['source_event_id'],
                'idempotency_key' => (string) $payload['idempotency_key'],
                'metadata' => array_filter($metadata, fn ($v) => $v !== null),
            ]);
        } catch (QueryException $e) {
            // La restricción UNIQUE ganó la carrera. Es el caso que la hace
            // valer más que una comprobación lógica: dos procesos que pasaron
            // el paso 1 a la vez, y solo uno escribe.
            $previa = $this->findCommitted((string) $payload['idempotency_key']);

            throw UltronCommitException::make(
                'already_committed',
                'Esta decisión ya se ejecutó. No se repite.',
                409,
                ['ai_action_id' => $previa?->id],
            );
        }
    }

    private function applyPhase(
        MarketingConversation $conversation,
        string $nextState,
        array $proposal,
        ?Plan $plan,
    ): void {
        $cambios = [
            'commercial_phase' => $nextState,
            // `lead_stage` sigue existiendo para el CRM de Mercadeo y ahora se
            // deriva de la fase, que sí tiene transiciones legales.
            'lead_stage' => $this->phases->toLeadStage($nextState),
        ];

        $barrera = $proposal['main_barrier'] ?? null;
        if ($this->phases->isBarrier($barrera)) {
            $cambios['main_barrier'] = $barrera;
        }

        if ($plan !== null) {
            $cambios['recommended_plan_id'] = $plan->id;
        }

        $conversation->forceFill($cambios)->save();
    }

    /** @return array<int, array<string,mixed>> */
    private function runTools(MarketingConversation $conversation, array $decision): array
    {
        $executed = [];

        foreach ((array) ($decision['tools_requested'] ?? []) as $tool) {
            $executed[] = match ($tool) {
                SalesIntents::TOOL_STAFF_REVIEW => $this->execStaffReview($conversation, $decision),
                SalesIntents::TOOL_MARK_DNC => $this->execMarkDnc($conversation),
                // Inalcanzable: la lista ya se filtró contra V1_ALLOWED_TOOLS.
                default => ['tool' => $tool, 'status' => 'skipped', 'reason' => 'not_available_in_v1'],
            };
        }

        return $executed;
    }

    private function execStaffReview(MarketingConversation $conversation, array $decision): array
    {
        $reason = $decision['staff_review_reason'] ?? 'staff_review';

        $conversation->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason' => $reason,
        ])->save();

        return ['tool' => SalesIntents::TOOL_STAFF_REVIEW, 'status' => 'created', 'reason' => $reason];
    }

    private function execMarkDnc(MarketingConversation $conversation): array
    {
        $lead = $conversation->lead;

        $lead->forceFill([
            'do_not_contact' => true,
            'consent_status' => MarketingLead::CONSENT_DENIED,
            'consent_source' => $conversation->channel,
            'consent_at' => now(),
        ])->save();

        return ['tool' => SalesIntents::TOOL_MARK_DNC, 'status' => 'executed', 'do_not_contact' => true];
    }

    private function finalise(MarketingAiAction $action, string $outcome, array $send): void
    {
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['outbound'] = array_filter([
            'message_id' => $send['message_id'],
            'sent' => $send['sent'],
            'dry_run' => $send['dry_run'],
            'provider_message_id' => $send['provider_message_id'],
            'reason' => $send['reason'],
        ], fn ($v) => $v !== null);

        $action->forceFill([
            'status' => $outcome === 'failed' ? 'failed' : 'executed',
            'metadata' => $meta,
        ])->save();
    }

    /**
     * La propuesta no se ejecuta y queda constancia de por qué.
     *
     * Se registra con la clave de idempotencia para que un reintento de n8n
     * reciba un 409 en vez de volver a intentarlo: un bloqueo también es una
     * decisión tomada.
     *
     * @return array<string,mixed>
     */
    private function blocked(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $reason,
        array $extra = [],
    ): array {
        $action = $this->persist(
            $conversation,
            $message,
            $payload,
            [
                'intent' => $payload['proposal']['intent'] ?? SalesIntents::UNKNOWN,
                'recommended_action' => SalesIntents::ACTION_NO_REPLY,
                'risk_flags' => [$reason],
                'tools_requested' => [],
            ],
            $this->decide->currentPhase($conversation),
            null,
            array_merge(['blocked_reason' => $reason], $extra),
        );

        $action->forceFill(['status' => 'skipped'])->save();

        ChannelLog::info('ultron.commit.blocked', [
            'ai_action_id' => $action->id,
            'conversation_id' => $conversation->id,
            'reason' => $reason,
        ]);

        return array_merge([
            'ok' => true,
            'ai_action_id' => (int) $action->id,
            'outcome' => 'blocked',
            'blocked_reason' => $reason,
            'message_id' => null,
            'provider_message_id' => null,
            'reply_final' => null,
        ], $extra);
    }

    /**
     * ¿Está autorizada la derivación a una persona, y por qué?
     *
     * El orden importa. Primero los hechos que ya decidió Laravel, porque en
     * esos casos el motivo NO lo pone el modelo: la conversación ya la lleva
     * alguien, o el router de entrada marcó el lead. Solo si Laravel no ha
     * dicho nada se examina la propuesta del modelo, y entonces tiene que
     * pasar la allowlist y —si el motivo es «me lo pidió»— corroborarse contra
     * el texto que la persona escribió de verdad.
     *
     * @param  array<string,mixed>  $proposal
     * @return array{allowed:bool, reason:?string, evidence:?string, refusal:?string}
     */
    private function autorizarHandoff(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $proposal,
    ): array {
        if ((bool) $conversation->human_takeover) {
            return [
                'allowed' => true,
                'reason' => HumanHandoffAuthority::POLICY_REQUIRED_ESCALATION,
                'evidence' => 'human_takeover',
                'refusal' => null,
            ];
        }

        $lead = $conversation->lead;

        if ($lead !== null && (string) $lead->status === MarketingLead::STATUS_NEEDS_HUMAN) {
            return [
                'allowed' => true,
                'reason' => HumanHandoffAuthority::POLICY_REQUIRED_ESCALATION,
                'evidence' => 'lead_status_needs_human',
                'refusal' => null,
            ];
        }

        return $this->handoff->decideProposal(
            is_string($proposal['human_handoff_reason'] ?? null) ? $proposal['human_handoff_reason'] : null,
            (string) $message->body,
        );
    }
}
