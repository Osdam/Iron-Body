<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentDecisionValidator;
use App\Services\Marketing\SalesAgentGuardrailService;
use App\Services\Marketing\SalesConversationMemoryService;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Throwable;

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
        private readonly ConversationMemoryService $memoryService,
        private readonly ReferenceResolver $references,
        private readonly NoveltyGuard $novelty,
        private readonly CustomerIntelligenceService $customers,
        private readonly ComposerStyleGuard $style,
        private readonly SalesConversationMemoryService $memory,
        private readonly SalesConversationReplyService $replies,
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly SalesPaymentGuardrailService $paymentGuardrail,
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
        if (CriticContract::isFail($critic)) {
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
            // El modelo no escribe URLs: los links (pago, app) los pone Laravel en su
            // propio mensaje. Una URL en el borrador es, como mínimo, inventada.
            if (OutboundContentGuard::containsCardDataRequest((string) ($proposal['reply_draft'] ?? ''))) {
                throw SalesGuardrailException::make(OutboundContentGuard::CODE_CARD_DATA_REQUEST, 'El borrador pide datos de tarjeta o claves; el cobro lo hace Wompi en su checkout, nunca el chat.', escalate: true);
            }
            if (OutboundContentGuard::containsUrl((string) ($proposal['reply_draft'] ?? ''))) {
                throw SalesGuardrailException::make(OutboundContentGuard::CODE_URL_IN_REPLY, 'El borrador contiene una URL; los enlaces los envía el CRM en su propio mensaje.', escalate: true);
            }
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

        /*
         * 10.pre) TONO. La persona debe sentir que la ayudan, no que la empujan.
         * Urgencia y escasez inventadas, culpa, vergüenza corporal y testimonios
         * que nadie verificó NO salen: es contenido, como un precio inventado, y
         * se rechaza antes de cualquier efecto. El folleto, las viñetas, las
         * muletillas y la longitud se anotan para medirlos; el Critic los juzga.
         */
        $planesVendibles = $this->memoryService->sellablePlansForMemory();
        $estilo = $this->style->inspect(
            $replyFinal,
            $planesVendibles,
            $this->style->askedForAllPlans((string) $message->body),
            $this->style->askedForDetail((string) $message->body),
        );
        if ($estilo['hard'] !== []) {
            ChannelLog::warning('outbound.style.blocked', [
                'endpoint' => 'internal.marketing.ai.commit', 'conversation_id' => (int) $conversation->id,
                'code' => ComposerStyleGuard::CODE_PRESSURE, 'signals' => $estilo['hard'], 'body_length' => mb_strlen($replyFinal),
            ]);
            throw UltronCommitException::make(
                ComposerStyleGuard::CODE_PRESSURE,
                'La respuesta presiona a la persona: urgencia o escasez inventadas, culpa o testimonios sin fuente.',
                422,
                ['signals' => $estilo['hard']],
            );
        }

        /*
         * 10.bis) NOVEDAD DURA. Una respuesta casi idéntica a una que ya salió
         * no vuelve a salir: así se mandó seis veces el mismo precio a la misma
         * persona. Se compara el texto FINAL (con el precio ya puesto) contra
         * las últimas salidas de máquina, antes de cualquier efecto. La
         * novedad fina —«cuéntame más» que repite beneficios— la juzga el
         * Critic con `novelty.must_not_repeat`; esto es la red de abajo.
         */
        $previas = $this->previousMachineReplies($conversation);
        $duplicadoDe = $this->novelty->isExplicitRepeatRequest((string) $message->body)
            ? null // «me repites la dirección?»: repetir es exactamente lo que pidió.
            : $this->novelty->nearDuplicateOf($replyFinal, $previas);
        $respuestaRepetida = $duplicadoDe !== null;
        if ($respuestaRepetida) {
            // No es una excepción: las herramientas del turno (el opt-out de
            // quien pide que no le escriban) se ejecutan igual. Sólo el TEXTO no
            // sale, y la fase no avanza sobre una respuesta que no se envió.
            ChannelLog::warning('ultron.commit.repeated_reply', [
                'conversation_id' => $conversation->id,
                'similarity' => round($this->novelty->similarity($replyFinal, $previas[$duplicadoDe]), 2),
            ]);
            $nextState = $currentPhase;
        }

        // El referente se resuelve sobre la memoria de ANTES de este turno.
        $memoriaPrevia = $this->memoryService->load($conversation);
        $resolution = $this->references->resolve(
            (string) $message->body,
            $memoriaPrevia,
            $planesVendibles,
        );

        /*
         * 10.ter) A QUIEN YA PAGÓ NO SE LE COTIZA LO MISMO. Anclado al efecto
         * (el plan que este turno cotiza o compromete), no a la etiqueta de
         * fase que elija el modelo, y sólo cuando se sabe seguro que es el plan
         * que ya tiene. No es una excepción: como la novedad, el texto no sale,
         * la fase no avanza y las herramientas del turno se ejecutan igual.
         */
        $perfil = $this->customers->profile($conversation, $message, $memoriaPrevia, $resolution, (string) $sanitized['intent']);
        $revende = $this->customers->isResell($perfil, $plan?->id);
        if ($revende) {
            ChannelLog::warning('ultron.commit.resell_blocked', [
                'conversation_id' => $conversation->id,
                'customer_lifecycle' => $perfil['customer_lifecycle'],
                'current_plan_id' => $perfil['known']['current_plan_id'] ?? $perfil['known']['paid_plan_id'] ?? null,
                'quoted_plan_id' => $plan?->id,
            ]);
            $nextState = $currentPhase;
        }

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

        if ($respuestaRepetida) {
            $decision['safe_to_send'] = false;
            $decision['should_send_message'] = false;
            $decision['recommended_action'] = 'repeated_reply';
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['repeated_reply'])));
        }
        if ($revende) {
            $decision['safe_to_send'] = false;
            $decision['should_send_message'] = false;
            $decision['should_generate_payment_link'] = false;
            $decision['recommended_action'] = 'resell_blocked';
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['resell_blocked'])));
        }

        // 12) Persistir. La fase solo avanza cuando el commit se acepta.
        /*
         * Economía de preguntas, observable: a quien ya quiere pagar no se le
         * pregunta el objetivo. El Critic lo rechaza; aquí queda constancia
         * cuando aun así llega, para medirlo en el laboratorio.
         */
        // Las pistas que decide EMITIÓ, leídas del token firmado: el modelo no
        // puede apagar su propia bandera declarando otro intent.
        $hints = is_array($token['extra']['hints'] ?? null)
            ? array_merge(StrategyContract::hints($perfil, $resolution, $this->decide->canOfferLink(), (string) $sanitized['intent']), $token['extra']['hints'])
            : StrategyContract::hints($perfil, $resolution, $this->decide->canOfferLink(), (string) $sanitized['intent']);
        if (StrategyContract::asksDiscoveryToReadyBuyer($replyFinal, $hints)) {
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['discovery_question_to_ready_buyer'])));
        }
        if ($estilo['soft'] !== []) {
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), array_map(fn ($s) => 'style:'.$s, $estilo['soft']))));
        }

        $action = $this->persist($conversation, $message, $payload, $decision, $nextState, $plan, [
            'source' => 'external_draft',
            // El veredicto se audita también cuando aprueba; si no vino, no se inventa.
            'critic' => CriticContract::judged($critic) ? CriticContract::forMetadata($critic) : null,
            'strategy' => StrategyContract::fromProposal($proposal) ?: null,
            'plans_mentioned' => $estilo['plans_mentioned'],
            'strategy_hints' => ['hot_lead_fast_path' => $hints['hot_lead_fast_path'], 'lifecycle_mode' => $hints['lifecycle_mode']],
            'price_enriched' => $plan !== null && $replyFinal !== $sanitized['reply'],
            'reference_resolution' => $resolution['type'],
            'lead_temperature' => $perfil['lead_temperature'],
            'customer_lifecycle' => $perfil['customer_lifecycle'],
            'novelty_max_similarity' => $previas === [] ? null
                : round(max(array_map(fn ($b) => $this->novelty->similarity($replyFinal, $b), $previas)), 2),
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

        $rechazadas = array_values(array_diff((array) ($proposal['tools_requested'] ?? []), (array) $decision['tools_requested']));
        if ($rechazadas !== []) {
            // Una petición sin permiso no tumba el turno, pero tampoco pasa en silencio.
            ChannelLog::warning('ultron.commit.tools_rejected', ['conversation_id' => $conversation->id, 'tools' => $rechazadas]);
        }

        if (! ($decision['safe_to_send'] ?? false)) {
            $action->forceFill(['status' => 'skipped'])->save();
            $this->memoryService->recordSilentTurn($conversation->fresh(), $message, $resolution);

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
                'applied' => ['tools_executed' => $this->executedTools($executed)],
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

        // 14.bis) El link de pago, si el modelo lo pidió y Laravel lo permite: se
        // genera con el precio del catálogo y sale como mensaje propio DESPUÉS de
        // la respuesta, para que la persona lea primero el texto y luego el link.
        $pago = $this->execPaymentLink($conversation, $message, $decision, $plan, $outcome);
        if ($pago !== null) {
            $executed[] = $pago;
            $this->annotatePayment($action, $pago);
        }

        // 14.ter) Los enlaces de la app, si el modelo los pidió: los escribe Laravel,
        // como mensaje propio después de la respuesta. No hay dinero ni permiso que negociar.
        $enlaces = $this->execAppLinks($conversation, $decision, $outcome);
        if ($enlaces !== null) {
            $executed[] = $enlaces;
            $this->annotateExecuted($action, $enlaces);
        }

        // 15) La memoria registra lo que SALIÓ, con el plan y el precio reales.
        if ($outcome !== 'failed') {
            $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, $replyFinal, $plan,
                (string) $sanitized['intent'], $resolution, $send['message_id'] ?? null,
            );
        }

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
                'tools_executed' => $this->executedTools($executed),
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

        /*
         * El texto curado es de Laravel y se confía en él, pero confiar no es
         * no mirar: es la única salida de máquina que no pasaba por el guard,
         * y por ahí salieron dos ofertas de traspaso. Si un texto futuro
         * vuelve a ofrecerlo, no sale: se marca revisión y se calla.
         */
        if ($curado !== null) {
            $inspeccion = $this->contentGuard->inspect($curado, MarketingMessage::SENDER_AI, false);
            if (! $inspeccion['safe']) {
                ChannelLog::warning('ultron.commit.curated_reply_blocked', [
                    'conversation_id' => $conversation->id, 'intent' => $intent, 'code' => $inspeccion['code'],
                ]);
                $curado = null;
            }
        }

        // Y el texto curado tampoco se repite palabra por palabra: dos fallos del
        // critic con la misma intención no pueden mandar dos veces la misma frase.
        if ($curado !== null && $this->novelty->nearDuplicateOf($curado, $this->previousMachineReplies($conversation)) !== null) {
            ChannelLog::warning('ultron.commit.curated_reply_repeated', ['conversation_id' => $conversation->id, 'intent' => $intent]);
            $curado = null;
        }

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
                // Un fail solo llega aquí tras el segundo Critic: si no dice el intento
                // (ausente o null, que es como n8n manda lo que no aplica), fue el 2.
                'critic' => CriticContract::forMetadata(array_replace(['attempt' => 2], array_filter((array) ($payload['critic'] ?? []), fn ($v) => $v !== null))),
                'fallback_mode' => $modo,
                // Evidencia de lo que se descartó, para poder revisarlo. No sale.
                'discarded_draft' => mb_substr((string) ($proposal['reply_draft'] ?? ''), 0, 500),
            ],
        );

        $conversation->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason' => $reason,
        ])->save();

        $resolution = $this->references->resolve(
            (string) $message->body,
            $this->memoryService->load($conversation),
            $this->memoryService->sellablePlansForMemory(),
        );

        if ($modo === 'NO_REPLY_AND_HANDOFF') {
            $action->forceFill(['status' => 'skipped'])->save();
            $this->memoryService->recordSilentTurn($conversation->fresh(), $message, $resolution);

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

        if ($outcome !== 'failed') {
            $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, (string) $curado, null, $intent, $resolution, $send['message_id'] ?? null,
            );
        }

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
        return array_values(array_intersect($requested, $this->decide->allowedTools()));
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
                // El link de pago corre DESPUÉS de enviar la respuesta (execPaymentLink).
                SalesIntents::TOOL_PAYMENT_LINK_SEND => null,
                // Inalcanzable: la lista ya se filtró contra el menú del turno.
                default => ['tool' => $tool, 'status' => 'skipped', 'reason' => 'not_available'],
            };
        }

        return array_values(array_filter($executed));
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

    /**
     * GENERATE_PAYMENT_LINK. El modelo solo PIDE; aquí se decide todo lo demás:
     * permiso vigente (Wompi productivo + autorización del negocio), guardrail
     * (do_not_contact, plan vendible, ningún monto del cliente), un solo link vivo
     * por lead, idempotencia por (lead, plan) y «ya pagó» —los resuelve
     * WompiPaymentLinkService—, y el texto del mensaje con el precio del catálogo.
     * Se ejecuta después de enviar la respuesta; si la respuesta no salió, el link
     * tampoco. Y NUNCA lanza: un fallo aquí no puede romper un turno que ya salió.
     *
     * @return array<string,mixed>|null null cuando no se pidió
     */
    private function execPaymentLink(MarketingConversation $conversation, MarketingMessage $message, array $decision, ?Plan $plan, string $outcome): ?array
    {
        $tool = SalesIntents::TOOL_PAYMENT_LINK_SEND;
        if (! in_array($tool, (array) ($decision['tools_requested'] ?? []), true)) {
            return null;
        }
        if ($outcome === 'failed') {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'reply_not_sent'];
        }
        // Doble cerrojo: el menú ya lo filtró, pero la barrera vive donde se ejecuta.
        if (! $this->decide->canOfferLink()) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'automatic_links_disabled'];
        }
        $lead = $conversation->lead;
        if ($lead === null || $plan === null) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => $plan === null ? 'no_plan_to_charge' : 'no_lead'];
        }

        try {
            $this->paymentGuardrail->assertCanGeneratePaymentLink($lead, $plan, []);

            // Dos links vivos para planes distintos serían dos cobros posibles, y el
            // checkout de Wompi no se puede anular desde aquí: el segundo no se
            // genera y lo resuelve una persona.
            $otro = PaymentTransaction::query()
                ->where('idempotency_key', 'like', 'mkt-lead-'.$lead->id.'-plan-%')
                ->where('plan_id', '!=', $plan->id)
                ->whereIn('status', SM::IN_FLIGHT)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('id')
                ->first();
            if ($otro !== null) {
                $conversation->forceFill(['staff_review_pending' => true, 'staff_review_reason' => 'payment_link_plan_change'])->save();

                return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'another_link_in_flight', 'other_plan_id' => (int) $otro->plan_id];
            }

            $link = WompiPaymentLinkService::make()->generateForLead($lead, $plan, [
                'channel' => $conversation->channel, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
            ]);
            if (($link['configured'] ?? false) === false) {
                return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'wompi_checkout_not_configured'];
            }
            if (($link['already_paid'] ?? false) === true) {
                return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'already_paid', 'reference' => $link['reference'] ?? null];
            }
            if (empty($link['payment_url'])) {
                return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'link_not_safe_to_send'];
            }

            $body = $this->replies->paymentLinkMessage($plan, (float) $link['amount'], (string) $link['payment_url']);
            $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, $body, [
                'kind' => 'payment_link', 'origin' => 'ultron', 'reference' => $link['reference'] ?? null,
            ], MarketingMessage::SENDER_AI);
            $this->memoryService->recordPaymentLink($conversation->fresh(), [
                'reference' => $link['reference'] ?? null, 'plan_id' => (int) $plan->id, 'expires_at' => $link['expires_at'] ?? null,
                'status' => 'pending', 'link_sent_at' => now()->toIso8601String(), 'message_id' => $send['message_id'] ?? null,
            ]);
            ChannelLog::info('ultron.payment_link.sent', [
                'conversation_id' => $conversation->id, 'plan_id' => (int) $plan->id, 'reference' => $link['reference'] ?? null,
                'sent' => $send['sent'], 'dry_run' => $send['dry_run'],
            ]);

            return ['tool' => $tool, 'status' => 'executed', 'reference' => $link['reference'] ?? null, 'expires_at' => $link['expires_at'] ?? null, 'sent' => $send['sent'], 'dry_run' => $send['dry_run'], 'message_id' => $send['message_id'] ?? null];
        } catch (SalesGuardrailException $e) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => $e->errorCode];
        } catch (Throwable $e) {
            ChannelLog::error('ultron.payment_link.failed', ['conversation_id' => $conversation->id, 'plan_id' => (int) $plan->id, 'exception' => class_basename($e)]);

            return ['tool' => $tool, 'status' => 'failed', 'reason' => 'payment_engine_error'];
        }
    }

    /** Solo lo que de verdad corrió: la respuesta a n8n y la metadata dicen lo mismo. */
    private function executedTools(array $executed): array
    {
        return array_values(array_map(fn ($e) => $e['tool'], array_filter($executed, fn ($e) => in_array($e['status'] ?? null, ['executed', 'created'], true))));
    }

    /**
     * SEND_APP_LINKS. Los enlaces oficiales de la app (MobileAppLinks) salen en un
     * mensaje de Laravel después de la respuesta; la memoria lo anota para no
     * volver a anunciarlos como novedad. Nunca lanza.
     *
     * @return array<string,mixed>|null null cuando no se pidió
     */
    private function execAppLinks(MarketingConversation $conversation, array $decision, string $outcome): ?array
    {
        $tool = SalesIntents::TOOL_APP_LINKS_SEND;
        if (! in_array($tool, (array) ($decision['tools_requested'] ?? []), true)) {
            return null;
        }
        if ($outcome === 'failed') {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'reply_not_sent'];
        }
        $lead = $conversation->lead;
        if ($lead === null) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'no_lead'];
        }

        try {
            $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, MobileAppCatalog::linksMessage(), [
                'kind' => 'app_links', 'origin' => 'ultron',
            ], MarketingMessage::SENDER_AI);
            $this->memoryService->recordAppLinksSent($conversation->fresh());

            return ['tool' => $tool, 'status' => 'executed', 'sent' => $send['sent'], 'dry_run' => $send['dry_run'], 'message_id' => $send['message_id'] ?? null];
        } catch (Throwable $e) {
            ChannelLog::error('ultron.app_links.failed', ['conversation_id' => $conversation->id, 'exception' => class_basename($e)]);

            return ['tool' => $tool, 'status' => 'failed', 'reason' => 'app_links_error'];
        }
    }

    /** Una herramienta que corrió después de persistir la acción se anota encima. */
    private function annotateExecuted(MarketingAiAction $action, array $resultado): void
    {
        if (($resultado['status'] ?? null) !== 'executed') {
            return;
        }
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['tools_executed'] = array_values(array_unique(array_merge((array) ($meta['tools_executed'] ?? []), [$resultado['tool']])));
        $action->forceFill(['metadata' => $meta])->save();
    }

    /** La acción ya está persistida cuando sale el link: se anota encima, sin URL. */
    private function annotatePayment(MarketingAiAction $action, array $pago): void
    {
        $meta = is_array($action->metadata) ? $action->metadata : [];
        if (($pago['status'] ?? null) === 'executed') {
            $meta['tools_executed'] = array_values(array_unique(array_merge((array) ($meta['tools_executed'] ?? []), [$pago['tool']])));
        }
        $meta['payment_link'] = array_filter([
            'status' => $pago['status'] ?? null, 'reason' => $pago['reason'] ?? null, 'reference' => $pago['reference'] ?? null,
            'expires_at' => $pago['expires_at'] ?? null, 'message_id' => $pago['message_id'] ?? null,
        ], fn ($v) => $v !== null);
        $action->forceFill(['metadata' => $meta])->save();
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

        /*
         * Tercer hecho de Laravel: la persona lo pidió en ESTE mensaje, y el
         * backend lo lee por sí mismo. Es la misma corroboración con la que
         * decide abrió el menú; si el cerrojo no la aceptara aquí, un modelo
         * que olvide el motivo convertiría una petición legítima en un 422 y
         * en silencio para quien pidió hablar con alguien. El motivo que
         * viaje desde n8n queda como propuesta: nunca concede lo que el texto
         * no concede ya.
         */
        $prueba = $this->handoff->peticionDeHumanoEn((string) $message->body);

        if ($prueba !== null) {
            return [
                'allowed' => true,
                'reason' => HumanHandoffAuthority::EXPLICIT_HUMAN_REQUEST,
                'evidence' => $prueba,
                'refusal' => null,
            ];
        }

        return $this->handoff->decideProposal(
            is_string($proposal['human_handoff_reason'] ?? null) ? $proposal['human_handoff_reason'] : null,
            (string) $message->body,
        );
    }

    /**
     * Las últimas respuestas de máquina de esta conversación, más reciente
     * primero. Tres bastan: una repetición se nota contra el turno anterior.
     *
     * @return string[]
     */
    private function previousMachineReplies(MarketingConversation $conversation): array
    {
        return $conversation->messages()
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->where('sender_type', MarketingMessage::SENDER_AI)
            // Ventana: repetir la dirección a quien vuelve semanas después no es repetirse.
            ->where('created_at', '>=', now()->subHours(24))
            ->latest('id')->limit(3)->pluck('body')
            ->filter(fn ($b) => is_string($b) && trim($b) !== '')
            ->values()->all();
    }
}
