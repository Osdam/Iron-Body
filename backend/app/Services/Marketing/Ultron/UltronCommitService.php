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
use App\Services\Marketing\StaffReviewAuthority;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Carbon\CarbonImmutable;
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

    /**
     * Lo que sale cuando la respuesta entera era la frase que mentía.
     *
     * No niega el cobro, no promete que nadie escriba y no inventa un enlace
     * que en ese momento no se pudo acuñar: dice lo que es verdad siempre.
     */
    private const SIN_ENLACE_PERO_SIN_MENTIR = 'Puedes hacer el pago desde la app Iron Body Workout o directamente en el gimnasio. Dime qué plan quieres y lo dejamos listo.';

    private const LOCK_TTL_SECONDS = 60;

    /**
     * Cuánto tiene que pasar para volver a mandar los enlaces de la app a la
     * MISMA conversación. La memoria ya anotaba `app_context.links_sent_at`,
     * pero nadie la leía: el modelo podía pedir `app_links_send` en cada turno
     * y Laravel despachaba tres URLs en cada turno.
     */
    public const APP_LINKS_RESEND_MINUTES = 60;

    /**
     * Lo que el resolver de Laravel reconoce como «esta persona quiere seguir
     * adelante». No entra `price_of_pending`: preguntar el precio no es pagar.
     */
    private const RESOLUCIONES_QUE_PAGAN = [
        ReferenceResolver::SEND_IT,
        ReferenceResolver::CHOOSE_PLAN,
        ReferenceResolver::ACCEPT_OFFER,
        ReferenceResolver::HOW_TO_START,
    ];

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
        private readonly MembershipFactsProvider $membershipFacts,
        private readonly MembershipFactGuard $membershipGuard,
        private readonly PaymentFactGuard $paymentGuard,
        private readonly UltronAbortLatch $abortLatch = new UltronAbortLatch,
        private readonly StaffReviewAuthority $staffReview = new StaffReviewAuthority,
        private readonly PromiseAuthority $promises = new PromiseAuthority,
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

        /*
         * EL INTERRUPTOR MAESTRO, AQUÍ TAMBIÉN.
         *
         * El emisor ya no crea eventos con ULTRON apagado, y el job que los
         * envía a n8n lo vuelve a comprobar. Pero esta puerta no lo miraba, así
         * que «apagar ULTRON» dependía de que nadie llamara al commit: un
         * commit rezagado, un reintento de n8n o una ejecución a mano seguían
         * mandando un WhatsApp de verdad después de haber apagado.
         *
         * Se comprobó en el canario físico: al abortar hubo que neutralizar
         * ADEMÁS el id del canario para estar seguro de que no saliera nada.
         * Un interruptor que necesita un segundo interruptor no es un
         * interruptor. Ahora sí lo es, y las dos barreras son independientes.
         */
        if (! (bool) config('marketing.ultron.enabled', false)) {
            ChannelLog::warning('ultron.commit.ultron_disabled', [
                'conversation_id' => (int) $conversation->id,
            ]);

            throw UltronCommitException::make(
                'ultron_disabled',
                'ULTRON está apagado: aquí no se ejecuta nada.',
                403,
            );
        }

        /*
         * EL FRENO DE EMERGENCIA, TAMBIÉN AQUÍ.
         *
         * El emisor ya no crea eventos con el freno puesto, pero los que ya
         * estaban en vuelo sí llegan aquí: n8n tarda veinte segundos en pensar,
         * y en esos veinte segundos el vigía puede haber parado el canario. Sin
         * esta puerta, parar significaría «parar dentro de un rato».
         *
         * Se lanza en vez de registrar un turno bloqueado por la misma razón
         * que el canario: una ejecución que llega con el sistema parado no
         * merece una fila en el historial de nadie, merece un no.
         */
        if ($this->abortLatch->engaged()) {
            ChannelLog::warning('ultron.commit.ultron_aborted', [
                'conversation_id' => (int) $conversation->id,
            ]);

            throw UltronCommitException::make(
                'ultron_aborted',
                'ULTRON está parado por el freno de emergencia: aquí no se ejecuta nada.',
                403,
            );
        }

        /*
         * EL CERROJO DEL CANARIO, TAMBIÉN EN LA PUERTA DE SALIDA.
         *
         * `UltronEventEmitter` ya impide que nazca un evento de una
         * conversación que no es la del canario, y ese es el camino normal: sin
         * evento, n8n no se entera. Pero el camino normal no es el único: quien
         * tenga el secreto —o una ejecución manual del workflow, o un pin de
         * datos— puede llamar aquí con cualquier `conversation_id`, y de aquí
         * sale un WhatsApp de verdad.
         *
         * Así que el mismo hecho se comprueba en los dos extremos. Se LANZA en
         * vez de registrar un turno bloqueado: una llamada a una conversación
         * ajena no merece una fila en su historial, merece un no.
         *
         * `null` en la bandera significa SIN RESTRICCIÓN, igual que en el
         * emisor: el canario se acota poniendo el id, no quitándolo.
         */
        $canario = config('marketing.ultron.canary_conversation_id');
        if ($canario !== null && (int) $canario !== (int) $conversation->id) {
            ChannelLog::warning('ultron.commit.not_canary_conversation', [
                'conversation_id' => (int) $conversation->id,
                'canary_conversation_id' => (int) $canario,
            ]);

            throw UltronCommitException::make(
                'not_canary_conversation',
                'El canario está acotado a otra conversación: aquí no se ejecuta nada.',
                403,
            );
        }

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

        /*
         * ── 7.bis) RENDICIÓN: este turno ya fue rechazado una vez ─────────────
         *
         * Va aquí, y no más abajo, por la misma razón por la que existe: lo que
         * viene a continuación son precisamente los guards que tumbaron el
         * intento anterior. Pasar otra vez por ellos con el mismo borrador sólo
         * sirve para repetir el silencio.
         *
         * Va DESPUÉS del token, del relevo y del interruptor a propósito: una
         * rendición no es un salvoconducto. Si la conversación ya no es
         * elegible, o alguien escribió después, o el mundo cambió, esto se
         * bloquea exactamente igual que cualquier otro commit.
         */
        if ($rendicion = $this->recoveryReason($payload)) {
            return $this->handleRecovery($conversation, $message, $payload, $currentPhase, $rendicion);
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
            /*
             * Quién dice que un pago entró: Wompi, y el CRM lo escribe. El
             * estado viaja firmado en el token, que es el mundo que vio decide;
             * recalcularlo aquí significaría salir otra vez a la pasarela desde
             * el camino de escritura, y ese es justo el error que el PUNTO 8
             * vino a deshacer.
             */
            $pagoFalso = $this->paymentGuard->contradiction(
                (string) ($proposal['reply_draft'] ?? ''),
                (string) ($token['extra']['payment_state'] ?? 'none'),
            );
            if ($pagoFalso !== null) {
                ChannelLog::warning('ultron.commit.payment_fact_rejected', [
                    'conversation_id' => (int) $conversation->id, 'reason' => $pagoFalso['reason'],
                ]);
                throw SalesGuardrailException::make(PaymentFactGuard::CODE, 'El borrador afirma un pago o una activación que el CRM no respalda ('.$pagoFalso['reason'].').', escalate: true);
            }
            // La membresía se afirma con los hechos del CRM o no se afirma: una
            // fecha de fin, unos días restantes o un estado que contradigan
            // `context.membership` (o que se afirmen sin socio identificado) no
            // salen. Los marcadores no llevan fechas, así que el borrador vale.
            $contradiccion = $this->membershipGuard->contradiction(
                (string) ($proposal['reply_draft'] ?? ''),
                $this->membershipFacts->forPrompt($conversation->lead),
            );
            if ($contradiccion !== null) {
                ChannelLog::warning('ultron.commit.membership_fact_rejected', [
                    'conversation_id' => (int) $conversation->id, 'reason' => $contradiccion['reason'],
                ]);
                throw SalesGuardrailException::make(MembershipFactGuard::CODE, 'El borrador afirma una fecha, unos días o un estado de membresía que el CRM no respalda ('.$contradiccion['reason'].').', escalate: true);
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
            // Para quién es este turno. Lo lee el guardrail, que desde el
            // canario ya no puede contestar con la bandera global.
            'conversation_id' => (int) $conversation->id,
            'reply' => $replyFinal,
            'should_reply' => true,
            'should_send_message' => true,
            'should_generate_payment_link' => false,
            'should_schedule_followup' => false,
            'followup_delay_minutes' => null,
            'should_escalate' => false,
            'needs_staff_review' => (bool) $sanitized['force_escalate'],
            'staff_review_reason' => $sanitized['escalation_reason'],
            /*
             * Lo que el MODELO dice que justifica la revisión, sin mezclarlo
             * con lo que afirma el backend. Viajan en claves distintas porque
             * no valen lo mismo: uno es un hecho calculado aquí, el otro una
             * etiqueta que llegó desde n8n. Quien decide es
             * {@see StaffReviewAuthority}.
             */
            'staff_review_proposed_reason' => $proposal['staff_review_reason'] ?? null,
            'escalation_reason' => $sanitized['escalation_reason'],
            'risk_flags' => $sanitized['risk_flags'],
            'extracted_fields' => [],
            'missing_fields' => [],
            'recommended_action' => SalesIntents::ACTION_REPLY,
            'tools_requested' => $this->allowedTools((array) ($proposal['tools_requested'] ?? []), (int) $conversation->id),
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

        /*
         * 11.bis) EL INVARIANTE DE LA PROMESA. Si el texto dice que algo pasa,
         * tiene que pasar.
         *
         * Va AQUÍ, entre el guardrail y `persist()`, y el sitio no es
         * cosmético. Antes, en `assertSafe()` (línea ~416), todavía no se sabe
         * si el backend tiene causa: `sanitize()` corre después. Aquí ya son
         * definitivos `needs_staff_review`, el motivo propuesto y el
         * `tools_requested` con la inyección del guardrail, y
         * {@see StaffReviewAuthority::decide()} es una función pura, así que
         * preguntarle no tiene efectos. Y va antes de `persist()` a propósito:
         * un 422 aquí no deja fila que deshacer, igual que `draft_rejected`.
         *
         * Dos familias y dos tratos, porque no son el mismo problema:
         *
         *  - ACCIÓN FUTURA («te escribo mañana», «te guardo el cupo»): no hay
         *    nada que contrastar. `should_schedule_followup` está fijado a
         *    false y no existe programador de seguimientos, así que la frase es
         *    falsa siempre. Muere aquí.
         *  - EFECTO DURABLE («lo dejo marcado», «lo escalo»): puede ser verdad.
         *    Se comprueba contra la autoridad Y contra el menú del turno,
         *    porque la autoridad decide si PUEDE marcarse y la herramienta
         *    decide si SE VA a marcar; las dos tienen que darse.
         *
         * Lo que sale por aquí no es un turno mudo: es un 422 antes de escribir
         * nada, que es exactamente de lo que cuelga la rendición de n8n. La
         * persona recibe el texto curado, que sí dice la verdad.
         */
        $promesa = $this->promises->detectar($replyFinal);

        if ($promesa['kind'] === PromiseAuthority::ACCION_FUTURA) {
            ChannelLog::warning('ultron.commit.unsupported_future_promise', [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'quote' => $promesa['quote'],
            ]);

            throw UltronCommitException::make(
                PromiseAuthority::ACCION_FUTURA,
                'El borrador promete una acción futura que el sistema no puede cumplir.',
                422,
                ['quote' => $promesa['quote']],
            );
        }

        if ($promesa['kind'] === PromiseAuthority::EFECTO_DURABLE) {
            $marcaAutorizada = $this->staffReview->decide(
                (bool) ($decision['needs_staff_review'] ?? false),
                $decision['staff_review_reason'] ?? null,
                $decision['staff_review_proposed_reason'] ?? null,
            )['allowed'];

            // Poder marcarse no es marcarse: la herramienta tiene que estar en
            // el menú del turno, o el efecto no ocurre aunque haya causa.
            $marcaEnElTurno = in_array(
                SalesIntents::TOOL_STAFF_REVIEW,
                (array) ($decision['tools_requested'] ?? []),
                true,
            );

            if (! ($marcaAutorizada && $marcaEnElTurno)) {
                ChannelLog::warning('ultron.commit.promised_effect_without_authority', [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    'quote' => $promesa['quote'],
                    'authorised' => $marcaAutorizada,
                    'tool_in_turn' => $marcaEnElTurno,
                ]);

                throw UltronCommitException::make(
                    'promised_effect_without_authority',
                    'El borrador afirma un efecto que este turno no produce.',
                    422,
                    ['quote' => $promesa['quote']],
                );
            }
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
            ? array_merge(StrategyContract::hints($perfil, $resolution, $this->decide->canOfferLink((int) $conversation->id), (string) $sanitized['intent']), $token['extra']['hints'])
            : StrategyContract::hints($perfil, $resolution, $this->decide->canOfferLink((int) $conversation->id), (string) $sanitized['intent']);
        if (StrategyContract::asksDiscoveryToReadyBuyer($replyFinal, $hints)) {
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['discovery_question_to_ready_buyer'])));
        }
        if ($estilo['soft'] !== []) {
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), array_map(fn ($s) => 'style:'.$s, $estilo['soft']))));
        }

        $action = $this->persist($conversation, $message, $payload, $decision, $nextState, $plan, [
            'source' => 'external_draft',
            /*
             * Si la derivación de este turno la AUTORIZÓ Laravel. Se guarda
             * porque, sin ella, leer la conversación después no distingue el
             * traspaso que pidió la persona del que se ofreció solo, y el acta
             * del canario —donde ese contador tiene que ser cero— tendría que
             * adivinarlo. Cuando no hay derivación, no se escribe.
             */
            'handoff_authorized' => $handoffAutorizado ?: null,
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

        /*
         * Lo que corrió, EN LA FILA. `executedTools()` promete en su docblock
         * que «la respuesta a n8n y la metadata dicen lo mismo», y para
         * `staff_review` y `mark_do_not_contact` no lo decían: las dos se
         * ejecutan aquí y sólo se anotaban las dos que corren después del envío
         * (los enlaces y el link de pago). Resultado medido en el canario: la
         * conversación quedó marcada para revisión humana y la acción que la
         * marcó no lo contaba. Quien auditara la fila veía una revisión
         * pendiente sin ninguna acción que la explicara.
         *
         * Va antes del corte por `safe_to_send` a propósito: ese camino también
         * devuelve `applied.tools_executed`, y ahí es donde corre el opt-out de
         * quien pidió que no le escriban.
         */
        if ($executed !== []) {
            $meta = is_array($action->metadata) ? $action->metadata : [];
            $meta['tools_executed'] = $this->executedTools($executed);

            /*
             * Y lo que se PIDIÓ y no se concedió, también.
             *
             * `tools_executed` filtra por `executed|created`, así que una
             * revisión rechazada por falta de causa no aparecía por ningún
             * lado: ni ahí, ni en `tools_rejected` —que compara pedidas contra
             * PERMITIDAS, y `staff_review` está siempre permitida—. Vivía sólo
             * en el log, y un log no es el expediente del turno. Se anota con
             * la misma forma que `payment_link`: qué se decidió, por qué, y
             * quién puso la causa.
             */
            foreach ($executed as $resultado) {
                if (($resultado['tool'] ?? null) === SalesIntents::TOOL_STAFF_REVIEW) {
                    $meta['staff_review'] = array_filter([
                        'status' => $resultado['status'] ?? null,
                        'reason' => $resultado['reason'] ?? null,
                        'source' => $resultado['source'] ?? null,
                    ], fn ($v) => $v !== null);
                }
            }

            $action->forceFill(['metadata' => $meta])->save();
        }

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

        /*
         * 13.bis) LOS ENLACES DE LA APP, DENTRO DE LA RESPUESTA.
         *
         * Salían como mensaje propio detrás de la respuesta, así que la persona
         * recibía dos globos seguidos: «te paso los enlaces para descargarla» y,
         * a continuación, los enlaces. Eso se leyó en el canario físico como lo
         * que parece —el asistente repitiéndose—, y no era un fallo: estaba
         * escrito así en los dos prompts y en el backend.
         *
         * Ahora es UN mensaje. El modelo sigue sin poder escribir una URL: deja
         * el marcador `{{APP_LINKS}}` —o ni eso, y entonces las pega Laravel al
         * final— y la sustitución ocurre aquí, después de todos los guards, por
         * el mismo orden que ya gobierna al precio.
         */
        $enlaces = $this->appLinksDecision($conversation, $decision, $replyFinal);

        if ($enlaces !== null && $enlaces['status'] === 'executed') {
            $replyFinal = $this->conEnlaces($replyFinal, MobileAppCatalog::linksInline());
        } elseif ($enlaces !== null) {
            // No se despachan (ya salieron hace poco, o no hay a quién): el
            // marcador no puede quedarse escrito en el mensaje del cliente.
            $replyFinal = $this->placeholders->resolveAppLinks($replyFinal, MobileAppCatalog::LINKS_ALREADY_SENT);
        }

        /*
         * 13.ter) EL COBRO MANDA SOBRE LA EXPLICACIÓN.
         *
         * Esto existe por un fallo medido en el canario físico. La persona
         * escribió «Listo, quiero pagarlo. ¿Me puedes mandar el enlace para
         * hacer el pago?» y recibió instrucciones para pagar en el gimnasio o
         * desde la app, más los tres enlaces de descarga. El checkout real
         * existía, la autoridad lo permitía y la herramienta estaba en el menú
         * del turno: el modelo simplemente no la pidió.
         *
         * Y no fue un capricho suyo: los prompts, escritos cuando el cobro
         * automático estaba apagado para siempre, le enseñaban que pagar es la
         * app o el gimnasio y que no pedir los enlaces de la app es «el error
         * más caro». Hizo lo que le enseñamos.
         *
         * Por eso el arreglo no puede vivir en el prompt. Cuando la intención
         * VALIDADA es de pago, hay plan vendible y la autoridad permite cobrar,
         * el checkout no es una opción del redactor: lo impone Laravel, igual
         * que impone el precio y los enlaces. El modelo no puede omitirlo, ni
         * sustituirlo por la app, ni inventarse una URL.
         *
         * Va DESPUÉS de los enlaces de la app y ANTES del envío, para que salga
         * UNA sola respuesta visible con el cobro dentro, y no tres globos.
         */
        $cobro = $this->checkoutDecision($conversation, $lead, $decision, $plan, (string) $sanitized['intent'], $resolution, $replyFinal);

        if ($cobro !== null && ($cobro['status'] ?? null) === 'inlined') {
            /*
             * Si el borrador NEGABA el cobro, no se le pega el enlace debajo.
             *
             * Quedaría un mensaje que se contradice en dos líneas: «no
             * contamos con un link de pago» y, a renglón seguido, el link. Ese
             * borrador ya no sirve para nada, así que se tira entero y sale el
             * texto curado, que dice el plan, el precio y el enlace.
             */
            $replyFinal = ($cobro['nego_el_cobro'] ?? null) !== null && $plan !== null
                ? $this->replies->paymentLinkMessage($plan, (float) ($cobro['amount'] ?? 0), (string) $cobro['payment_url'])
                : $this->conCheckout($replyFinal, $cobro);

            /*
             * Y la fila cuenta que el cobro salió. Se ejecutó —hay referencia y
             * hay enlace en el mensaje—, sólo que dentro de la respuesta en vez
             * de en un globo aparte. Que `tools_executed` dijera que no habría
             * sido el mismo expediente que miente que ya se corrigió tres veces.
             */
            $anotacion = [
                'tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND,
                'status' => 'executed',
                'reference' => $cobro['reference'] ?? null,
                'expires_at' => $cobro['expires_at'] ?? null,
                'delivery' => 'inlined',
            ];
            $this->annotatePayment($action, $anotacion);

            // Y la respuesta a n8n dice lo mismo que la fila. Su docblock lo
            // promete y ya se rompió una vez: el cobro se ejecutó, sólo que
            // dentro del mensaje en vez de en uno aparte.
            $executed[] = $anotacion;
        } elseif ($cobro !== null) {
            $this->annotatePayment($action, [
                'tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND,
                'status' => $cobro['status'] ?? 'skipped',
                'reason' => $cobro['reason'] ?? null,
                'other_plan_id' => $cobro['other_plan_id'] ?? null,
            ]);
        }

        /*
         * 13.quater) NO SE NIEGA UN COBRO QUE EXISTE.
         *
         * La red, por debajo del enrutado. El testigo de arriba arregla el caso
         * que se midió —intención de pago validada y respuesta que niega el
         * enlace—, pero la invariante tiene que valer aunque el turno llegue
         * por un camino que nadie previó: si el cobro está disponible y la
         * respuesta dice que no existe, esa respuesta es falsa y no sale.
         *
         * Falso en el sentido literal: la autoridad permite cobrar en esta
         * conversación, hay un plan vendible y nadie ha pagado todavía. Decirle
         * a alguien que no hay forma de pagar por aquí, en ese estado, es la
         * clase de mentira que cuesta una venta y la confianza de quien la lee.
         *
         * Lo que cae es el BORRADOR, no el turno. Esto llegó a lanzar un 422, y
         * era peor que el problema: el turno se quedaba mudo, la fila de la
         * acción ya estaba escrita como ejecutada dos pasos antes, y el vigía
         * —que busca turnos sin desenlace— no veía nada que abrir. Un cerrojo
         * que apaga la conversación y además ciega al que vigila no es un
         * cerrojo. Se quita la frase que miente y sale lo demás.
         *
         * Se paga barata: primero se mira el texto, que es memoria; las tres
         * preguntas al estado sólo se hacen cuando ya hay una negación que
         * verificar, y eso es una minoría ínfima de los turnos.
         */
        $replyFinal = $this->sinNegarUnCobroDisponible($conversation, $lead, $plan, $replyFinal, $action);

        // 14) Envío por el camino de siempre.
        // La conversación va explícita: la respuesta pertenece al hilo del
        // turno, no al primero que encuentre el despachador.
        $send = $this->dispatcher->dispatchWhatsapp(
            $lead->fresh(),
            $conversation->channel,
            $replyFinal,
            ['kind' => 'reply', 'origin' => 'ultron', 'ai_action_id' => $action->id],
            MarketingMessage::SENDER_AI,
            conversation: $conversation,
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

        // 14.ter) Los enlaces viajaron DENTRO de la respuesta; aquí sólo queda
        // anotar qué pasó y espaciar el próximo envío. La marca se escribe sólo
        // si el mensaje llegó a existir: si el despachador lo bloqueó, no hay
        // enlaces que espaciar y marcarlo los callaría una hora por nada.
        if ($enlaces !== null) {
            if ($enlaces['status'] === 'executed') {
                if ($outcome === 'failed') {
                    $enlaces = ['tool' => $enlaces['tool'], 'status' => 'skipped', 'reason' => 'reply_not_sent'];
                } else {
                    $this->memoryService->recordAppLinksSent($conversation->fresh());
                    $enlaces['sent'] = $send['sent'];
                    $enlaces['dry_run'] = $send['dry_run'];
                    $enlaces['message_id'] = $send['message_id'] ?? null;
                }
            }

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

    // ── Rendiciones: cuando el borrador del modelo no puede salir ─────────────

    /**
     * El borrador que el critic rechazó NO se envía. Ni se valida, ni se
     * enriquece: se guarda como evidencia y muere ahí. Lo que sale en su lugar
     * lo decide {@see safeFallback()}.
     *
     * @return array<string,mixed>
     */
    private function handleCriticFailure(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $currentPhase,
    ): array {
        return $this->safeFallback($conversation, $message, $payload, $currentPhase, [
            'flag' => 'critic_failed',
            'origin' => 'ultron_curated_fallback',
            'log' => 'ultron.commit.critic_failed',
            'metadata' => [
                // Un fail solo llega aquí tras el segundo Critic: si no dice el intento
                // (ausente o null, que es como n8n manda lo que no aplica), fue el 2.
                'critic' => CriticContract::forMetadata(array_replace(['attempt' => 2], array_filter((array) ($payload['critic'] ?? []), fn ($v) => $v !== null))),
            ],
        ]);
    }

    /**
     * RENDICIÓN. El propio CRM rechazó el turno anterior y n8n vuelve a llamar
     * pidiendo lo único que aquí no se puede rechazar: el texto de Laravel.
     *
     * Existe por un fallo medido en el canario físico. Un borrador cayó en un
     * guard, `/ai/commit` devolvió 422 ANTES de escribir una sola fila, el nodo
     * HTTP de n8n no considera eso un error y la ejecución terminó en verde.
     * Resultado: la persona preguntó, nadie contestó, y en las tablas no había
     * ni rastro de que hubiera habido un turno. Un rechazo del CRM es una razón
     * para cambiar de texto; nunca una razón para dejar a alguien sin respuesta.
     *
     * Lo que NO hace, y es la mitad del diseño:
     *  - No envía el borrador rechazado. Ni lo revalida: se guarda de evidencia.
     *  - No se fía de que n8n diga la verdad sobre el motivo. `recovery.reason`
     *    es una ETIQUETA para la auditoría; no abre ninguna puerta ni se lee
     *    para decidir nada. Todo lo de arriba —interruptor, canario,
     *    elegibilidad, relevo, token— ya se comprobó y sigue mandando.
     *  - No inventa un veredicto del Critic que nadie emitió: la alternativa
     *    era que n8n mandara `critic.verdict='fail'` fabricado, y eso ensucia
     *    la única métrica que mide si el Critic sirve.
     *
     * @param  array{reason:string,attempt:int}  $rendicion
     * @return array<string,mixed>
     */
    private function handleRecovery(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $currentPhase,
        array $rendicion,
    ): array {
        ChannelLog::warning('ultron.commit.recovery', [
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) $message->id,
            'reason' => $rendicion['reason'],
            'attempt' => $rendicion['attempt'],
        ]);

        return $this->safeFallback($conversation, $message, $payload, $currentPhase, [
            'flag' => 'commit_rejected',
            'origin' => 'ultron_recovery_fallback',
            'log' => 'ultron.commit.recovered',
            'metadata' => ['recovery' => $rendicion],
        ]);
    }

    /**
     * La salida segura, compartida por las dos rendiciones.
     *
     * Lo que sale en lugar del borrador lo decide una regla, no un modelo: si
     * existe un texto curado para la intención validada, se usa; si no existe,
     * no se responde y se marca para el equipo. La existencia del texto curado
     * ES el discriminante, y ya estaba escrita.
     *
     * @param  array{flag:string,origin:string,log:string,metadata:array<string,mixed>}  $causa
     * @return array<string,mixed>
     */
    private function safeFallback(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $currentPhase,
        array $causa,
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

        /*
         * Qué hace falta una persona AQUÍ, y qué no.
         *
         * Antes, cualquier rendición marcaba la conversación, y eso llenaba la
         * bandeja de turnos bien resueltos. Ahora la bandera sigue a dos cosas,
         * y las dos hacen falta:
         *
         *   LA RAMA. `NO_REPLY_AND_HANDOFF` significa que no salió nada:
         *   alguien preguntó y se quedó sin contestar. Eso lo coge una persona
         *   siempre. Con `SAFE_CURATED_REPLY` salió un texto de Laravel, así
         *   que por sí sola la rendición no pide a nadie: el borrador que el
         *   critic tumbó NUNCA se envió, no hay nada que reparar.
         *
         *   LA CAUSA. Y aquí está lo que casi se me escapa, porque el
         *   razonamiento de arriba es cierto para un turno normal y FALSO para
         *   una intención sensible: el texto curado de esas cinco dice, con
         *   esas palabras, «lo dejo marcado para revisión del equipo». Si la
         *   bandera no se levanta, Laravel acaba de prometer en primera persona
         *   algo que no va a pasar, y quien escribió una queja se queda
         *   esperando a alguien que nunca fue avisado. Es el mismo fallo que
         *   este sistema lleva meses persiguiendo —dejar a una persona
         *   esperando—, sólo que escrito por nosotros.
         *
         * La regla, entonces, es literal: SI EL TEXTO QUE SALE PROMETE LA
         * MARCA, LA MARCA SE PONE. Y quien elige el texto es el mismo `$intent`
         * que se consulta aquí, así que las dos decisiones no pueden separarse.
         *
         * Que no se levante en el resto de los casos no pierde el fallo: quedan
         * el warning en el log, la fila con sus `risk_flags`, el borrador
         * descartado como evidencia y `fallback_mode`, que es lo que lee el
         * informe del canario.
         */
        $motivoSensible = StaffReviewAuthority::motivoDeIntencion($intent);
        $necesitaPersona = $modo === 'NO_REPLY_AND_HANDOFF' || $motivoSensible !== null;

        /*
         * El motivo dice por qué hace falta alguien, y eso depende de la rama:
         * si nadie contestó, el hecho urgente es ése; si salió el texto curado
         * de una queja, el hecho es la queja.
         */
        $reason = $modo === 'NO_REPLY_AND_HANDOFF'
            ? $causa['flag'].'_no_safe_reply'
            : ($motivoSensible ?? $causa['flag']);

        /*
         * EL OPT-OUT NO SE PIERDE POR RENDIRSE.
         *
         * El paso 13 de {@see execute()} corre las herramientas ANTES de mirar
         * si hay algo que enviar, y su comentario dice exactamente por qué:
         * «para que el opt-out no se pierda». Este camino era el único donde
         * ese razonamiento no se aplicaba, porque la rendición no ejecutaba
         * NINGUNA herramienta. Resultado medido: quien escribía «no me escriban
         * más» en un turno que se rendía quedaba marcado para el equipo y con
         * `do_not_contact` en false. La máquina conservaba el derecho a
         * escribirle a alguien que acababa de pedir que no le escribieran,
         * hasta que una persona procesara la marca a mano.
         *
         * Corre SÓLO esta herramienta, y no por prudencia: las demás no tienen
         * sentido aquí. El link de pago cuelga de una respuesta que no salió,
         * los enlaces de la app van dentro de un texto que se descartó, y la
         * revisión del equipo ya la decidió la regla de arriba. Lo que se
         * ejecuta es lo que la PERSONA pidió, no lo que el modelo propuso.
         *
         * La condición es la misma que aplica {@see SalesAgentGuardrailService}
         * en el camino normal —la intención `do_not_contact_request` basta— más
         * la petición explícita, que allí también se honra. No se inventa una
         * regla nueva para este camino: se aplica la que ya existía y que aquí
         * faltaba.
         */
        $pideOptOut = $intent === SalesIntents::DO_NOT_CONTACT_REQUEST
            || in_array(SalesIntents::TOOL_MARK_DNC, (array) ($proposal['tools_requested'] ?? []), true);

        $ejecutables = $pideOptOut ? [SalesIntents::TOOL_MARK_DNC] : [];

        /*
         * Y lo que se pidió y AQUÍ no se corre, se anota igual.
         *
         * Antes la fila decía `tools_requested: []` siempre, así que una
         * petición de enlaces de la app en un turno que se rendía desaparecía
         * del expediente entera: ni pedida, ni ejecutada, ni descartada. No
         * ejecutarla es correcto —el texto donde iban los enlaces se descartó—,
         * pero borrar que se pidió no lo es. Quien audite el turno tiene que
         * poder ver la diferencia entre «no se pidió» y «se pidió y este camino
         * no la corre».
         */
        $descartadas = array_values(array_diff(
            (array) ($proposal['tools_requested'] ?? []),
            $ejecutables,
        ));

        $action = $this->persist(
            $conversation,
            $message,
            $payload,
            [
                'intent' => $intent,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                'recommended_action' => SalesIntents::ACTION_REPLY,
                'risk_flags' => [$causa['flag']],
                // La fila dice lo que de verdad se va a ejecutar aquí. Decía
                // siempre `[]`, y eso era falso en cuanto hubo una herramienta.
                'tools_requested' => $ejecutables,
                'needs_staff_review' => $necesitaPersona,
                'staff_review_reason' => $reason,
            ],
            // La fase NO avanza: el critic dijo que la respuesta no servía, así
            // que la conversación no ha progresado.
            $currentPhase,
            null,
            array_merge(array_filter([
                'fallback_mode' => $modo,
                // Evidencia de lo que se descartó, para poder revisarlo. No sale.
                'discarded_draft' => mb_substr((string) ($proposal['reply_draft'] ?? ''), 0, 500),
                'tools_not_run_on_fallback' => $descartadas ?: null,
            ], fn ($v) => $v !== null), $causa['metadata']),
        );

        if ($necesitaPersona) {
            $conversation->forceFill([
                'staff_review_pending' => true,
                'staff_review_reason' => $reason,
            ])->save();
        }

        /*
         * Y ahora sí, el opt-out. Va DESPUÉS de `persist()` —igual que en el
         * camino normal— para que la acción exista y pueda anotar el efecto:
         * una fila que causa un efecto durable y no lo cuenta es un expediente
         * que miente sobre sí mismo, y eso ya se corrigió una vez aquí.
         *
         * No repite nada: el turno entero está protegido por la clave de
         * idempotencia, que se comprueba dos veces —antes del cerrojo y dentro—
         * y tiene un UNIQUE detrás, así que un reintento del mismo turno muere
         * en 409 sin volver a pasar por aquí.
         */
        if ($pideOptOut) {
            $optOut = $this->execMarkDnc($conversation);
            $meta = is_array($action->metadata) ? $action->metadata : [];
            $meta['tools_executed'] = $this->executedTools([$optOut]);
            $meta['mark_do_not_contact'] = array_filter([
                'status' => $optOut['status'] ?? null,
                'reason' => $optOut['reason'] ?? null,
            ], fn ($v) => $v !== null);
            $action->forceFill(['metadata' => $meta])->save();

            ChannelLog::info('ultron.commit.opt_out_on_fallback', [
                'conversation_id' => (int) $conversation->id,
                'ai_action_id' => (int) $action->id,
                'status' => $optOut['status'] ?? null,
            ]);
        }

        $resolution = $this->references->resolve(
            (string) $message->body,
            $this->memoryService->load($conversation),
            $this->memoryService->sellablePlansForMemory(),
        );

        if ($modo === 'NO_REPLY_AND_HANDOFF') {
            $action->forceFill(['status' => 'skipped'])->save();
            $this->memoryService->recordSilentTurn($conversation->fresh(), $message, $resolution);

            ChannelLog::info($causa['log'], [
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
            ['kind' => 'reply', 'origin' => $causa['origin'], 'ai_action_id' => $action->id],
            MarketingMessage::SENDER_AI,
            conversation: $conversation,
        );

        $outcome = $send['sent'] || $send['dry_run'] ? 'curated_fallback' : 'failed';
        $this->finalise($action, $outcome, $send);

        if ($outcome !== 'failed') {
            $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, (string) $curado, null, $intent, $resolution, $send['message_id'] ?? null,
            );
        }

        ChannelLog::info($causa['log'], [
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

    /**
     * ¿Este commit viene a rendirse? Devuelve el motivo etiquetado, o null.
     *
     * Deliberadamente tonto: no valida el motivo contra ninguna lista ni lo usa
     * para decidir nada. Es una etiqueta de auditoría —«esto salió así porque
     * el intento anterior murió en tal guard»— y la forma (código corto, sin
     * prosa ni PII) ya la impone la validación del controlador.
     *
     * @return array{reason:string,attempt:int}|null
     */
    private function recoveryReason(array $payload): ?array
    {
        $rendicion = (array) ($payload['recovery'] ?? []);
        $motivo = trim((string) ($rendicion['reason'] ?? ''));

        if ($motivo === '') {
            return null;
        }

        return [
            'reason' => $motivo,
            'attempt' => max(1, (int) ($rendicion['attempt'] ?? 2)),
        ];
    }

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
    private function allowedTools(array $requested, ?int $conversationId = null): array
    {
        return array_values(array_intersect($requested, $this->decide->allowedTools($conversationId)));
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

    /**
     * Pedir revisión no es obtenerla.
     *
     * Antes sí lo era: bastaba con que el modelo pusiera `staff_review` en
     * `tools_requested` para marcar la conversación, con el motivo genérico
     * `staff_review` porque no había ninguno. Medido en el canario: un turno
     * limpio —pregunta normal, respuesta correcta, sin queja ni lesión ni
     * petición de humano— dejó la bandera levantada. Quien abriera la bandeja
     * veía un aviso que no describía nada.
     *
     * Ahora la causa la decide {@see StaffReviewAuthority}: o la afirma el
     * backend, o es la única que el modelo puede proponer (una operación que
     * el asistente no ejecuta jamás). Lo que se rechaza NO se pierde: queda en
     * el log y en `metadata.staff_review` de la fila, con su motivo. Que eso
     * fuera verdad hubo que arreglarlo: la primera versión de este docblock lo
     * prometía y la fila no lo guardaba en ninguna parte.
     */
    private function execStaffReview(MarketingConversation $conversation, array $decision): array
    {
        $veredicto = $this->staffReview->decide(
            (bool) ($decision['needs_staff_review'] ?? false),
            $decision['staff_review_reason'] ?? null,
            $decision['staff_review_proposed_reason'] ?? null,
        );

        if (! $veredicto['allowed']) {
            ChannelLog::info('ultron.commit.staff_review_not_warranted', [
                'conversation_id' => (int) $conversation->id,
                'refusal' => $veredicto['refusal'],
                'proposed_reason' => $decision['staff_review_proposed_reason'] ?? null,
            ]);

            return [
                'tool' => SalesIntents::TOOL_STAFF_REVIEW,
                'status' => 'skipped',
                'reason' => $veredicto['refusal'],
            ];
        }

        $conversation->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason' => $veredicto['reason'],
        ])->save();

        return [
            'tool' => SalesIntents::TOOL_STAFF_REVIEW,
            'status' => 'created',
            'reason' => $veredicto['reason'],
            'source' => $veredicto['source'],
        ];
    }

    private function execMarkDnc(MarketingConversation $conversation): array
    {
        $lead = $conversation->lead;

        /*
         * Sin lead no hay a quién marcar, y reventar aquí sería peor que no
         * marcar: esta herramienta corre en la rendición, donde el turno ya
         * viene de un fallo. Hasta ahora nadie la llamaba desde un camino que
         * pudiera traer una conversación huérfana; ahora sí.
         */
        if ($lead === null) {
            return ['tool' => SalesIntents::TOOL_MARK_DNC, 'status' => 'skipped', 'reason' => 'no_lead'];
        }

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

        /*
         * Si el cobro ya viajó DENTRO de la respuesta, aquí no se manda nada.
         *
         * Sin esto, un turno de pago acabaría en dos globos con el mismo
         * enlace: el de la respuesta y el de esta herramienta. Ya pasó una vez
         * con los enlaces de la app y se leyó en el canario como lo que
         * parece, el asistente repitiéndose.
         */
        if (($decision['checkout_inlined'] ?? false) === true) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'already_inlined'];
        }

        if (! in_array($tool, (array) ($decision['tools_requested'] ?? []), true)) {
            return null;
        }
        if ($outcome === 'failed') {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'reply_not_sent'];
        }

        /*
         * LAS GUARDAS DEL DINERO NO SE COPIAN: SE LLAMAN.
         *
         * Aquí vivían otra vez, palabra por palabra, las mismas seis que están
         * en {@see mintCheckout()}: el permiso del canario, lead y plan nulos,
         * la autoridad, el otro link vivo, «ya pagó» y la URL vacía. Eran
         * equivalentes, así que no fallaba nada… hasta que dejaron de serlo:
         * el corroborador de intención de pago entró en UNA de las dos, y la
         * asimetría empezó ahí. La misma duplicación ya se había cobrado un
         * fallo el mismo día —el camino nuevo no miraba si había un link vivo
         * para otro plan y generaba el segundo—.
         *
         * Lo único que distingue a esta entrada es la ENTREGA: aquí el cobro
         * sale en un mensaje propio, con el texto curado. Todo lo demás es la
         * misma decisión y se pregunta en el mismo sitio.
         */
        $cobro = $this->mintCheckout($conversation, $conversation->lead, $plan, (int) $message->id);

        if (($cobro['status'] ?? null) !== 'ready') {
            return array_filter([
                'tool' => $tool,
                'status' => $cobro['status'] === 'failed' ? 'failed' : 'skipped',
                'reason' => $cobro['reason'] ?? null,
                'reference' => $cobro['reference'] ?? null,
                'other_plan_id' => $cobro['other_plan_id'] ?? null,
            ], fn ($v) => $v !== null);
        }

        $lead = $conversation->lead;
        $link = $cobro;

        try {
            $body = $this->replies->paymentLinkMessage($plan, (float) $link['amount'], (string) $link['payment_url']);
            $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, $body, [
                'kind' => 'payment_link', 'origin' => 'ultron', 'reference' => $link['reference'] ?? null,
            ], MarketingMessage::SENDER_AI, conversation: $conversation);
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
            /*
             * Esta clave ya sólo cubre la ENTREGA.
             *
             * Antes cubría las dos mitades: los errores de pasarela al acuñar y
             * los de mandar el mensaje. Desde que la acuñación vive en un solo
             * sitio, los primeros salen como `ultron.checkout.failed` desde
             * {@see mintCheckout()} y aquí queda lo que de verdad pasa aquí:
             * que el cobro existe y el globo no salió. Ninguna de las dos se
             * pierde —las dos son `error`—, pero no son el mismo suceso y
             * conviene que no lo parezcan. Comprobado que nadie consume la
             * clave vieja: ni el repo, ni n8n, ni la configuración del
             * servidor.
             */
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
     * Esa anotación es también el FRENO: dentro de
     * {@see self::APP_LINKS_RESEND_MINUTES} minutos la herramienta responde
     * `skipped/already_sent` y no despacha nada.
     *
     * @return array<string,mixed>|null null cuando no se pidió
     */
    /**
     * El saliente tal y como se JUZGÓ, sin lo que Laravel le añadió después.
     *
     * La línea de enlaces es literal —la escribe `MobileAppCatalog`—, así que
     * se quita por igualdad exacta; lo que quede con pinta de URL se tapa con
     * la definición del guard. Sin esto, la novedad compara prosa contra
     * prosa+enlaces y el parecido se hunde por debajo del umbral.
     */
    private function comoSeComparo(string $body): string
    {
        $sinEnlaces = trim(str_replace(MobileAppCatalog::linksInline(), '', $body));

        return OutboundContentGuard::withoutUrls($sinEnlaces) ?? $sinEnlaces;
    }

    /**
     * ¿Llevan enlaces de la app este turno? Sin efectos: sólo el veredicto.
     *
     * Se calcula ANTES del envío porque el texto tiene que salir ya con ellos.
     * Lo que antes decidía un despacho aparte ahora decide una sustitución.
     *
     * Se considera pedido también cuando el BORRADOR trae `{{APP_LINKS}}` sin
     * que la estrategia pidiera la herramienta. El marcador es inerte —no puede
     * producir una URL por sí mismo— y tratarlo como petición evita repetir el
     * patrón que dejó mudo al mensaje 915: un desajuste entre lo que escribió el
     * modelo y lo que esperaba el backend que acaba en 422 y en silencio.
     *
     * @return array{tool:string,status:string,reason?:string,sent_at?:string}|null
     */
    private function appLinksDecision(MarketingConversation $conversation, array $decision, string $draft): ?array
    {
        $tool = SalesIntents::TOOL_APP_LINKS_SEND;
        $pedida = in_array($tool, (array) ($decision['tools_requested'] ?? []), true);

        if (! $pedida && ! $this->placeholders->wantsAppLinks($draft)) {
            return null;
        }

        if ($conversation->lead === null) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'no_lead'];
        }

        // Dentro de la ventana no se repiten: el cliente tiene los tres enlaces
        // unas líneas más arriba, en este mismo chat. Pasada, volver a pedirlos
        // es legítimo («se me borró, reenvíamelo») y salen otra vez.
        $marca = data_get($conversation->memory, 'app_context.links_sent_at');
        if (is_string($marca) && trim($marca) !== '') {
            try {
                $ultimo = CarbonImmutable::parse($marca);
            } catch (Throwable) {
                // Una marca ilegible no puede callar los enlaces para siempre:
                // se trata como si no existiera y el próximo envío la reescribe.
                $ultimo = null;
            }
            if ($ultimo !== null && $ultimo->greaterThan(now()->subMinutes(self::APP_LINKS_RESEND_MINUTES))) {
                return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'already_sent', 'sent_at' => $marca];
            }
        }

        return ['tool' => $tool, 'status' => 'executed'];
    }

    /**
     * ¿Este turno tiene que llevar el cobro dentro, lo haya pedido el modelo o no?
     *
     * Cuatro condiciones, y las cuatro son del backend: la intención validada
     * es de pago, hay plan, el plan se vende hoy y la autoridad del canario
     * permite cobrarle a ESTA conversación. Si alguna falta, se devuelve el
     * motivo y no se toca nada.
     *
     * Reutiliza: `generateForLead()` devuelve la transacción EN VUELO del par
     * (lead, plan) si existe, así que pedir el enlace tres veces sigue siendo
     * una sola referencia. Y si el pago ya está aprobado, no hay checkout que
     * dar: `already_paid` corta antes.
     *
     * NUNCA lanza. Un fallo de la pasarela no puede tumbar un turno; se anota
     * y la respuesta sale sin el enlace, que es peor pero no es mudo.
     *
     * @return array<string,mixed>|null null cuando la intención no es de pago
     */
    private function checkoutDecision(
        MarketingConversation $conversation,
        MarketingLead $lead,
        array &$decision,
        ?Plan $plan,
        string $intent,
        array $resolution,
        string $respuesta = '',
    ): ?array {
        if (! in_array($intent, SalesIntents::PAYMENT_INTENTS, true)) {
            return null;
        }

        /*
         * DOS TESTIGOS PARA ACUÑAR. La etiqueta del modelo no basta.
         *
         * La revisión lo demostró con cinco mensajes que NO piden pagar
         * —«hola, quiero informacion de los planes», «a que hora abren los
         * sabados?» y, el peor, «no me interesa por ahora»— etiquetados como
         * intención de pago: los cinco acuñaban un checkout real y entregaban
         * la URL. Y el daño no es un cobro no querido, que exige teclear la
         * tarjeta: es un enlace pagable POR QUIEN LO TENGA en manos de alguien
         * que acaba de decir que no, una transacción en vuelo que bloquea el
         * link del plan correcto, y una conversación que se lee como presión.
         *
         * El intent y el plan los pone la MISMA etiqueta que esta mañana se
         * equivocó en la dirección contraria. Así que hace falta un segundo
         * testigo, y de los dos que hay sirve cualquiera:
         *
         *   EL RESOLVER. `ReferenceResolver` lee el mensaje contra la memoria
         *   y lo calcula LARAVEL, no el modelo. Es el mismo tipo de
         *   corroboración que se le exige al traspaso. Medido: el mensaje real
         *   del canario da `send_it`, y los cinco falsos positivos dan `none`
         *   o `price_of_pending`.
         *
         *   LA PETICIÓN EXPLÍCITA. Que el modelo además PIDA la herramienta.
         *   No es un testigo independiente, pero exige dos errores suyos a la
         *   vez —clasificar mal Y pedir cobrar— en vez de uno, y es el camino
         *   que ya existía antes de todo esto.
         *
         * Lo que NO se usa es una lista de frases. Se midió: contra el banco
         * de expresiones cubre 2 de 20, porque la mitad de esa categoría son
         * preguntas por el medio de pago y reclamos de pagos ya hechos —«ya
         * pagué», «reciben nequi»—, no peticiones de enlace. Sería el error de
         * `asesor`/`asesoria` con dinero encima.
         */
        /*
         * EL TERCER TESTIGO: QUE NOSOTROS MISMOS LO NEGUEMOS.
         *
         * Los dos primeros fallaron juntos en un turno real. A «y tienes un
         * link de pago mas directo de casualidad?» el resolver dio `none` y el
         * modelo pidió… los enlaces de la app. La intención estaba bien
         * clasificada —`payment_link_request`— pero sin corroborar, así que el
         * cobro se saltó y salió «No contamos con un link de pago directo».
         *
         * Los dos testigos que había dependen de que alguien ACIERTE: el
         * resolver, calculando; el modelo, pidiendo la herramienta que sus
         * propios prompts le enseñaron a no pedir. Éste no depende de acertar,
         * sino de algo que ya ocurrió: si la respuesta NIEGA que exista un
         * enlace de pago, es porque entendió perfectamente que se lo estaban
         * pidiendo. La negación es la prueba.
         *
         * Y es un testigo que los falsos positivos no producen: los cinco
         * mensajes mal etiquetados de la revisión —«hola, quiero informacion de
         * los planes», «a que hora abren los sabados?», «no me interesa por
         * ahora»— se contestan hablando de planes, de horarios o despidiéndose.
         * Ninguna de esas respuestas niega un enlace de pago, porque nadie lo
         * había pedido.
         */
        $nego = $this->contentGuard->checkoutDenialIn($respuesta);

        $corroborado = in_array($resolution['type'] ?? null, self::RESOLUCIONES_QUE_PAGAN, true)
            || in_array(SalesIntents::TOOL_PAYMENT_LINK_SEND, (array) ($decision['tools_requested'] ?? []), true)
            || $nego !== null;

        if (! $corroborado) {
            ChannelLog::info('ultron.checkout.intent_not_corroborated', [
                'conversation_id' => (int) $conversation->id,
                'intent' => $intent,
                'resolution' => $resolution['type'] ?? null,
            ]);

            return ['status' => 'skipped', 'reason' => 'payment_intent_not_corroborated'];
        }

        $cobro = $this->mintCheckout($conversation, $lead, $plan);

        if (($cobro['status'] ?? null) !== 'ready') {
            return $cobro;
        }

        // La herramienta de después no vuelve a mandarlo.
        $decision['checkout_inlined'] = true;

        $this->memoryService->recordPaymentLink($conversation->fresh(), [
            'reference' => $cobro['reference'] ?? null,
            'plan_id' => (int) $plan->id,
            'expires_at' => $cobro['expires_at'] ?? null,
            'status' => 'pending',
            'link_sent_at' => now()->toIso8601String(),
        ]);

        ChannelLog::info('ultron.checkout.inlined', [
            'conversation_id' => (int) $conversation->id,
            'plan_id' => (int) $plan->id,
            'reference' => $cobro['reference'] ?? null,
        ]);

        // `+` sobre arrays conserva la clave de la IZQUIERDA, así que
        // `$cobro + ['status' => 'inlined']` seguía devolviendo 'ready' y el
        // llamador no inyectaba nada. Con array_merge gana la derecha.
        return array_merge($cobro, ['status' => 'inlined', 'nego_el_cobro' => $nego]);
    }

    /**
     * Las guardas del cobro, en UN solo sitio.
     *
     * Estaban escritas dos veces —aquí y donde se despachaba el link— durante
     * exactamente una hora, y en esa hora se coló el fallo que tenían que
     * impedir: mi camino nuevo no comprobaba si ya había un link vivo para OTRO
     * plan, así que generaba el segundo. Dos links vivos son dos cobros
     * posibles, y el checkout de Wompi no se puede anular desde aquí.
     *
     * Por eso las guardas del dinero no se copian: se llaman. Las dos entradas
     * —el cobro dentro de la respuesta y la herramienta que lo manda aparte—
     * pasan por aquí, y lo único que las distingue es cómo se entrega.
     *
     * NUNCA lanza: un fallo de la pasarela no puede tumbar un turno.
     *
     * @return array<string,mixed> con `status` ready|skipped y su motivo
     */
    /**
     * PAYMENT_CHECKOUT_AVAILABLE + OUTBOUND_DENIES_CHECKOUT: cae el borrador.
     *
     * «Hard fail» es del BORRADOR. Tumbar el turno entero sería cambiar una
     * mentira por un silencio, y el silencio aquí es peor de lo que parece: la
     * fila de la acción se persiste como ejecutada antes de llegar hasta aquí,
     * así que el vigía de turnos sin desenlace no vería nada, no abriría
     * incidente y no accionaría el freno del canario. Se lanzaba un 422 y eso
     * hacía exactamente eso; ya no.
     *
     * El arreglo de verdad está arriba, en {@see checkoutDecision()}: si la
     * respuesta niega el cobro, esa negación vale como prueba de que a la
     * persona le interesaba pagar, se acuña y el borrador se sustituye entero
     * por el texto curado. Aquí abajo sólo quedan los casos en que NO se pudo
     * acuñar —otro enlace vivo para otro plan, la pasarela caída— o en que la
     * negación llegó por un camino que no contemplamos.
     *
     * En esos, se quita la frase que miente y sale el resto. Si no queda nada
     * que se sostenga, sale un texto curado que dice lo que SÍ es verdad: se
     * puede pagar, y por dónde. Nunca se promete que alguien escriba.
     *
     * @return string la respuesta que puede salir
     */
    private function sinNegarUnCobroDisponible(
        MarketingConversation $conversation,
        MarketingLead $lead,
        ?Plan $plan,
        string $respuesta,
        MarketingAiAction $action,
    ): string {
        if ($this->contentGuard->checkoutDenialIn($respuesta) === null) {
            return $respuesta;
        }

        // Sin plan vendible o sin permiso NO hay cobro que ofrecer: la negación
        // es verdad y tiene que poder decirse.
        if ($plan === null || ! $plan->isSellable() || ! $this->decide->canOfferLink((int) $conversation->id)) {
            return $respuesta;
        }

        // Y a quien ya pagó no se le reescribe el turno por una frase que ya no
        // cambia nada: su caso lo resuelve el enrutado con `already_paid`.
        $yaPago = PaymentTransaction::query()
            ->where('idempotency_key', 'like', 'mkt-lead-'.$lead->id.'-plan-'.$plan->id.'-%')
            ->where('status', SM::APPROVED)
            ->exists();

        if ($yaPago) {
            return $respuesta;
        }

        // Frase a frase, porque lo que sobra es UNA, no el mensaje entero.
        $frases = preg_split('/(?<=[.!?])\s+/u', trim($respuesta)) ?: [];
        $limpias = array_values(array_filter(
            $frases,
            fn (string $frase) => $this->contentGuard->checkoutDenialIn($frase) === null,
        ));

        $limpio = trim(implode(' ', $limpias));

        ChannelLog::error('ultron.checkout.denied_but_available', [
            'conversation_id' => (int) $conversation->id,
            'plan_id' => (int) $plan->id,
            'frases_retiradas' => count($frases) - count($limpias),
            'quedo_vacio' => $limpio === '',
        ]);

        /*
         * Y QUEDA EN LA FILA, no sólo en un log que no lee nadie.
         *
         * El log no llega a ningún panel: el vigía mira `metadata.recovery` y
         * el detector de salud mira tablas, no ficheros. Sin esto, el CRM
         * reescribiría al modelo en silencio para siempre —y la causa raíz
         * sigue ahí, porque el prompt todavía le enseña a no mencionar el
         * enlace—. Si mañana esto pasa en uno de cada diez turnos o en todos,
         * la diferencia tiene que verse en algún sitio.
         *
         * Va donde mira el acta del canario, que es quien cuenta lo que hizo
         * cada turno, igual que ya cuenta las rendiciones para no atribuirle al
         * modelo un texto que escribió Laravel.
         */
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['checkout_denial_dropped'] = [
            'code' => OutboundContentGuard::CODE_DENIES_AVAILABLE_CHECKOUT,
            'frases_retiradas' => count($frases) - count($limpias),
            'texto_curado' => $limpio === '',
        ];
        $action->forceFill(['metadata' => $meta])->save();

        return $limpio !== '' ? $limpio : self::SIN_ENLACE_PERO_SIN_MENTIR;
    }

    private function mintCheckout(MarketingConversation $conversation, ?MarketingLead $lead, ?Plan $plan, ?int $messageId = null): array
    {
        if (! $this->decide->canOfferLink((int) $conversation->id)) {
            return ['status' => 'skipped', 'reason' => 'automatic_links_disabled'];
        }
        if ($lead === null || $plan === null) {
            return ['status' => 'skipped', 'reason' => $plan === null ? 'no_plan_to_charge' : 'no_lead'];
        }
        /*
         * La vendibilidad NO se pregunta aquí.
         *
         * Estaba, y era la TERCERA copia de la misma regla: la resolución del
         * plan ya tumba el turno aguas arriba y el guardrail la vuelve a exigir
         * dos líneas más abajo. Además esta copia era peor que las otras,
         * porque colapsaba `plan_inactive`, `plan_price_invalid` y
         * `plan_duration_invalid` en un `plan_not_sellable` genérico: quien
         * mirara el motivo perdía el dato que explicaba el rechazo.
         *
         * Quitarla no abre nada —`assertCanGeneratePaymentLink()` la comprueba
         * y lanza con el código exacto— y es lo que este método vino a hacer:
         * que la regla del dinero viva en un sitio.
         */

        try {
            $this->paymentGuardrail->assertCanGeneratePaymentLink($lead, $plan, [], [
                'conversation_id' => (int) $conversation->id,
            ]);

            // Dos links vivos para planes distintos serían dos cobros posibles,
            // y el checkout de Wompi no se puede anular desde aquí: el segundo
            // no se genera y lo resuelve una persona.
            $otro = PaymentTransaction::query()
                ->where('idempotency_key', 'like', 'mkt-lead-'.$lead->id.'-plan-%')
                ->where('plan_id', '!=', $plan->id)
                ->whereIn('status', SM::IN_FLIGHT)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('id')
                ->first();

            if ($otro !== null) {
                $conversation->forceFill([
                    'staff_review_pending' => true,
                    'staff_review_reason' => 'payment_link_plan_change',
                ])->save();

                return ['status' => 'skipped', 'reason' => 'another_link_in_flight', 'other_plan_id' => (int) $otro->plan_id];
            }

            $link = WompiPaymentLinkService::make()->generateForLead($lead, $plan, [
                'channel' => $conversation->channel,
                'conversation_id' => (int) $conversation->id,
                'message_id' => $messageId,
            ]);
        } catch (SalesGuardrailException $e) {
            return ['status' => 'skipped', 'reason' => $e->errorCode];
        } catch (Throwable $e) {
            ChannelLog::error('ultron.checkout.failed', [
                'conversation_id' => (int) $conversation->id,
                'plan_id' => (int) $plan->id,
                'exception' => class_basename($e),
            ]);

            return ['status' => 'failed', 'reason' => 'payment_engine_error'];
        }

        if (($link['configured'] ?? true) === false) {
            return ['status' => 'skipped', 'reason' => 'wompi_checkout_not_configured'];
        }
        if (($link['already_paid'] ?? false) === true) {
            return ['status' => 'skipped', 'reason' => 'already_paid', 'reference' => $link['reference'] ?? null];
        }
        if (empty($link['payment_url'])) {
            return ['status' => 'skipped', 'reason' => $link['error'] ?? 'link_not_safe_to_send'];
        }

        return [
            'status' => 'ready',
            'payment_url' => (string) $link['payment_url'],
            'reference' => $link['reference'] ?? null,
            'amount' => (float) ($link['amount'] ?? 0),
            'expires_at' => $link['expires_at'] ?? null,
        ];
    }

    /**
     * El texto final con el cobro dentro.
     *
     * Va al final y en su propia línea. El modelo no escribe URLs —el guard se
     * lo prohíbe y el Critic lo tumba—, así que aquí no hay marcador que
     * sustituir: lo pega Laravel después de todos los guards, igual que el
     * precio y los enlaces de la app.
     */
    private function conCheckout(string $reply, array $cobro): string
    {
        /*
         * Con el PRECIO dentro. La línea sustituye a un mensaje curado que sí
         * lo llevaba, y quitarlo habría dejado a alguien abriendo un checkout
         * sin saber cuánto va a pagar hasta verlo en la pasarela.
         */
        $monto = number_format((float) ($cobro['amount'] ?? 0), 0, ',', '.');

        return rtrim($reply)."\n\nEste es tu enlace de pago seguro por $".$monto.":\n".$cobro['payment_url'];
    }

    /**
     * El texto final con los enlaces puestos.
     *
     * Con marcador van donde el modelo los quiso; sin marcador van al final,
     * separados, porque el borrador ya los anunció en prosa y el contrato con
     * los dos prompts dice que los escribe Laravel.
     */
    private function conEnlaces(string $reply, string $enlaces): string
    {
        if ($this->placeholders->wantsAppLinks($reply)) {
            return $this->placeholders->resolveAppLinks($reply, $enlaces);
        }

        return rtrim($reply)."\n\n".$enlaces;
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
            /*
             * Sin los enlaces, porque el borrador con el que se comparan tampoco
             * los lleva todavía: la sustitución de {{APP_LINKS}} ocurre después
             * de la novedad. Comparar prosa contra prosa+URLs hundía el parecido
             * —192 caracteres de enlace bajan un Jaccard de 1.000 a 0.519, por
             * debajo del umbral de 0.85— y desactivaba la red que impide mandar
             * dos veces el mismo párrafo. Se comprobó de punta a punta.
             */
            ->map(fn (string $b) => $this->comoSeComparo($b))
            ->values()->all();
    }
}
