<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\MarketingAgentSwitch;
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
use Illuminate\Support\Carbon;
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
    /** Lo que sale cuando el borrador entero daba por confirmada una visita. */
    private const CORTESIA_SIN_CONFIRMAR = 'Dejé registrada tu solicitud de día de cortesía. El equipo de Iron Body la revisará para tener todo listo para tu visita.';

    /**
     * Cuando la guarda retira TODO y no hay ninguna solicitud de cortesía.
     *
     * No afirma nada: ni que algo quedó registrado, ni que alguien espera a
     * nadie. Devuelve la conversación al único sitio donde puede seguir sin
     * mentir, que es preguntar.
     */
    private const NADA_QUE_CONFIRMAR = 'Para no darte un dato equivocado: la visita la confirma el equipo de Iron Body. ¿Qué día y a qué hora te quedarían bien?';

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
     * Las resoluciones que por sí solas prueban que alguien quiere PAGAR.
     *
     * Era una lista de cuatro y se midió que tres de ellas no lo prueban. El
     * caso concreto: el agente ofrece un plan, la persona contesta «me
     * interesa», el resolver lo llama `choose_plan` —está en su lista de
     * verbos de elección— y con eso se acuñaba y se entregaba un enlace
     * pagable COLGADO DE UNA PREGUNTA DE DESCUBRIMIENTO: «¿buscas bajar de
     * peso o ganar masa?» y debajo «Este es tu enlace de pago seguro por
     * $80.000». A quien solo estaba mirando.
     *
     * Elegir un plan, preguntar cómo se empieza o decir que sí a una oferta
     * cualquiera es INTERÉS. Querer pagar es otra cosa, y sólo `send_it` —«me
     * lo mandas», «pásame el link»— lo dice sin ambigüedad.
     *
     * La única aceptación que cuenta va aparte, abajo, porque depende de QUÉ
     * se aceptó: decir que sí a «¿te paso el link de pago?» sí es querer
     * pagar; decir que sí a «¿te explico cómo empezar?» no.
     *
     * Esto no deja a nadie sin poder pagar: quedan los otros dos testigos —que
     * el modelo pida la herramienta y que la respuesta niegue el cobro— y una
     * frase de pago resuelve a `send_it`.
     */
    private const RESOLUCIONES_QUE_PAGAN = [
        ReferenceResolver::SEND_IT,
    ];

    /** La oferta que, aceptada, sí prueba intención de pagar. */
    private const OFERTA_DE_COBRO = 'send_payment_link';

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
        private readonly CourtesyRequestService $courtesy = new CourtesyRequestService,
        private readonly GymFactsProvider $gym = new GymFactsProvider,
        private readonly LeadProfileService $leadProfile = new LeadProfileService,
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
         * ── 7.ter) LA HORA DE LA VISITA LA DECIDE LARAVEL ─────────────────────
         *
         * Medido en el canario el 2026-10-04: la visita a las 18:00, «Mejor
         * mañana a las 7», y el Strategist etiquetó el turno `goal_fat_loss`
         * sin pedir la herramienta; el critic tumbó los dos borradores y salió
         * el respaldo de ESA etiqueta: «Listo. Para bajar grasa…». La pregunta
         * de a. m./p. m. sólo vivía en el camino de la herramienta, y aquí ni
         * se pidió la herramienta ni hubo camino normal.
         *
         * Con una visita viva (o a medio concretar) y una hora con dos lecturas
         * dentro del horario, el turno es de la visita diga lo que diga la
         * etiqueta: no se ejecuta nada y se pregunta. La respuesta a esa
         * pregunta («7 p. m.») la resuelve Laravel también, porque la pregunta
         * la hizo él. Va antes de la rendición y del critic justo por eso: esos
         * caminos eligen el texto por la etiqueta del modelo. Y después del
         * token, del relevo y de la elegibilidad: no es un salvoconducto.
         */
        if ($horaDeLaVisita = $this->horaDeLaVisita($conversation, $message, $proposal)) {
            return $this->contestarLaHoraDeLaVisita($conversation, $message, $payload, $currentPhase, $horaDeLaVisita);
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

        /*
         * UN SALUDO PURO ES UN SALUDO, diga lo que diga la etiqueta del modelo.
         *
         * `decide` ya lo recalcula desde el texto; si aquí se leyera la
         * etiqueta de la propuesta, bastaría que el modelo llamara
         * «high_intent_close» a un «hola» para que el modo recepción no
         * existiera en el commit y saliera plan, precio y pregunta de
         * objetivo. La revisión lo midió. Mismo criterio en las dos mitades.
         */
        if ($sanitized['intent'] !== SalesIntents::GREETING && SalesIntents::isPureGreeting((string) $message->body)) {
            ChannelLog::info('ultron.commit.intent_overridden_by_greeting', [
                'conversation_id' => (int) $conversation->id, 'proposed' => $sanitized['intent'],
            ]);
            $sanitized['intent'] = SalesIntents::GREETING;
        }

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
            // Y las frases comparativas que el negocio aprobó por escrito: sin
            // esto, «el mejor gimnasio de Neiva» mata el turno incluso cuando
            // el negocio la autorizó, y con esto sigue muriendo cuando no.
            $this->knowledge->approvedBrandCopy(),
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
        // El referente se resuelve sobre la memoria de ANTES de este turno.
        $memoriaPrevia = $this->memoryService->load($conversation);
        // Y la ficha del lead aprende del delta entre esta foto y lo que quede
        // al final. Copia, no referencia: la memoria es mutable, y `$memoriaPrevia`
        // se recarga más abajo con el rechazo ya dentro, lo que dejaba el
        // episodio «Rechazó el X» imposible de ver.
        $memoriaAlEntrar = ConversationMemory::fromArray($memoriaPrevia->toArray());
        $resolution = $this->references->resolve(
            (string) $message->body,
            $memoriaPrevia,
            $planesVendibles,
        );

        $previas = $this->previousMachineReplies($conversation);
        /*
         * Una pregunta de MEMORIA pide repetir por definición: «¿a qué hora
         * era mi visita?» por segunda vez en el día merece la misma hora, no un
         * «cuéntame en qué te ayudo». Mismo criterio que la política.
         */
        $pideRepetir = $this->novelty->isExplicitRepeatRequest((string) $message->body)
            || CommercialTurnPolicy::esTurnoDeMemoria((string) $sanitized['intent'], (string) $message->body, $this->knowledge->activePlans(), $resolution);
        $duplicadoDe = $pideRepetir
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

        /*
         * 10.bis) LA POLÍTICA DEL TURNO. QUÉ HAY QUE HACER, DECIDIDO AQUÍ.
         *
         * Las reglas comerciales estaban escritas en el prompt y una prueba
         * física las desmintió las tres: se preguntó el objetivo sin contar
         * nada, se volvió a saludar en el segundo turno y se ofreció el plan
         * por defecto en vez del insignia. Una regla que sólo vive en el
         * prompt es una sugerencia.
         *
         * El plan OBLIGATORIO se impone aquí, antes de rellenar el borrador,
         * para que el precio y el nombre que se inyectan sean los suyos. No
         * hay id escrito en el código: el insignia es el que el CRM marca como
         * recomendado, y deja de ser obligatorio en cuanto la persona pide
         * otro, ya lo rechazó, o viene hablando de otro sin ambigüedad.
         */
        $policy = CommercialTurnPolicy::decide(
            (string) $sanitized['intent'],
            $currentPhase,
            $memoriaPrevia,
            $this->knowledge->activePlans(),
            $resolution,
            (string) $message->body,
        );

        /*
         * Un turno de MEMORIA sólo contesta: la persona preguntó por su visita.
         * Si el modelo pidió el enlace de pago, los de la app o la cortesía, se
         * retiran aquí, ANTES de que se acuñe o se registre nada: un cobro
         * acuñado para un texto que la política va a sustituir quedaría vivo
         * sin que nadie lo viera, y la cortesía, rellenada con el día y la hora
         * de la PREGUNTA, movía la visita y le quitaba la aprobación. Cambiar o
         * cancelar nunca entra en modo memoria, así que aquí no se pierde nada.
         * El marcador de la app tampoco se honra: no hay enlaces en este turno.
         */
        if (($policy['memory_mode'] ?? false) === true) {
            $soloContesta = fn (array $tools): array => array_values(array_diff($tools, [SalesIntents::TOOL_PAYMENT_LINK_SEND, SalesIntents::TOOL_APP_LINKS_SEND, SalesIntents::TOOL_COURTESY_REQUEST]));
            $sanitized['tools_requested'] = $soloContesta((array) ($sanitized['tools_requested'] ?? []));
            $proposal['tools_requested'] = $soloContesta((array) ($proposal['tools_requested'] ?? []));
            $replyFinal = $this->placeholders->resolveAppLinks($replyFinal, '');
        }

        /*
         * «Menciona otro plan» es un NO a lo anterior, y se anota como tal.
         * Sin esto, dos turnos después el insignia volvería a ser obligatorio
         * y se le ofrecería otra vez lo que acaba de rechazar.
         */
        if (($policy['wants_alternative'] ?? false) === true) {
            /*
             * Se rechaza el plan que se OFRECIÓ, no el insignia.
             *
             * «El trimestre está muy caro» es un no al trimestre. Anotar el
             * insignia porque sí lo dejaba vetado para siempre sin que nadie
             * lo hubiera rechazado, y de paso dejaba vivo el que sí molestaba.
             * El orden es: el que la persona nombra, el último que se le
             * recomendó, y sólo si no hay ninguno, el insignia.
             */
            $negados = array_values(array_map('intval', (array) ($policy['rejected_plan_ids'] ?? [])));
            $rechazados = $negados !== []
                ? $negados
                : array_filter([
                    ($policy['required_plan_id'] !== null && in_array($policy['required_plan_source'] ?? null, ['named_by_person', 'resolved_reference'], true))
                        ? $policy['required_plan_id']
                        : (($memoriaPrevia->get('last_recommendation')['plan_ids'][0] ?? null)
                            ?? CommercialTurnPolicy::planInsignia($this->knowledge->activePlans())),
                ]);

            foreach ($rechazados as $rechazado) {
                $this->memoryService->recordPlanRejected($conversation, (int) $rechazado);
            }
            if ($rechazados !== []) {
                $memoriaPrevia = $this->memoryService->load($conversation->fresh());
            }
        }

        /*
         * LA POLÍTICA PISA EL PLAN DEL MODELO, venga de la prioridad comercial
         * o de que la persona lo NOMBRÓ.
         *
         * Antes sólo pisaba la prioridad comercial, con el argumento de que si
         * la persona lo nombró «el id de la propuesta ya es el bueno». Una
         * prueba física lo desmintió: «¿cuánto vale el mensual?» llegó con el
         * Trimestral propuesto por el estratega y «Plan Mensual» escrito por
         * el redactor, y salió el precio del Trimestral bajo el nombre del
         * Mensual. El plan que la persona nombra es un HECHO, corroborado por
         * el resolutor de Laravel; ni el estratega ni el redactor lo mueven,
         * y con él va el precio y el cobro. El texto que nombre otro se
         * sustituye más abajo.
         */
        if ($policy['required_plan_id'] !== null
            && in_array($policy['required_plan_source'] ?? null, ['commercial_priority', 'named_by_person'], true)
            && (int) $policy['required_plan_id'] !== ($plan?->id)) {
            $forzado = $this->resolvePlan((int) $policy['required_plan_id'], $sanitized['reply']);

            if ($forzado !== null) {
                ChannelLog::info('ultron.commit.policy_plan_forced', [
                    'conversation_id' => (int) $conversation->id,
                    'propuesto' => $plan?->id,
                    'obligatorio' => $forzado->id,
                    'origen' => $policy['required_plan_source'] ?? null,
                ]);

                $plan = $forzado;
                $replyFinal = $this->placeholders->resolve($sanitized['reply'], $plan);
            }
        }

        /*
         * 10.quater) EL PRECIO ES EL DEL PLAN QUE LAS PALABRAS NOMBRAN.
         *
         * Prueba física: el estratega eligió el Mensual, el redactor escribió
         * «Plan Semana» y el marcador se rellenó con el precio del Mensual.
         * Salió «El Plan Semana … por $80.000» y al turno siguiente «$45.000»:
         * dos precios para el mismo nombre, y ninguna guarda lo vio, porque
         * cada una miraba una mitad. El nombre y el precio son UNA cosa.
         *
         * Si el texto nombra exactamente un plan y no es el que se iba a
         * cotizar, se cotiza el que nombra: el precio sigue saliendo del
         * catálogo del CRM, sólo que del plan correcto. Si nombra varios y
         * cotiza, no se adivina: sale el respaldo sin cifra. Nada de esto
         * corre cuando la prioridad comercial ya impuso el plan: ahí el texto
         * que nombra otro se sustituye unos pasos más abajo.
         */
        $realineado = null;
        $catalogoCompleto = $this->knowledge->activePlans();
        $nombrados = CommercialTurnPolicy::plansNamedIn($sanitized['reply'], $catalogoCompleto);
        /*
         * NO se realinea cuando la política ya fijó el plan, venga de donde
         * venga. Con la prioridad comercial, el texto que nombra otro se
         * sustituye más abajo. Y cuando el plan lo NOMBRÓ LA PERSONA, el id
         * de la propuesta es el suyo: dejar que la prosa del redactor lo
         * mueva habría movido también el COBRO —la revisión acuñó un link del
         * Trimestral a quien pidió el Mensual—, y el modelo no toca el dinero.
         */
        $obligado = $policy['required_plan_id'] !== null ? (int) $policy['required_plan_id'] : null;
        $fuerzaComercial = $obligado !== null && (
            in_array($policy['required_plan_source'] ?? null, ['commercial_priority', 'named_by_person'], true)
            || $obligado === ($plan?->id)
        );
        /*
         * Una cifra sólo es atribuible cuando el texto nombra UN plan (o ninguno
         * y habla del que se cotiza). Con dos nombres y una cifra, quien lee
         * decide a cuál pertenece, y no tiene por qué acertar: sale sin cifra.
         */
        $cotiza = $this->placeholders->needsPlan($sanitized['reply']);
        // El dueño de la cifra: el plan nombrado en la misma oración que el
        // marcador; si nombra uno solo en todo el texto, ése. Con varios
        // nombres y ningún dueño claro, no se adivina.
        $dueno = $cotiza ? CommercialTurnPolicy::planOwningPrice($sanitized['reply'], $catalogoCompleto) : null;
        if ($dueno === null && count($nombrados) === 1) {
            $dueno = $nombrados[0];
        }
        $ambiguo = $cotiza && $nombrados !== [] && $dueno === null;
        // Con un plan obligado por referencia y otro propuesto, la cifra sólo
        // puede moverse HACIA el obligado: nunca hacia un tercero que nombre
        // la prosa.
        if ($obligado !== null && $dueno !== null && $dueno !== $obligado) {
            $dueno = null;
            $ambiguo = $cotiza;
        }
        /*
         * EN UN TURNO DE COBRO LA CIFRA NO SE MUEVE NUNCA. El link se acuña
         * para el plan de la propuesta, que ya valida la autoridad de pagos;
         * si la prosa nombra otro, la prosa se sustituye y el link sale igual.
         * Mover el plan por lo que escribió el redactor acuñó un cobro de
         * 210.000 a quien pidió el de 80.000, y eso es el modelo tocando el
         * dinero.
         */
        $turnoDeCobro = in_array(SalesIntents::TOOL_PAYMENT_LINK_SEND, (array) ($sanitized['tools_requested'] ?? []), true)
            || in_array((string) $sanitized['intent'], [SalesIntents::HIGH_INTENT_CLOSE, SalesIntents::PAYMENT_LINK_REQUEST], true);
        if ($plan !== null && ! $fuerzaComercial && $nombrados !== [] && ($ambiguo || ($dueno !== null && $dueno !== (int) $plan->id) || (! $cotiza && ! in_array((int) $plan->id, $nombrados, true) && count($nombrados) === 1))) {
            if ($turnoDeCobro) {
                ChannelLog::warning('ultron.commit.plan_price_mismatch', [
                    'conversation_id' => (int) $conversation->id,
                    'cotizado' => (int) $plan->id,
                    'nombrados' => $nombrados,
                    'turno_de_cobro' => true,
                ]);
                $sinCifra = $this->ultimoRecurso($conversation, (string) $sanitized['intent']);
                if ($sinCifra !== null) {
                    // El plan se queda: es el del link. Solo cambia el texto.
                    $realineado = ['from' => (int) $plan->id, 'to' => (int) $plan->id, 'fallback' => true, 'money_turn' => true];
                    $replyFinal = $sinCifra;
                }
            } elseif ($dueno !== null || ($obligado === null && count($nombrados) === 1)) {
                try {
                    $alineado = $this->resolvePlan($dueno ?? $nombrados[0], $sanitized['reply']);
                } catch (UltronCommitException $e) {
                    $alineado = null;
                }
                if ($alineado !== null) {
                    ChannelLog::warning('ultron.commit.plan_price_realigned', [
                        'conversation_id' => (int) $conversation->id,
                        'cotizado' => (int) $plan->id,
                        'nombrado' => (int) $alineado->id,
                    ]);
                    $realineado = ['from' => (int) $plan->id, 'to' => (int) $alineado->id];
                    $plan = $alineado;
                    $replyFinal = $this->placeholders->resolve($sanitized['reply'], $plan);
                }
            }
            if ($realineado === null) {
                ChannelLog::warning('ultron.commit.plan_price_mismatch', [
                    'conversation_id' => (int) $conversation->id,
                    'cotizado' => (int) $plan->id,
                    'nombrados' => $nombrados,
                ]);
                /*
                 * El respaldo HABLA DEL PLAN, sin cifra. La revisión midió que
                 * «Tenemos el Mensual por {{PLAN_PRICE}} y también el
                 * Trimestral; ¿cuál te sirve?» acababa en «dime si empiezas
                 * este mes»: se perdía todo. El plan cotizado se presenta con
                 * sus beneficios del CRM y la puerta abierta; la cifra, que era
                 * lo ambiguo, es lo único que no sale.
                 */
                /*
                 * Se conserva el texto y se retira SOLO la oración del marcador;
                 * la cifra vuelve en una frase propia, atribuida al plan que el
                 * estratega eligió y Laravel validó. Así no se pierde lo que el
                 * redactor contó de los dos planes y la persona lee un precio
                 * con dueño explícito.
                 */
                $conservado = $this->sinLaOracionDelMarcador($sanitized['reply']);
                $frasePrecio = 'El '.trim((string) $plan->name).' cuesta '.$this->replies->formatCop((float) $plan->price).'.';
                $realineado = ['from' => (int) $plan->id, 'to' => (int) $plan->id, 'price_sentence' => true];
                $replyFinal = trim($conservado === '' ? $frasePrecio : $conservado.' '.$frasePrecio);
            }
        }

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
            /*
             * El día y la hora de la cortesía viajan tal y como los propuso el
             * modelo. No se interpretan aquí: quien decide si eso se puede
             * registrar es {@see CourtesyAuthority}, contra el horario aprobado.
             */
            'courtesy_action' => $proposal['courtesy_action'] ?? null,
            'courtesy_date' => $proposal['courtesy_date'] ?? null,
            'courtesy_time' => $proposal['courtesy_time'] ?? null,
            'safe_to_send' => false,
            'responder' => 'ultron',
        ], $lead);

        if ($respuestaRepetida) {
            $decision['should_send_message'] = false;
            $decision['recommended_action'] = 'repeated_reply';
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['repeated_reply'])));
        }
        if ($revende) {
            $decision['should_send_message'] = false;
            $decision['should_generate_payment_link'] = false;
            $decision['recommended_action'] = 'resell_blocked';
            $decision['risk_flags'] = array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), ['resell_blocked'])));
        }

        /*
         * LA MISMA PUERTA AL SILENCIO, CON EL JUEZ DEL OTRO LADO.
         *
         * El último recurso vivía sólo en la rendición —cuando el critic TUMBA
         * el borrador—, y este camino es el de cuando el critic lo APRUEBA y
         * lo tumbamos nosotros: repetido, o venta a quien ya compró. Acababa
         * igual, en `skipped` sin saliente, y además sin marca para nadie.
         * Misma persona esperando, otra puerta.
         *
         * Que el texto del modelo no pueda salir no significa que no haya nada
         * que decir: significa que hay que decir OTRA cosa. Estos dos motivos
         * son recuperables por definición —«ya lo dijiste» y «a éste no le
         * vendas»— y los dos se resuelven con un texto que ni repite ni vende.
         *
         * Lo que NO se recupera aquí es el resto: un texto que un guard tumbó
         * por lo que dice es un problema del texto, y ése camino ya tiene su
         * propia salida más abajo. Y quien pide que no le escriban sigue sin
         * recibir nada, se mire el intent o la herramienta.
         */
        $intentPropuesto = (string) ($proposal['intent'] ?? SalesIntents::UNKNOWN);
        $pideBaja = $intentPropuesto === SalesIntents::DO_NOT_CONTACT_REQUEST
            || in_array(SalesIntents::TOOL_MARK_DNC, (array) ($proposal['tools_requested'] ?? []), true);

        if (($respuestaRepetida || $revende) && ! $pideBaja) {
            $alternativa = $this->ultimoRecurso($conversation, $intentPropuesto);

            if ($alternativa !== null) {
                ChannelLog::info('ultron.commit.last_resort_recovered', [
                    'conversation_id' => (int) $conversation->id,
                    'motivo' => $decision['recommended_action'],
                ]);

                $replyFinal = $alternativa;
                $decision['safe_to_send'] = true;
                $decision['should_send_message'] = true;
                $decision['recommended_action'] = $decision['recommended_action'].'_recovered';

                /*
                 * La bandera es lo que gobierna; la etiqueta sólo se lee.
                 *
                 * Esto colgaba de mirar si `recommended_action` terminaba en
                 * «_recovered», y una puerta del dinero que depende de cómo se
                 * escriba una cadena se desarma sola el día que alguien
                 * renombre la etiqueta, sin que nada se ponga rojo.
                 */
                $decision['recovered_reply'] = true;

                /*
                 * Y LAS HERRAMIENTAS SE QUEDAN FUERA. Esto casi se me escapa y
                 * lo caz{ó} un test de dinero: reabrir el env{í}o reabri{ó} tambi{é}n el
                 * cobro, y a un socio que ya tiene el plan le sali{ó} un enlace
                 * de pago de verdad, acu{ñ}ado, justo en el caso que el bloqueo
                 * de reventa existe para impedir.
                 *
                 * El razonamiento es el mismo que en la rendici{ó}n: el enlace
                 * de pago y los de la app colgaban del texto del modelo, y ese
                 * texto NO sali{ó}. Lo {ú}nico que se ejecuta es lo que pidi{ó} la
                 * PERSONA, no lo que propuso el modelo.
                 */
                $decision['should_generate_payment_link'] = false;

                /*
                 * Sólo se caen las DOS que viajaban dentro del texto.
                 *
                 * El enlace de pago y los de la app se insertan en el mensaje
                 * del modelo, y ese mensaje no salió: anunciarlos sería
                 * mentir. Lo demás sí ocurre, porque no lo pidió el modelo
                 * sino la PERSONA: la marca para el equipo, la baja y la
                 * solicitud de cortesía son efectos del mundo real y que el
                 * texto se repita no los cancela. Quitarlas todas dejaba sin
                 * marcar una operación que sólo hace el equipo.
                 */
                $decision['tools_requested'] = array_values(array_diff(
                    (array) ($decision['tools_requested'] ?? []),
                    [SalesIntents::TOOL_PAYMENT_LINK_SEND, SalesIntents::TOOL_APP_LINKS_SEND],
                ));
            }
        }

        if (($respuestaRepetida || $revende) && ($decision['safe_to_send'] ?? false) !== true) {
            $decision['safe_to_send'] = false;
            $decision['should_send_message'] = false;
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
        // La fecha y la hora de la cortesía, resueltas UNA vez: la invariante y la herramienta ven lo mismo.
        $decision = $this->conCortesiaDelTexto($conversation, $message, $decision);

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

            /*
             * LA CORTESÍA TAMBIÉN DEJA CONSTANCIA, Y AHÍ DECIR «LO DEJÉ
             * REGISTRADO» NO ES UNA PROMESA: ES UN HECHO.
             *
             * Esta invariante nació contra un modelo que prometía que el equipo
             * revisaría algo sin que nadie lo marcara. Pero la frase honesta de
             * la cortesía —«dejé registrada tu solicitud»— engancha las mismas
             * palabras, y sin esto el turno moriría en 422 justo después de
             * haber escrito la fila correctamente: el agente no podría contar
             * lo que acaba de hacer.
             *
             * La condición es la misma que para la marca, no una excepción: que
             * el efecto esté AUTORIZADO y que la herramienta esté en el turno.
             * Aquí la autorización es la propia ejecución, que ya corrió unos
             * pasos antes y escribió la solicitud. Si la cortesía se rechazó
             * —día cerrado, fecha pasada—, `tools_executed` no la trae y la
             * frase vuelve a ser mentira, que es exactamente lo que tiene que
             * pasar.
             */
            $cortesiaEnElTurno = in_array(
                SalesIntents::TOOL_COURTESY_REQUEST,
                (array) ($decision['tools_requested'] ?? []),
                true,
            );

            // Esta comprobación corre ANTES que las herramientas, igual que la
            // de la marca, así que pregunta si el efecto VA A OCURRIR y no si
            // ocurrió: la autoridad es pura y contesta lo mismo aquí que allí.
            /*
             * CANCELAR SÓLO ES UN EFECTO SI HAY ALGO QUE CANCELAR.
             *
             * Esto cortocircuitaba en «cancel» sin preguntar nada más, y ahí
             * se abría la invariante desde dentro: un turno que pide cancelar
             * una solicitud que no existe no toca una sola fila —la
             * herramienta contesta `courtesy_nothing_to_cancel`— y aun así
             * desactivaba la comprobación entera. Con eso, «listo, lo dejo
             * marcado para que el equipo te contacte» salía tal cual sin que
             * nadie marcara nada ni nadie mirara nada: exactamente el fallo
             * que esta invariante nació para impedir.
             *
             * La solicitud abierta se mira AQUÍ, antes de las herramientas,
             * que es cuando todavía está viva: la cancelación la borra del
             * mapa unos pasos después.
             */
            $cancela = ($decision['courtesy_action'] ?? 'request') === 'cancel';

            $cortesiaRegistrada = $cortesiaEnElTurno && (
                $cancela
                    // La solicitud abierta del lead, también la de otra conversación: es la que cancela la herramienta.
                    ? ($this->courtesy->openFor($conversation) ?? ($lead !== null ? $this->courtesy->openForLead($lead) : null)) !== null
                    : CourtesyAuthority::decide(
                        $decision['courtesy_date'] ?? null,
                        $decision['courtesy_time'] ?? null,
                        $this->gym->openingWindows(),
                        null,
                        (string) $message->body,
                    )['ok']
            );

            /*
             * Y RECORDAR UNA VISITA QUE YA ESTÁ SOLICITADA TAMPOCO ES UNA PROMESA.
             *
             * «Tu visita quedó solicitada para el miércoles a las 14:00» en
             * una conversación nueva engancha las mismas palabras que la
             * constancia, y sin esto el turno moría en 422 justo cuando la
             * persona preguntaba por lo que ya pidió. La autoridad aquí es la
             * fila viva del CRM: sólo en modo memoria, sólo si el lead tiene
             * una solicitud pendiente y por llegar, y sólo si el texto habla
             * de esa visita. Fuera de eso, la frase vuelve a ser mentira.
             */
            $recuerdaVisitaViva = ($policy['memory_mode'] ?? false)
                && $lead !== null
                && $this->soloRecuerdaLaVisita($replyFinal, $this->courtesy->commitmentsForLead($lead));

            /*
             * SI LO ÚNICO QUE SE AFIRMA ES UN CAMBIO DE LA VISITA, NO SE TUMBA EL
             * TURNO: SE CUENTA LA VERDAD. «Cambio la visita para mañana a las 7»
             * sin herramienta ejecutada es falso, pero el 422 dejaba a la persona
             * con un texto genérico. Laravel lo reescribe tras las herramientas
             * con el estado real de la fila y, si la hora era ambigua, pregunta
             * ({@see sinConstanciaDeUnaCortesiaQueNoSeRegistro()}). Cualquier otro
             * efecto durable sin autoridad (escalar, marcar) sigue cayendo aquí.
             */
            // Con la hora ambigua, la herramienta no va a registrar nada: cualquier
            // constancia SOBRE LA VISITA («queda registrada tu solicitud de día de
            // cortesía») va por la misma reescritura, que pregunta la hora.
            $horaAmbigua = $cortesiaEnElTurno && ! $cancela && CourtesyAuthority::decide(
                $decision['courtesy_date'] ?? null,
                $decision['courtesy_time'] ?? null,
                $this->gym->openingWindows(),
                null,
                (string) $message->body,
            )['reason'] === CourtesyAuthority::HORA_AMBIGUA;
            $soloLaVisita = $horaAmbigua
                ? $this->promises->efectoDurableEn($this->sinCambiosDeVisita($replyFinal, true)) === null
                : $this->promises->cambioDeVisita($replyFinal) !== null
                    && $this->promises->efectoDurableEn($this->sinCambiosDeVisita($replyFinal)) === null;

            if (! ($marcaAutorizada && $marcaEnElTurno) && ! $cortesiaRegistrada && ! $recuerdaVisitaViva && ! $soloLaVisita) {
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
            'plan_price_realigned' => $realineado,
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
        $executed = $this->runTools($conversation, $message, $decision);

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
        $textoSustituido = false;
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
        /*
         * SOBRE UN TEXTO DE RELLENO NO SE ACUÑA NADA.
         *
         * Apagar `should_generate_payment_link` y quitar la herramienta NO
         * bastaba: {@see checkoutDecision()} corrobora por su cuenta —con la
         * referencia resuelta `send_it` o con una oferta de cobro aceptada— y
         * no lee esa bandera. Lo que impedía acuñar aquí era el corte por
         * `safe_to_send`, que es justo el que se abrió para dejar de callar.
         *
         * Medido en código por la revisión: un socio con plan vigente fuera de
         * ventana que dice «sí, mándame el link» caía en `resell_blocked`,
         * entraba en la recuperación y salía con un checkout ACUÑADO pegado a
         * un texto genérico. El enlace cuelga del mensaje del modelo, y ese
         * mensaje no salió: sin mensaje no hay enlace.
         */
        $recuperado = ($decision['recovered_reply'] ?? false) === true;

        $cobro = $recuperado
            ? null
            : $this->checkoutDecision($conversation, $lead, $decision, $plan, (string) $sanitized['intent'], $resolution, $replyFinal);

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
        // Primero la constancia sin registro y después la confirmación: al revés, la
        // primera retiraba el acta que la segunda acababa de escribir con la fila del CRM.
        $replyFinal = $this->sinConstanciaDeUnaCortesiaQueNoSeRegistro($conversation, $message, $replyFinal, $action, $executed, $decision);
        $replyFinal = $this->sinConfirmarUnaCortesiaQueNadieConfirmo($conversation, $replyFinal, $action);
        // Y lo último de la cortesía: el día y la hora que se dicen son los de la fila guardada.
        $replyFinal = $this->conLaFechaDeLaCita($replyFinal, $action, $executed);

        /*
         * 13.bis) Y LO ÚLTIMO: QUE EL TEXTO CUMPLA LA POLÍTICA DEL TURNO.
         *
         * El Critic juzga calidad; esto comprueba HECHOS: que a quien pidió
         * información se le informe antes de interrogarle, que no se salude
         * dos veces, y que el plan que se nombra primero sea el que el CRM
         * manda. Los tres se midieron incumplidos en una prueba física con el
         * Critic diciendo que estaba bien.
         *
         * Si el borrador no cumple, NO se calla: sale el texto de respaldo de
         * la MISMA intención, que por construcción cumple —cuenta algo y deja
         * una puerta abierta sin nombrar plan— y el incumplimiento queda
         * anotado en la fila. La regeneración con el motivo concreto ya existe
         * aguas arriba, en el Critic y el Composer de reintento; esto es el
         * suelo, no el primer intento.
         */
        $catalogo = $this->knowledge->activePlans();

        /*
         * UNA EXCUSA INVENTADA SOBRE EL PRECIO NO SALE.
         *
         * «La diferencia puede ser por confusión con otro plan o información
         * previa» es especulación: el modelo no sabe por qué la persona vio
         * dos precios, y lo que sabe el CRM es el precio de hoy. Se retira la
         * frase y sale lo demás; si queda claro que la persona pregunta por
         * un precio, el precio actual se afirma abajo desde el catálogo.
         */
        $replyFinal = $this->sinExcusasDePrecio($conversation, $replyFinal, $action, (string) $sanitized['intent']);

        /*
         * UNA CLASE QUE NO EXISTE TAMPOCO SALE.
         *
         * «Entrenadores especializados en musculación, funcional, pilates,
         * yoga y más» salió en una prueba física: ni pilates ni yoga son
         * clases del gimnasio; eran especialidades de la ficha de los
         * entrenadores y el Critic las dio por respaldadas. Lo que ofrecemos
         * lo dice el CRM; la oración que afirme otra cosa se retira.
         */
        $hechos = $this->hechosDelGimnasio();
        $replyFinal = $this->sinServiciosInventados($conversation, $replyFinal, $action, $hechos, $plan);

        $incumple = CommercialTurnPolicy::violations($replyFinal, $policy, $catalogo, $hechos);

        /*
         * Saludar dos veces se ARREGLA, no se castiga.
         *
         * Detrás del saludo de más suele venir justo lo que la persona pidió
         * —la dirección, el horario—, así que tirar el mensaje entero
         * cambiaría un defecto de forma por uno de fondo. Se recorta la
         * fórmula y se vuelve a mirar qué queda incumpliendo.
         */
        if (in_array(CommercialTurnPolicy::VIOLACION_SALUDO_REPETIDO, $incumple, true)) {
            $replyFinal = CommercialTurnPolicy::withoutRepeatedGreeting($replyFinal);
            $incumple = CommercialTurnPolicy::violations($replyFinal, $policy, $catalogo, $hechos);
        }

        /*
         * En modo memoria, el día y la hora que el borrador afirma tienen que
         * ser los del compromiso real. Es justo el dato que este modo existe
         * para dar bien: «a las 4 pm» por un compromiso de las 14:00 manda a
         * alguien a una hora que nadie preparó. Si no casan, sale el respaldo,
         * que está escrito con la fila del CRM.
         */
        if (($policy['memory_mode'] ?? false) && $this->contradiceLosCompromisos($conversation, $replyFinal)) {
            $incumple[] = self::VIOLACION_MEMORIA_DATO;
        }

        if ($incumple !== []) {
            $alternativa = $this->respaldoPorIntencion($conversation, (string) $sanitized['intent'], $policy);
            if (($policy['reception_mode'] ?? false) || ($policy['memory_mode'] ?? false)) {
                // Un saludo o un recuerdo no cotizan nada: la memoria dice lo que salió.
                $plan = null;
            }

            ChannelLog::warning('ultron.commit.policy_violation', [
                'conversation_id' => (int) $conversation->id,
                'violaciones' => $incumple,
                'sustituido' => $alternativa !== null,
            ]);

            $meta = is_array($action->metadata) ? $action->metadata : [];
            $meta['policy'] = $policy;
            $meta['policy_violations'] = $incumple;
            $meta['policy_discarded_draft'] = mb_substr($replyFinal, 0, 400);
            $action->forceFill(['metadata' => $meta])->save();

            /*
             * Si lo que falló fue el PLAN, el respaldo habla de planes.
             *
             * Mandar «cuéntame qué necesitas» a quien acaba de preguntar por
             * planes cambia un plan mal elegido por una respuesta vacía. El
             * texto se arma con el catálogo del CRM, así que sigue al negocio
             * y no a lo que alguien escribiera aquí.
             */
            if (in_array(CommercialTurnPolicy::VIOLACION_PLAN_EQUIVOCADO, $incumple, true)) {
                $dePlanes = CommercialTurnPolicy::planReply($policy, $catalogo);

                /*
                 * El sustituto también se mide. Sonaba absurdo hasta que la
                 * revisión lo demostró: si el plan obligatorio estaba a la vez
                 * en la lista de prohibidos, el respaldo volvía a nombrarlo y
                 * el turno salía incumpliendo lo mismo que venía a arreglar.
                 * Si no cumple, se cae al respaldo genérico, que no nombra
                 * ningún plan y por construcción no puede fallar por esto.
                 */
                if ($dePlanes !== null
                    && CommercialTurnPolicy::violations($dePlanes, $policy, $catalogo, $hechos) === []) {
                    $alternativa = $dePlanes;
                }
            }

            /*
             * Si lo que falló fue la VISITA —se puso a vender con el día y la
             * hora a medias—, el respaldo sigue con la visita: pide el dato
             * que falta y nada más. Sale del estado, no de una plantilla.
             */
            if (in_array(CommercialTurnPolicy::VIOLACION_OBJETIVO_PERDIDO, $incumple, true)) {
                $deVisita = CommercialTurnPolicy::courtesyReply($policy);
                if ($deVisita !== null) {
                    $alternativa = $deVisita;
                    // Este turno no cotiza nada: lo que se anote en memoria
                    // tiene que decir lo mismo que lo que salió.
                    $plan = null;
                }
            }

            /*
             * Si lo que falló fue que pidió información y no recibió ni un
             * hecho, el respaldo se arma CON los hechos del CRM: cumple por
             * construcción, y si mañana cambia la ficha del gimnasio cambia
             * con ella sin tocar código.
             */
            if (in_array(CommercialTurnPolicy::VIOLACION_SIN_HECHO, $incumple, true)) {
                $conHechos = $this->respuestaConHechos();
                if ($conHechos !== null) {
                    $alternativa = $conHechos;
                }
            }

            if ($alternativa !== null) {
                $replyFinal = $alternativa;
                // Lo que colgaba del texto del modelo (los enlaces de la app ya pegados) no salió con él.
                $textoSustituido = true;
            }
        }

        /*
         * 13.ter) RETOMAR LO QUE SE ESTABA HACIENDO.
         *
         * Una pregunta incidental se contesta y NO borra el hilo: si había una
         * visita a medio concretar y el texto ya contestó lo suyo pero no la
         * retoma, se le añade la frase que la retoma, con el dato que falta.
         * Corrige sin amordazar: lo que contestó sale entero.
         */
        if (($policy['resume_goal_after_answer'] ?? false) === true && ! CommercialTurnPolicy::mentionsVisit($replyFinal)) {
            $cola = CommercialTurnPolicy::courtesyResumption($policy);
            if ($cola !== null) {
                ChannelLog::info('ultron.commit.goal_resumed', ['conversation_id' => (int) $conversation->id]);
                $replyFinal = rtrim($replyFinal).' '.$cola;
            }
        }

        /*
         * 13.quater) QUIEN DUDA DE UN PRECIO RECIBE EL PRECIO DE HOY.
         *
         * Del catálogo, con el plan de la política —el que la persona nombró
         * o el que se venía cotizando—. Si el texto ya lo trae, no se repite;
         * si el modelo contestó dando rodeos, se añade la cifra verdadera.
         */
        if (($policy['price_verification'] ?? false) === true && $plan !== null
            && preg_match('/\$\s?\d|\d{2,3}[.,]\d{3}/u', $replyFinal) !== 1) {
            $replyFinal = rtrim($replyFinal).' El precio actual del '.trim((string) $plan->name).' es '.$this->replies->formatCop((float) $plan->price).'.';
        }

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
                if ($textoSustituido || ! self::llevaLosEnlaces($replyFinal)) {
                    // Una guarda cambió o recortó el texto después de pegar los enlaces: no salieron, y
                    // anotarlos haría que durante una hora se le dijera «te los dejé aquí mismo» sin que estén.
                    $enlaces = ['tool' => $enlaces['tool'], 'status' => 'skipped', 'reason' => 'reply_replaced'];
                } elseif ($outcome === 'failed') {
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
            $memoriaFinal = $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, $replyFinal, $plan,
                (string) $sanitized['intent'], $resolution, $send['message_id'] ?? null,
                ($decision['recovered_reply'] ?? false) === true,
            );
            // 15.bis) Y la ficha del lead aprende lo que vale para la próxima conversación.
            $this->leadProfile->absorb($conversation->fresh(), $message, (string) $sanitized['intent'], $memoriaAlEntrar, $memoriaFinal);
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

        /*
         * EL RESPALDO ES SEMÁNTICO, NO GENÉRICO.
         *
         * Con el critic caído, un «hola» recibía el texto curado de saludo y,
         * si ya se había usado en 24 h, el último recurso genérico («dime qué
         * necesitas —planes, horarios…»). Quien saluda recibe una bienvenida;
         * quien pide información, la ficha real. Lo demás sigue por el texto
         * curado de su intención.
         */
        $semantico = $intent === SalesIntents::DO_NOT_CONTACT_REQUEST
            ? null
            : $this->respaldoSemantico($conversation, (string) $message->body, $intent);
        $curado = match (true) {
            $intent === SalesIntents::DO_NOT_CONTACT_REQUEST => null,
            $semantico !== null => $semantico,
            default => $this->replies->replyFor($intent, ['lead' => $lead, 'channel' => $conversation->channel]),
        };

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
        // (La bienvenida elige ella misma la variante no repetida, y si todas
        // se dijeron, repite antes que caer en un menú.)
        if ($curado !== null && $semantico === null && $this->novelty->nearDuplicateOf($curado, $this->previousMachineReplies($conversation)) !== null) {
            ChannelLog::warning('ultron.commit.curated_reply_repeated', ['conversation_id' => $conversation->id, 'intent' => $intent]);
            $curado = null;
        }

        /*
         * Y AQUÍ NO SE ACABA LA BÚSQUEDA. SIN TEXTO, SE BUSCA OTRO.
         *
         * Esto faltaba, y costó tres mensajes seguidos sin respuesta en una
         * prueba real. La cadena era: el critic tumba el borrador → sale el
         * texto curado de esa intención → al turno siguiente el critic vuelve
         * a tumbar → el MISMO texto curado ya está dicho → la guarda
         * antirrepetición lo anula → silencio. Un último recurso que sólo
         * puede dispararse una vez por conversación no es un último recurso.
         *
         * La regla es: un entrante atendible termina en un saliente visible.
         * Las excepciones son gobernadas y se cuentan con los dedos —aquí,
         * quien pidió que no le escriban—, y no incluyen «al agente se le
         * atragantó la redacción». Repetirse es un defecto de estilo;
         * callarse deja a una persona esperando delante del teléfono.
         */
        $mudoPorDiseno = $intent === SalesIntents::DO_NOT_CONTACT_REQUEST
            // La herramienta cuenta tanto como el intent: unas líneas más
            // abajo el opt-out se ejecuta por las dos vías, y mirar sólo una
            // aquí elegía un texto de respaldo para alguien que acaba de pedir
            // que no le escriban. No llegaba a salir —el despachador lo frena—
            // pero el turno moría marcado como fallido en vez de como correcto.
            || in_array(SalesIntents::TOOL_MARK_DNC, (array) ($proposal['tools_requested'] ?? []), true);

        if (! $mudoPorDiseno && ($curado === null || trim($curado) === '')) {
            $curado = $this->ultimoRecurso($conversation, $intent);
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

        $memoriaPrevia = $this->memoryService->load($conversation);
        $resolution = $this->references->resolve(
            (string) $message->body,
            $memoriaPrevia,
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
            $memoriaFinal = $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, (string) $curado, null, $intent, $resolution, $send['message_id'] ?? null,
            );
            // La ficha del lead también aprende de los turnos que salieron por el respaldo.
            // La ficha aprende del texto de la persona y de lo que salió, nunca de la etiqueta de la propuesta que se tumbó.
            $this->leadProfile->absorb($conversation->fresh(), $message, $intent, $memoriaPrevia, $memoriaFinal);
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

    /**
     * ¿Este turno es la HORA DE LA VISITA, y la decide Laravel? Dos casos:
     *
     *  - `preguntar`: hay visita viva o a medio concretar y la persona propone
     *    una hora con dos lecturas dentro del horario de ese día («mejor
     *    mañana a las 7»). No se ejecuta nada: se pregunta cuál.
     *  - `resolver`: Laravel ya preguntó (queda `pending_hour` en el objetivo)
     *    y la persona elige, sin dudas, una de esas lecturas («7 p. m.»).
     *
     * Null —y el turno sigue su camino— si la persona trae otra cosa (precio,
     * planes, horario, ubicación), rechaza o cancela la visita, o si el modelo
     * propone que no le escriban, una revisión del equipo o una persona: esos
     * caminos tienen su propia autoridad y no se secuestran.
     *
     * @param  array<string,mixed>  $proposal
     * @return array{modo:string,fecha:string,opciones?:array<int,string>,hora?:string}|null
     */
    private function horaDeLaVisita(MarketingConversation $conversation, MarketingMessage $message, array $proposal): ?array
    {
        $lead = $conversation->lead;
        $texto = (string) $message->body;
        $intent = (string) ($proposal['intent'] ?? '');
        if ($lead === null
            || $intent === SalesIntents::DO_NOT_CONTACT_REQUEST
            || StaffReviewAuthority::motivoDeIntencion($intent) !== null
            || array_intersect((array) ($proposal['tools_requested'] ?? []), [SalesIntents::TOOL_MARK_DNC, SalesIntents::TOOL_STAFF_REVIEW]) !== []
            || ($proposal['next_state'] ?? null) === CommercialPhaseMachine::HUMAN_HANDOFF
            || (bool) ($proposal['human_handoff_requested'] ?? false)
            || ($proposal['courtesy_action'] ?? null) === 'cancel'
            || CommercialTurnPolicy::declinesVisit($texto)
            || CommercialTurnPolicy::traeOtroTema($texto, $this->knowledge->activePlans())) {
            return null;
        }

        $objetivo = $this->memoryService->load($conversation)->activeGoal();
        $objetivo = ($objetivo['kind'] ?? null) === ConversationMemoryService::GOAL_COURTESY ? $objetivo : null;
        $viva = $this->courtesy->visitaVivaDe($lead, $conversation);
        if ($objetivo === null && $viva === null) {
            return null;
        }

        $ventanas = $this->gym->openingWindows();
        $fechaDelTexto = CourtesyAuthority::fechaDesdeTexto($texto);
        // Un día nombrado que no se lee sin dudas no es una hora por aclarar.
        if ($fechaDelTexto === null && CourtesyAuthority::mencionesEn($texto)['fechas'] !== []) {
            return null;
        }

        $pendiente = (array) ($objetivo['data']['pending_hour'] ?? []);
        $vigente = isset($pendiente['date'], $pendiente['at']) && is_array($pendiente['options'] ?? null)
            && rescue(fn () => Carbon::parse((string) $pendiente['at'])->diffInHours(now()) < ConversationMemoryService::GOAL_COLLECTING_HOURS, false, false);
        if ($vigente && ($fechaDelTexto === null || $fechaDelTexto === $pendiente['date']) && CourtesyAuthority::textoSinDudas($texto)) {
            $elegida = CourtesyAuthority::lecturasPropuestas($texto, (string) $pendiente['date'], $ventanas);
            if ($elegida !== null && count($elegida) === 1 && in_array($elegida[0], $pendiente['options'], true)) {
                return ['modo' => 'resolver', 'fecha' => (string) $pendiente['date'], 'hora' => $elegida[0]];
            }
        }

        // El día de la pregunta: el que nombra, el de la aclaración en curso, el que se recogía o el de su visita.
        $fecha = $fechaDelTexto
            ?? ($vigente ? (string) $pendiente['date'] : null)
            ?? (($objetivo['status'] ?? null) === ConversationMemoryService::GOAL_COLLECTING ? ($objetivo['data']['date'] ?? null) : null)
            ?? $viva?->scheduled_at?->copy()->setTimezone(BusinessClock::TZ)->toDateString()
            ?? ($objetivo['data']['date'] ?? null);
        if ($fecha === null) {
            return null;
        }
        $lecturas = CourtesyAuthority::lecturasPropuestas($texto, (string) $fecha, $ventanas);

        return $lecturas !== null && count($lecturas) >= 2
            ? ['modo' => 'preguntar', 'fecha' => (string) $fecha, 'opciones' => $lecturas]
            : null;
    }

    /**
     * El turno de la hora de la visita, contestado por Laravel. La etiqueta y
     * el borrador del modelo no se usan: quedan en la fila como evidencia.
     *
     * Al preguntar no se ejecuta nada; se dice cómo está la visita y se
     * pregunta, y la aclaración queda pendiente sin tocar el objetivo. Al
     * resolver corre la MISMA herramienta que pediría el modelo —con su pausa
     * y su autoridad— DESPUÉS de dejar la fila, como en el camino normal, y lo
     * que se dice es el acta de la cita tal y como quedó.
     *
     * @param  array{modo:string,fecha:string,opciones?:array<int,string>,hora?:string}  $turno
     * @return array<string,mixed>
     */
    private function contestarLaHoraDeLaVisita(
        MarketingConversation $conversation,
        MarketingMessage $message,
        array $payload,
        string $currentPhase,
        array $turno,
    ): array {
        $proposal = (array) ($payload['proposal'] ?? []);
        $lead = $conversation->lead;
        $resolver = $turno['modo'] === 'resolver';
        // La etiqueta más cercana del contrato: la que pone el decide de Laravel a estos mensajes.
        $intent = SalesIntents::SCHEDULE_QUESTION;
        $decision = [
            'intent' => $intent,
            'confidence' => 1.0,
            'recommended_action' => SalesIntents::ACTION_REPLY,
            'risk_flags' => [],
            'tools_requested' => $resolver ? [SalesIntents::TOOL_COURTESY_REQUEST] : [],
            'needs_staff_review' => false,
            'courtesy_action' => 'request',
            'courtesy_date' => $turno['fecha'],
            'courtesy_time' => $turno['hora'] ?? null,
        ];
        $memoriaPrevia = $this->memoryService->load($conversation);

        $action = $this->persist($conversation, $message, $payload, $decision, $currentPhase, null, array_filter([
            'visit_hour' => array_filter([
                'mode' => $resolver ? 'resolve' : 'ask',
                'date' => $turno['fecha'],
                'options' => $turno['opciones'] ?? null,
                'chosen' => $turno['hora'] ?? null,
            ], fn ($v) => $v !== null),
            'model_intent' => $proposal['intent'] ?? null,
            'model_tools_requested' => ((array) ($proposal['tools_requested'] ?? [])) ?: null,
            'discarded_draft' => mb_substr((string) ($proposal['reply_draft'] ?? ''), 0, 500) ?: null,
        ], fn ($v) => $v !== null));

        $ejecutadas = [];
        if ($resolver) {
            $r = $this->execCourtesyRequest($conversation, $message, $decision);
            $ejecutadas[] = $r;
            $this->annotateExecuted($action, $r);
            $meta = is_array($action->metadata) ? $action->metadata : [];
            $meta['visit_hour']['result'] = array_filter([
                'status' => $r['status'] ?? null,
                'courtesy' => $r['courtesy'] ?? null,
                'reason' => $r['reason'] ?? null,
                'appointment_id' => $r['appointment_id'] ?? null,
            ], fn ($v) => $v !== null);
            $action->forceFill(['metadata' => $meta])->save();

            $cita = ($r['status'] ?? null) === 'executed' ? MarketingAppointment::find((int) ($r['appointment_id'] ?? 0)) : null;
            $texto = $cita !== null ? $this->courtesy->actaDeLaFila($cita) : null;
            if ($texto === null) {
                $estado = $this->courtesy->estadoDe($lead, $conversation);
                $texto = ($estado !== null ? rtrim($estado, '.').'. ' : '').'Todavía no cambié nada de tu visita. ¿Qué día y a qué hora te queda bien?';
            }
        } else {
            $this->memoryService->recordCourtesyHourQuestion($conversation->fresh(), $turno['fecha'], (array) $turno['opciones']);
            $estado = $this->courtesy->estadoDe($lead, $conversation);
            $pregunta = CourtesyRequestService::preguntaDeHora((array) $turno['opciones'], $estado === null);
            $texto = $estado === null ? $pregunta : rtrim($estado, '.').'. '.$pregunta;
        }

        ChannelLog::info('ultron.courtesy.visit_hour_turn', [
            'conversation_id' => (int) $conversation->id,
            'ai_action_id' => (int) $action->id,
            'mode' => $resolver ? 'resolve' : 'ask',
            'model_intent' => $proposal['intent'] ?? null,
        ]);

        $resolution = $this->references->resolve((string) $message->body, $memoriaPrevia, $this->memoryService->sellablePlansForMemory());
        $send = $this->dispatcher->dispatchWhatsapp(
            $lead->fresh(),
            $conversation->channel,
            $texto,
            ['kind' => 'reply', 'origin' => 'ultron', 'ai_action_id' => $action->id],
            MarketingMessage::SENDER_AI,
            conversation: $conversation,
        );
        $outcome = $send['sent'] ? 'sent' : ($send['dry_run'] ? 'dry_run' : 'failed');
        $this->finalise($action, $outcome, $send);

        if ($outcome !== 'failed') {
            $memoriaFinal = $this->memoryService->recordSentTurn(
                $conversation->fresh(), $message, $texto, null, $intent, $resolution, $send['message_id'] ?? null,
            );
            $this->leadProfile->absorb($conversation->fresh(), $message, $intent, $memoriaPrevia, $memoriaFinal);
        }

        return [
            'ok' => true,
            'ai_action_id' => (int) $action->id,
            'outcome' => $outcome,
            'message_id' => $send['message_id'],
            'provider_message_id' => $send['provider_message_id'],
            'commercial_phase' => ['from' => $currentPhase, 'to' => $currentPhase],
            'reply_final' => $texto,
            'applied' => [
                'tools_executed' => $this->executedTools($ejecutadas),
                'tools_rejected' => array_values(array_diff((array) ($proposal['tools_requested'] ?? []), $this->executedTools($ejecutadas))),
            ],
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
    private function runTools(MarketingConversation $conversation, MarketingMessage $message, array $decision): array
    {
        $executed = [];

        foreach ((array) ($decision['tools_requested'] ?? []) as $tool) {
            $executed[] = match ($tool) {
                SalesIntents::TOOL_STAFF_REVIEW => $this->execStaffReview($conversation, $decision),
                SalesIntents::TOOL_MARK_DNC => $this->execMarkDnc($conversation),
                SalesIntents::TOOL_COURTESY_REQUEST => $this->execCourtesyRequest($conversation, $message, $decision),
                // El link de pago corre DESPUÉS de enviar la respuesta (execPaymentLink).
                SalesIntents::TOOL_PAYMENT_LINK_SEND => null,
                // Inalcanzable: la lista ya se filtró contra el menú del turno.
                default => ['tool' => $tool, 'status' => 'skipped', 'reason' => 'not_available'],
            };
        }

        return array_values(array_filter($executed));
    }

    /**
     * DEJA ANOTADA LA CORTESÍA. NO LA AGENDA.
     *
     * El modelo trae el día y la hora que dijo la persona; quien decide si eso
     * se puede registrar es {@see CourtesyAuthority}, contra el horario
     * aprobado del gimnasio. Aquí no se interpreta nada: si la autoridad dice
     * que no, el turno sigue y la respuesta lo cuenta —«ese día cerramos a las
     * dos»—, que es más útil que un registro imposible.
     *
     * Y el efecto va ANTES de que el texto lo afirme, no después. Es la misma
     * regla que el cobro: primero el hecho, luego la frase. Si esto falla, la
     * invariante de promesas de más abajo impide que salga un «lo dejé
     * registrado» que sería falso.
     *
     * @return array<string,mixed>
     */
    private function execCourtesyRequest(MarketingConversation $conversation, MarketingMessage $message, array $decision): array
    {
        $tool = SalesIntents::TOOL_COURTESY_REQUEST;
        $accion = (string) ($decision['courtesy_action'] ?? 'request');

        /*
         * La pausa del agente, otra vez y justo antes de escribir en la agenda.
         * Las cabeceras del commit ya la miraron, pero una pausa que llegue
         * entre ellas y esta herramienta dejaría una cita automática con el
         * agente pausado. Es la misma ventana que cierra el despachador para
         * los envíos: sin efecto automático, la conversación queda para una
         * persona, que sí puede operar la agenda a mano.
         */
        if (app(MarketingAgentSwitch::class)->pausedSince($message->created_at)) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => MarketingAgentSwitch::REASON];
        }

        if ($accion === 'cancel') {
            $r = $this->courtesy->cancel($conversation);

            if ($r !== null) {
                $this->memoryService->recordCourtesyCancelled($conversation->fresh());
            }

            return $r === null
                ? ['tool' => $tool, 'status' => 'skipped', 'reason' => 'courtesy_nothing_to_cancel']
                : ['tool' => $tool, 'status' => 'executed', 'courtesy' => 'cancelled', 'appointment_id' => $r['appointment_id']];
        }

        $lead = $conversation->lead;
        if ($lead === null) {
            return ['tool' => $tool, 'status' => 'skipped', 'reason' => 'no_lead'];
        }

        // Idempotente: el commit ya lo resolvió antes de la invariante; aquí sólo por si llega directo.
        $decision = $this->conCortesiaDelTexto($conversation, $message, $decision);

        $veredicto = CourtesyAuthority::decide(
            $decision['courtesy_date'] ?? null,
            $decision['courtesy_time'] ?? null,
            $this->gym->openingWindows(),
            null,
            (string) $message->body,
        );

        if (! $veredicto['ok']) {
            ChannelLog::info('ultron.courtesy.rejected', [
                'conversation_id' => (int) $conversation->id,
                'reason' => $veredicto['reason'],
                'fecha' => $decision['courtesy_date'] ?? null,
                'hora' => $decision['courtesy_time'] ?? null,
            ]);

            /*
             * RECHAZADA NO ES OLVIDADA. Pedir la visita sin hora es lo normal
             * —se pregunta de uno en uno— y la autoridad la rechaza bien; lo
             * que no puede pasar es que del rechazo no quede nada: así el
             * turno siguiente se puso a vender con la visita a medias. Queda
             * el objetivo en recogida, con el dato que SÍ valía para no
             * volver a pedirlo.
             */
            $fechaVale = in_array($veredicto['reason'], [
                CourtesyAuthority::HORA_INVALIDA, CourtesyAuthority::FUERA_DE_HORARIO, CourtesyAuthority::SIN_HORARIO, CourtesyAuthority::HORA_AMBIGUA,
            ], true);
            $horaVale = in_array($veredicto['reason'], [
                CourtesyAuthority::FECHA_INVALIDA, CourtesyAuthority::FECHA_PASADA, CourtesyAuthority::FECHA_LEJANA, CourtesyAuthority::DIA_CERRADO,
            ], true) && CourtesyAuthority::minutosDe($decision['courtesy_time'] ?? null) !== null;
            // La hora ambigua deja la aclaración pendiente y no degrada una visita ya solicitada.
            if ($veredicto['reason'] === CourtesyAuthority::HORA_AMBIGUA && ! empty($veredicto['options'])) {
                $this->memoryService->recordCourtesyHourQuestion($conversation->fresh(), (string) $decision['courtesy_date'], $veredicto['options']);
            } else {
                $this->memoryService->recordCourtesyCollecting($conversation->fresh(), [
                    'date' => $fechaVale ? ($decision['courtesy_date'] ?? null) : null,
                    'time' => $horaVale ? ($decision['courtesy_time'] ?? null) : null,
                ]);
            }

            return array_filter([
                'tool' => $tool,
                'status' => 'skipped',
                'reason' => $veredicto['reason'],
                'closes_at' => $veredicto['closes_at'],
                'weekday' => $veredicto['weekday'],
                'options' => $veredicto['options'] ?? null,
            ], fn ($v) => $v !== null);
        }

        $r = $this->courtesy->request(
            $lead,
            $conversation,
            $message,
            (string) $veredicto['scheduled_at'],
            $decision['courtesy_date'] ?? null,
            $decision['courtesy_time'] ?? null,
        );

        // La misma hora de una visita YA confirmada no cambió nada: ni la memoria
        // ni la ficha pueden contarla como una solicitud pendiente.
        if (! $r['changed'] && in_array($r['status'], MarketingAppointment::CONFIRMED_STATUSES, true)) {
            return ['tool' => $tool, 'status' => 'executed', 'courtesy' => 'unchanged', 'appointment_id' => $r['appointment_id'], 'scheduled_at' => $r['scheduled_at']];
        }

        $this->memoryService->recordCourtesyRequest($conversation->fresh(), [
            'status' => 'requested',
            'appointment_id' => $r['appointment_id'],
            'scheduled_at' => $r['scheduled_at'],
            'date' => $decision['courtesy_date'] ?? null,
            'time' => $decision['courtesy_time'] ?? null,
            'at' => now()->toIso8601String(),
        ]);

        return [
            'tool' => $tool,
            'status' => 'executed',
            'courtesy' => ! $r['changed'] ? 'unchanged' : ($r['nueva'] ? 'requested' : 'updated'),
            'appointment_id' => $r['appointment_id'],
            'scheduled_at' => $r['scheduled_at'],
        ];
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

        /*
         * DECIR QUE SÍ AL COBRO ES QUERER PAGAR. DECIR QUE SÍ A OTRA COSA, NO.
         *
         * Lo que decide no es el «sí», es QUÉ se ofreció. Y hay que mirarlo en
         * la memoria, no en la resolución: cuando hay un plan pendiente, el
         * resolver llama `choose_plan` tanto al «sí» de «¿te paso el link de
         * pago?» como al «me interesa» de «¿te lo dejo listo?», y por el camino
         * pierde el dato que los distingue.
         *
         * Con la oferta a la vista, la distinción es limpia: sólo cuenta si lo
         * ofrecido fue el enlace de pago. «¿Te explico cómo empezar?» seguido
         * de «dale» no es aceptar un cobro, y tampoco lo es «me interesa»
         * después de presentar un plan.
         */
        $tipo = $resolution['type'] ?? null;
        $ofrecido = $resolution['offer_kind'] ?? data_get($conversation->memory, 'last_agent_offer.kind');

        $aceptoElCobro = in_array($tipo, [ReferenceResolver::ACCEPT_OFFER, ReferenceResolver::CHOOSE_PLAN], true)
            && $ofrecido === self::OFERTA_DE_COBRO;

        $corroborado = in_array($resolution['type'] ?? null, self::RESOLUCIONES_QUE_PAGAN, true)
            || $aceptoElCobro
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
     * NADIE SE PRESENTA UN DÍA QUE NADIE PREPARÓ.
     *
     * El día de cortesía lo confirma una persona del equipo. Mientras la
     * solicitud sólo esté anotada, decir «quedaste agendado» o «te esperamos
     * el sábado» manda a alguien a hacer un viaje a un gimnasio que no lo
     * espera, y eso no lo arregla ninguna disculpa: el viaje ya está hecho.
     *
     * No lo cubría nada: se midió que «quedaste agendado», «tu cortesía quedó
     * confirmada» y «ya te reservé el cupo» pasaban limpias por la invariante
     * de promesas, que sólo cazaba «te agendo» en primera persona.
     *
     * Y si el equipo YA confirmó —existe la cita de verdad— la frase es
     * cierta y sale sin tocarla. Lo que se corrige es afirmar lo que todavía
     * no ha pasado, no hablar de citas.
     */
    private function sinConfirmarUnaCortesiaQueNadieConfirmo(
        MarketingConversation $conversation,
        string $respuesta,
        MarketingAiAction $action,
    ): string {
        /*
         * Una cita real y TODAVÍA POR VENIR hace verdadera la frase.
         *
         * Antes valía cualquier cita, de cualquier fecha y en cualquier
         * estado. Y una conversación de WhatsApp no se cierra: vive ligada al
         * par lead+canal durante meses. Así que a un socio con una visita
         * `completed` en junio se le podía decir en septiembre «quedaste
         * agendado para el sábado a las 10», intacto, porque aquella visita de
         * hace tres meses abría la puerta. Esa persona viaja un día que nadie
         * preparó, y el argumento de la guarda —que el viaje ya está hecho
         * cuando llega la disculpa— vale igual aquí.
         *
         * `completed` se cae por definición: una cita cumplida está en el
         * pasado y no puede hacer verdadera ninguna frase en futuro.
         */
        /*
         * La cita es del LEAD, no de la conversación —quien vuelve en una
         * conversación nueva tiene la misma visita—, pero sólo cuenta una
         * VISITA confirmada: una llamada o una valoración agendada por el
         * equipo no hace verdadera una frase sobre la visita. Y la exime sólo
         * si no hay OTRA solicitud del lead pendiente (entonces la frase habla
         * de ésa) y si la frase NOMBRA el día de la cita —y su hora, si nombra
         * alguna—: con una cita el jueves, «quedaste agendado para el sábado»
         * sigue siendo mentira, y un «quedaste agendado» sin día, en otra
         * conversación, no se sabe de qué visita habla. Si se retira, lo que
         * la persona lee es la cita real, escrita por Laravel.
         */
        $lead = $conversation->lead;
        $citas = $lead !== null ? $this->courtesy->confirmedVisitsFor($lead) : collect();
        $visitas = $citas->map(function (MarketingAppointment $cita): array {
            $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);

            return ['date' => $d->toDateString(), 'time' => $d->format('H:i'), 'at' => $d];
        })->all();
        $abiertaDelLead = $lead !== null ? $this->courtesy->openForLead($lead) : null;
        $laVisitaLaConfirma = function (string $frase) use ($visitas, $abiertaDelLead): bool {
            if ($visitas === [] || $abiertaDelLead !== null) {
                return false;
            }
            $menciones = CourtesyAuthority::mencionesEn($frase);

            return $menciones['fechas'] !== [] && $this->casaConAlguno($menciones, $visitas);
        };

        /*
         * LA DEFENSA PRINCIPAL ES EL ESTADO, NO LA FECHA.
         *
         * Perseguir con una regex todas las formas de escribir un día —«el
         * 26», «el 26 de septiembre», «el 26/09», «este 26», «a la 1»— es una
         * carrera que no se gana: cada forma nueva es un agujero, y
         * ensancharla a ciegas se midió que retira frases honestas («el 1 a 1
         * con el entrenador», «el 80% de los socios», «el 5 de la carrera 5»).
         *
         * Si esta conversación TIENE una solicitud de cortesía, el CRM ya sabe
         * la verdad sin adivinar nada: no está confirmada. Entonces cualquier
         * «te esperamos» habla de esa visita y no puede salir, venga la fecha
         * escrita como venga o sin fecha ninguna. La regex con ancla temporal
         * se queda como defensa SECUNDARIA para las conversaciones donde no
         * hay ninguna solicitud de por medio, que son las que todavía pueden
         * despedirse con un «te esperamos en la sede» sin mentir.
         */
        // El estado se mira en el LEAD: una solicitud sin confirmar en otra conversación también es de esta persona.
        $solicitud = $this->courtesy->latestFor($conversation) ?? $abiertaDelLead;
        // Una cortesía de antes de la agenda (en el panel viejo) también cuenta.
        $porEstado = $solicitud !== null || $this->courtesy->hadLegacyCourtesy($conversation);

        $detecta = fn (string $frase): ?string => $porEstado
            ? $this->contentGuard->courtesyClaimIn($frase)
            // Sin ninguna solicitud, «tu visita quedó registrada» también es falso.
            : ($this->contentGuard->courtesyConfirmationIn($frase) ?? $this->contentGuard->courtesyRegistrationIn($frase));
        /*
         * Si TODAS las fechas y horas de la respuesta son las de la visita
         * confirmada, la frase sin fecha («Te esperamos.», «¡Nos vemos!») habla
         * de esa visita y es verdad. Retirarla pieza a pieza dejaba la
         * plantilla de confirmada sin su cierre y con el acta repetida detrás.
         */
        $todaLaRespuesta = $laVisitaLaConfirma($respuesta);
        $sinFechaNiHora = function (string $frase): bool {
            $m = CourtesyAuthority::mencionesEn($frase);

            return $m['fechas'] === [] && $m['horas'] === [];
        };
        $afirma = fn (string $frase): ?string => ($cita = $detecta($frase)) !== null
            && ! $laVisitaLaConfirma($frase)
            && ! ($todaLaRespuesta && $sinFechaNiHora($frase)) ? $cita : null;

        if ($detecta($respuesta) === null) {
            return $respuesta;
        }

        /*
         * SE CORTA TAMBIÉN POR SALTO DE LÍNEA, Y EL SEPARADOR SE CONSERVA.
         *
         * El bloque del cobro se pega con `\n\n` y no lleva punto final, así
         * que cortando sólo por `.!?` un borrador sin puntuación era UNA sola
         * pieza: la frase y el enlace viajaban juntos y se retiraban juntos.
         * Ese enlace ya está acuñado y no se puede anular desde aquí, así que
         * tragárselo deja a la persona con un cobro vivo que nunca vio.
         *
         * Cortar también por `\n` separa las dos cosas y permite retirar la
         * frase SIN tocar el enlace, que es mejor que elegir entre una de las
         * dos. Los separadores se capturan y se vuelven a poner, para que el
         * mensaje conserve sus saltos de línea tal cual.
         */
        // Sin partir «a. m.» ni «p. m.»: el corte en «p.» mutilaba la frase verdadera («m. Trae ropa cómoda») y la leía de la mañana.
        $trozos = preg_split('/((?<=[.!?])(?<!\b[aApP]\.)\s+|\n+)/u', trim($respuesta), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        // Cada pieza con el separador que la sigue, para poder rehacer el
        // mensaje con sus saltos de línea intactos.
        $piezas = [];
        for ($i = 0; $i < count($trozos); $i += 2) {
            if (trim($trozos[$i]) === '') {
                continue;
            }
            $piezas[] = [$trozos[$i], $trozos[$i + 1] ?? ''];
        }

        /*
         * SÓLO SE PERDONA LA PIEZA QUE **ES** UN ENLACE.
         *
         * Perdonar cualquier pieza que CONTENGA un enlace convertía la
         * presencia de una URL en un interruptor para apagar la guarda: los
         * enlaces de la app se insertan donde el modelo los quiso, o sea en
         * medio de su prosa, así que «quedaste agendado para el sábado, y aquí
         * está tu link https://…» salía entero. El bloque del cobro no
         * necesitaba ese permiso: va aislado con `\n\n` y el corte por salto
         * de línea ya lo deja en su propia pieza.
         */
        $frases = array_column($piezas, 0);
        $limpias = array_values(array_filter(
            $piezas,
            fn (array $p) => $afirma($p[0]) === null || preg_match('/^\s*https?:\/\/\S+\s*$/u', $p[0]) === 1,
        ));

        // Todo lo que afirmaba hablaba de la visita confirmada, con su día: es verdad y sale tal cual.
        if (count($limpias) === count($piezas)) {
            return $respuesta;
        }

        // El separador va ENTRE piezas que sobreviven: ponerlo antes de saber
        // si la siguiente se queda dejaba dobles espacios donde se retiró algo.
        $salida = '';
        foreach ($limpias as $i => [$texto, $sep]) {
            $salida .= $texto;
            if ($i < count($limpias) - 1) {
                $salida .= $sep !== '' ? $sep : ' ';
            }
        }

        $limpio = trim($salida);

        ChannelLog::error('ultron.courtesy.claimed_confirmed', [
            'conversation_id' => (int) $conversation->id,
            'modo' => $porEstado ? 'estado' : 'texto',
            'estado_solicitud' => $solicitud?->status ?? ($porEstado ? 'heredada' : null),
            'frases_retiradas' => count($frases) - count($limpias),
            'quedo_vacio' => $limpio === '',
        ]);

        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['courtesy_confirmation_dropped'] = [
            'code' => OutboundContentGuard::CODE_CLAIMS_CONFIRMED_COURTESY,
            'frases_retiradas' => count($frases) - count($limpias),
        ];
        $action->forceFill(['metadata' => $meta])->save();

        /*
         * Y si no queda nada, el respaldo tiene que ser verdad TAMBIÉN.
         *
         * Había uno solo, y afirmaba «dejé registrada tu solicitud de día de
         * cortesía». En un turno donde nunca hubo cortesía —un «te esperamos
         * el sábado» a quien va a pagar— la guarda contra afirmar una cortesía
         * falsa acababa afirmando una cortesía falsa. Se elige según lo que de
         * verdad hay escrito en la conversación.
         */
        /*
         * Y la afirmación de estado la pone LARAVEL, con la fecha y la hora
         * que están en la fila. El modelo puede seguir escribiendo alrededor
         * —y lo que escriba sale—, pero lo que la persona lee sobre en qué
         * punto está su visita no depende de que el modelo lo redacte bien.
         */
        $abierta = $this->courtesy->openFor($conversation) ?? $abiertaDelLead;
        $acta = $this->courtesy->actaDe($abierta);
        if ($acta === null && $abierta === null && $visitas !== [] && ! $laVisitaLaConfirma($limpio)) {
            // Sin solicitud pendiente y con la visita confirmada: el acta es la cita real,
            // salvo que lo que queda ya la nombre (repetirla detrás no informa de nada).
            $acta = 'El equipo ya confirmó tu visita: '.$this->courtesy->humanDe($visitas[0]['at']).'.';
        }

        if ($acta !== null) {
            return $limpio !== '' ? $limpio.' '.$acta : $acta;
        }

        if ($limpio !== '') {
            return $limpio;
        }

        return $abierta !== null
            ? self::CORTESIA_SIN_CONFIRMAR
            : self::NADA_QUE_CONFIRMAR;
    }

    /**
     * Un turno que NO pidió la cortesía (o pidió cancelar y no había nada) y aun
     * así cuenta un cambio de la visita: «cambio la visita», «te la cancelo»,
     * «la moví al jueves». Se retiran esas frases; sale el estado real de la
     * fila y la pregunta que toque: la de la hora, si la que nombra la persona
     * es ambigua, o la de confirmar, si nombra un día y una hora sin dudas.
     * Lo que se pregunta queda en recogida para el turno siguiente.
     *
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>|null  $cortesia
     */
    private function sinCambioDeVisitaQueNoSeHizo(
        MarketingConversation $conversation,
        MarketingMessage $message,
        string $respuesta,
        MarketingAiAction $action,
        array $decision,
        ?array $cortesia,
    ): string {
        $piezas = self::oracionesProtegidas($respuesta);
        $quedan = array_values(array_filter($piezas, fn (string $p): bool => $this->promises->cambioDeVisita($p) === null));
        if (count($quedan) === count($piezas)) {
            return $respuesta;
        }
        $cancelaba = collect($piezas)->contains(fn (string $p): bool => preg_match('/\b(cancel|anul|elimin|borr)/u', (string) $this->promises->cambioDeVisita($p)) === 1);

        $lead = $conversation->lead;
        $estado = $lead !== null ? $this->courtesy->estadoDe($lead, $conversation) : null;
        $texto = (string) $message->body;
        $menciones = CourtesyAuthority::mencionesEn($texto);
        $ventanas = $this->gym->openingWindows();
        // El día de la pregunta: el que nombra la persona, el que propuso el modelo o el de su visita.
        $fecha = ($menciones['fechas'][0] ?? null) ?? ($decision['courtesy_date'] ?? null)
            ?? ($lead !== null ? optional($this->courtesy->openForLead($lead))->scheduled_at?->copy()->setTimezone(BusinessClock::TZ)->toDateString() : null);
        $pregunta = null;
        if ($menciones['horas'] !== [] && $fecha !== null) {
            $lecturas = CourtesyAuthority::lecturasAmbiguas($texto, $fecha, null, $ventanas);
            if ($lecturas !== null) {
                $pregunta = CourtesyRequestService::preguntaDeHora($lecturas, $estado === null);
                $this->memoryService->recordCourtesyHourQuestion($conversation->fresh(), (string) $fecha, $lecturas);
            } elseif (count($menciones['horas'][0]) >= 1) {
                $hora = collect($menciones['horas'][0])->first(fn (string $l) => CourtesyAuthority::decide($fecha, $l, $ventanas)['ok']);
                if ($hora !== null) {
                    $d = Carbon::parse($fecha.' '.$hora, BusinessClock::TZ);
                    $pregunta = '¿Quieres que la deje para el '.CourtesyRequestService::fechaDicha($d).' a las '.CourtesyRequestService::horaDicha($d).'?';
                    $this->memoryService->recordCourtesyCollecting($conversation->fresh(), ['date' => $fecha, 'time' => $hora]);
                }
            }
        }

        ChannelLog::warning('ultron.courtesy.unexecuted_change_dropped', [
            'conversation_id' => (int) $conversation->id,
            'frases_retiradas' => count($piezas) - count($quedan),
            'motivo' => $cortesia['reason'] ?? 'no_tool',
        ]);
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['courtesy_unexecuted_change_dropped'] = count($piezas) - count($quedan);
        $action->forceFill(['metadata' => $meta])->save();

        $partes = array_values(array_filter([
            trim(implode(' ', $quedan)),
            $estado !== null ? rtrim($estado, '.').'.' : null,
            $pregunta,
        ], fn ($p) => $p !== null && $p !== ''));
        $salida = trim(implode(' ', $partes));

        if ($salida !== '') {
            return $salida;
        }

        return ($decision['courtesy_action'] ?? null) === 'cancel' || $cancelaba
            ? 'No encontré ninguna visita pendiente a tu nombre para cancelar.'
            : 'Todavía no cambié nada de tu visita. ¿Qué día y a qué hora te queda bien?';
    }

    /**
     * El texto sin las oraciones que cuentan un cambio de la visita y, si se
     * pide, tampoco las que dan constancia de ella («queda registrada tu
     * solicitud de día de cortesía»).
     */
    private function sinCambiosDeVisita(string $texto, bool $tambienConstancias = false): string
    {
        return trim(implode(' ', array_filter(
            self::oracionesProtegidas($texto),
            fn (string $p): bool => $this->promises->cambioDeVisita($p) === null
                && ! ($tambienConstancias
                    && $this->promises->efectoDurableEn($p) !== null
                    && preg_match('/\b(visita|cita|cortesia)\b/u', SalesAgentDecisionSchema::normalize($p)) === 1),
        )));
    }

    /**
     * LA FECHA Y LA HORA QUE SE DICEN SON LAS DE LA FILA.
     *
     * El borrador lo escribe el modelo ANTES de que la herramienta se ejecute,
     * así que puede nombrar un día o una hora que no son los que quedaron
     * guardados. Medido en el canario el 2026-10-04: «te registro la visita de
     * cortesía para mañana a las 6:00 a. m.» con la cita guardada a las 18:00.
     *
     * Ejecutada la cortesía (solicitarla, moverla, repetirla o cancelarla),
     * cada frase que nombra un día o una hora que no casa con la cita REAL se
     * retira, y en su lugar va el acta que Laravel escribe desde la fila. Un
     * «a las 6» suelto casa con las 18:00 (es una de sus lecturas); «6:00 a. m.»
     * no. Las frases del horario del gimnasio no hablan de la visita y no se
     * tocan. Lo demás del mensaje sale como lo escribió el modelo.
     *
     * @param  array<int,array<string,mixed>>  $ejecutadas
     */
    private function conLaFechaDeLaCita(string $respuesta, MarketingAiAction $action, array $ejecutadas): string
    {
        $hecho = collect($ejecutadas)->first(fn (array $r): bool => ($r['tool'] ?? null) === SalesIntents::TOOL_COURTESY_REQUEST
            && ($r['status'] ?? null) === 'executed'
            && ! empty($r['appointment_id']));
        $cita = $hecho !== null ? MarketingAppointment::find((int) $hecho['appointment_id']) : null;
        if ($cita === null || $cita->scheduled_at === null) {
            return $respuesta;
        }
        $d = $cita->scheduled_at->copy()->setTimezone(BusinessClock::TZ);
        $laFila = [['date' => $d->toDateString(), 'time' => $d->format('H:i')]];
        $delHorario = '/\b(abrimos|abre|abierto|cerramos|cierra|horario|atendemos|atencion|lunes\s+a\s+viernes|de\s+lunes\s+a)\b/u';

        // Una oración termina donde empieza otra en mayúscula: «de 5:00 a. m. a
        // 10:00 p. m.» es UNA, y partirla dejaba «a 10:00 p. m.» sin su «abrimos».
        // Lookbehinds de longitud fija: el PCRE de producción no admite otros.
        $piezas = array_values(array_filter(
            preg_split('/(?<=[.!?;])\s+(?=[A-ZÁÉÍÓÚÑ¿¡])|\n+/u', trim($respuesta)) ?: [],
            fn (string $f) => trim($f) !== '',
        ));
        $quedan = array_values(array_filter($piezas, function (string $frase) use ($laFila, $delHorario): bool {
            $m = CourtesyAuthority::mencionesEn($frase);
            if ($m['fechas'] === [] && $m['horas'] === []) {
                return true;
            }

            return preg_match($delHorario, SalesAgentDecisionSchema::normalize($frase)) === 1 || $this->casaConAlguno($m, $laFila);
        }));
        if (count($quedan) === count($piezas)) {
            return $respuesta;
        }

        $acta = $this->courtesy->actaDeLaFila($cita);
        $limpio = trim(implode(' ', $quedan));
        $salida = $acta === null || str_contains($limpio, $acta) ? $limpio : trim($limpio.' '.$acta);

        ChannelLog::warning('ultron.courtesy.reply_date_corrected', [
            'conversation_id' => (int) $action->conversation_id,
            'appointment_id' => (int) $cita->id,
            'cita' => $d->format('Y-m-d H:i'),
            'frases_retiradas' => count($piezas) - count($quedan),
        ]);
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['courtesy_reply_corrected'] = [
            'appointment_id' => (int) $cita->id,
            'cita' => $d->format('Y-m-d H:i'),
            'frases_retiradas' => count($piezas) - count($quedan),
        ];
        $action->forceFill(['metadata' => $meta])->save();

        return $salida !== '' ? $salida : self::CORTESIA_SIN_CONFIRMAR;
    }

    /**
     * «Dejé solicitada tu visita» cuando la herramienta NO la registró.
     *
     * La invariante de promesas sólo ve la constancia si el «equipo» va en la
     * misma oración, y con la petición parcial (sólo el día o sólo la hora, que
     * es lo normal al preguntar de uno en uno) la herramienta no registra
     * nada: la persona leía «dejé solicitada tu visita para el miércoles» y no
     * había ninguna solicitud. Si la cortesía se pidió y no se ejecutó, esas
     * frases se retiran y se pregunta lo que falta, con lo que la conversación
     * ya recogió. Lo demás del mensaje sale.
     *
     * @param  array<int,array<string,mixed>>  $ejecutadas
     * @param  array<string,mixed>  $decision
     */
    private function sinConstanciaDeUnaCortesiaQueNoSeRegistro(
        MarketingConversation $conversation,
        MarketingMessage $message,
        string $respuesta,
        MarketingAiAction $action,
        array $ejecutadas,
        array $decision,
    ): string {
        $cortesia = collect($ejecutadas)->firstWhere('tool', SalesIntents::TOOL_COURTESY_REQUEST);
        if (($cortesia['status'] ?? null) === 'executed') {
            return $respuesta;
        }
        /*
         * SIN HERRAMIENTA EJECUTADA, NINGUNA ACCIÓN SOBRE LA VISITA SE DA POR
         * HECHA. Medido en el canario el 2026-10-04: «Perfecto, cambio la visita
         * de cortesía para mañana a las 7:00 a. m.» en un turno que ni pidió la
         * herramienta; la cita seguía a las 18:00. Lo que dice ULTRON tiene que
         * ser lo que ejecutó Laravel y lo que enseña la agenda: la frase se
         * retira y sale el estado real de la fila y, si la hora era ambigua,
         * la pregunta.
         */
        if ($cortesia === null || ($decision['courtesy_action'] ?? 'request') === 'cancel') {
            return $this->sinCambioDeVisitaQueNoSeHizo($conversation, $message, $respuesta, $action, $decision, $cortesia);
        }

        $constancia = '/\b(deje|dejo|dejamos|queda|quedo|esta|ya\s+esta)\s+(tu\s+|la\s+|su\s+)?(solicitud\s+|visita\s+|cita\s+)?(de\s+(visita|cortesia)\s+)?(solicitada|registrada|anotada|apuntada)\b'
            .'|\b(tu|la|su)\s+(visita|cita|solicitud)(\s+de\s+(visita|cortesia))?\s+(ya\s+)?(quedo|queda|esta|sigue)\s+(solicitada|registrada|anotada|apuntada)\b'
            .'|\b(registre|anote|apunte|solicite)\s+(tu|la|su)\s+(visita|cita|solicitud)\b'
            .'|\b(ya\s+)?(quedaste|estas)\s+(registrad|anotad|inscrit)[oa]\b/u';
        // «Todavía no queda registrada tu visita» es la verdad que se quiere oír: no se retira.
        $negada = '/\b(no|nunca|tampoco)\s+(\w+\s+){0,2}(solicitad|registrad|anotad|apuntad|inscrit)\w*/u';

        $piezas = self::oracionesProtegidas($respuesta);
        $quedan = array_values(array_filter($piezas, function (string $p) use ($constancia, $negada): bool {
            $n = SalesAgentDecisionSchema::normalize($p);

            // Y tampoco una acción en presente o en pasado: «te registro la visita», «la muevo al jueves».
            return (preg_match($constancia, $n) !== 1 || preg_match($negada, $n) === 1) && $this->promises->cambioDeVisita($p) === null;
        }));
        if (count($quedan) === count($piezas)) {
            return $respuesta;
        }

        $objetivo = $this->memoryService->load($conversation->fresh())->activeGoal();
        $lead = $conversation->lead;
        $estado = $lead !== null ? $this->courtesy->estadoDe($lead, $conversation) : null;
        $pregunta = ($cortesia['reason'] ?? null) === CourtesyAuthority::HORA_AMBIGUA && ! empty($cortesia['options'])
            ? CourtesyRequestService::preguntaDeHora((array) $cortesia['options'], $estado === null)
            : (CommercialTurnPolicy::courtesyReply(['active_goal' => $objetivo])
                ?? 'Todavía no quedó registrada tu visita. ¿Qué día y a qué hora te queda bien?');
        if ($estado !== null && ($cortesia['reason'] ?? null) === CourtesyAuthority::HORA_AMBIGUA) {
            // El punto de «p. m.» cierra la oración: sin otro detrás.
            $pregunta = rtrim($estado, '.').'. '.$pregunta;
        }

        ChannelLog::warning('ultron.courtesy.unregistered_claim_dropped', [
            'conversation_id' => (int) $conversation->id,
            'frases_retiradas' => count($piezas) - count($quedan),
            'motivo' => $cortesia['reason'] ?? null,
        ]);
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['courtesy_unregistered_claim_dropped'] = count($piezas) - count($quedan);
        $action->forceFill(['metadata' => $meta])->save();

        $resto = trim(implode(' ', $quedan));

        return $resto === '' ? $pregunta : (str_contains($resto, '?') ? $resto : $resto.' '.$pregunta);
    }

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
    /**
     * El texto que siempre hay: variantes de lo mismo, de útil a mínima.
     *
     * Se elige la primera que sea segura y que no se haya dicho ya. Si todas
     * se dijeron, se repite la última a propósito: repetirse es peor estilo y
     * mejor servicio que el silencio.
     *
     * Devuelve null sólo si NINGUNA pasa el guard de salida, que sería un
     * fallo de estos textos y no del turno; entonces sí se calla y se marca,
     * que es el comportamiento que ya había.
     */
    /**
     * El borrador sin la oración que contiene el marcador de precio.
     *
     * Para cuando la cifra no tiene dueño claro: se quita esa oración y se
     * deja el resto, que suele contar cosas verdaderas de los planes.
     */
    private function sinLaOracionDelMarcador(string $draft): string
    {
        $trozos = preg_split('/((?<=[.!?;])\s+|\n+)/u', trim($draft), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $salida = [];
        for ($i = 0; $i < count($trozos); $i += 2) {
            $pieza = trim($trozos[$i]);
            if ($pieza === '' || preg_match('/\{\{\s*PLAN_PRICE\s*\}\}/iu', $pieza) === 1) {
                continue;
            }
            // Un punto y coma huérfano al final de lo que queda se vuelve punto.
            $salida[] = (string) preg_replace('/[;,]\s*$/u', '.', $pieza);
        }

        return trim(implode(' ', $salida));
    }

    /**
     * Lo que el CRM sabe del gimnasio, como lista de contenidos normalizados.
     *
     * Son las categorías que describen QUÉ es Iron Body —identidad, lo que
     * ofrece, dónde está, cuándo abre— más los nombres de las clases. Es lo
     * que la política exige que aparezca cuando alguien pide información.
     *
     * @return string[]
     */
    private function hechosDelGimnasio(): array
    {
        $hechos = [];
        foreach ($this->knowledge->activeItems() as $item) {
            if (in_array($item->category, ['business_identity', 'gym_info', 'location', 'schedule'], true)) {
                $hechos[] = trim((string) $item->content);
            }
        }
        foreach ((array) ($this->gym->forPrompt()['classes'] ?? []) as $clase) {
            if (is_array($clase) && ! empty($clase['name'])) {
                $hechos[] = (string) $clase['name'];
            }
        }

        return array_values(array_filter($hechos, fn ($h) => $h !== ''));
    }

    /**
     * El texto sin teléfonos. El número con su etiqueta («Teléfono de
     * contacto: 314 3455483») se quita dentro de su frase; una frase con un
     * teléfono sin etiqueta («Escríbenos al 3143455483») se retira entera,
     * porque sin el número no dice nada. Teléfono es lo que tiene forma de
     * teléfono colombiano —móvil de 10 cifras que empieza por 3, fijo 60X—,
     * con o sin +57. La versión anterior quitaba cualquier tira de cifras y
     * se comía direcciones («Calle 10 20-30») y años («desde 2015 - 2026»).
     */
    private static function sinTelefonos(string $texto): string
    {
        // Las abreviaturas de dirección y de contacto no terminan una frase: «Cl. 24», «Tel. 608…».
        $abreviatura = '/\b(cl|cll|cra|kr|cr|av|avda|dg|diag|tv|transv|mz|no|nro|num|tel|cel|ed|edif|of|apto|int|km|bis|sur|norte|este|oeste)\./iu';
        // «a. m.» y «p. m.» tampoco: el horario de la ficha se partía en trozos sueltos.
        $protegido = (string) preg_replace(['/\b([ap])\.(\s?)m\./iu', $abreviatura], ['$1§$2m§', '$1§'], trim($texto));

        $numero = '\(?\+?(?:57)?\)?[\s.-]?\(?\d{1,3}\)?[\d\s.-]{5,}\d';
        $etiqueta = '(?:tel[eé]fonos?|tel§?|cel§?|celular|whatsapp|wpp|contacto|fijo|l[ií]nea|ll[aá]manos(?:\s+al)?|escr[ií]benos(?:\s+(?:al|por(?:\s+whatsapp)?(?:\s+al)?))?|cont[aá]ctanos(?:\s+al)?)';
        $etiquetado = '/\s*'.$etiqueta.'(?:\s*[\/|]\s*'.$etiqueta.')*(?:\s+de\s+contacto)?\s*:?\s*'.$numero.'/iu';
        $conForma = '/(?<!\d)\(?(?:\+?57[\s.-]?)?\(?(?:3\d{2}|60\d)\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/u';
        // Lo que queda colgando al quitar el número: «Escríbenos por.», «Tel.», «(tel.», «Teléfono /», «| Dirección».
        $colgando = '/(\b(al|por|a|de|en|y|o)|'.$etiqueta.'|[\/|(:])\s*[.,;:)]*$/iu';

        $frases = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', $protegido) ?: [] as $frase) {
            $limpia = trim((string) preg_replace($etiquetado, '', $frase));
            if (preg_match($conForma, $limpia) === 1) {
                continue;
            }
            $limpia = trim((string) preg_replace(['/^\s*[|\/,;:]+\s*/u', '/^(o|y)\s+/iu', '/\(\s*\)/u'], '', $limpia));
            $limpia = (string) preg_replace(['/\s*,(\s*,)+/u', '/,\s*\./u', '/\s{2,}/u', '/\s+([.,;:])/u'], [',', '.', ' ', '$1'], $limpia);
            if (trim($limpia, " \t.,;:|/()") === '' || preg_match($colgando, rtrim($limpia, '.')) === 1) {
                continue;
            }
            // Si al quitar el número sólo queda la entradilla («Para más información.»), la frase ya no dice nada.
            if ($limpia !== trim($frase) && preg_match('/^(para|por|pa)\s+(mas\s+|más\s+)?(informaci[oó]n|info|informes|detalles|dudas|consultas)\.?$|^(informes|mas info|más info|cualquier (duda|inquietud|consulta))\.?$/iu', $limpia) === 1) {
                continue;
            }
            $frases[] = mb_strtoupper(mb_substr($limpia, 0, 1)).mb_substr($limpia, 1);
        }

        // «p.m.» al final de una frase protegida deja «p.m..»: un punto basta.
        return trim((string) preg_replace('/\.{2,}/u', '.', str_replace('§', '.', implode(' ', $frases))));
    }

    /**
     * La fecha y la hora EFECTIVAS de la cortesía: lo que trajo el modelo si
     * vale; si no, lo que dijo la persona hoy; si no, lo que la conversación
     * está recogiendo para una visita aún sin registrar. Se resuelve UNA vez, antes de la invariante de promesas, y la
     * invariante y la herramienta usan los mismos valores: antes la invariante
     * decidía con los null del modelo y tumbaba en 422 el turno que la
     * herramienta sí habría registrado —el mismo incidente que esto venía a
     * arreglar—. Lo que el modelo resolvió bien no se pisa con una lectura
     * literal del texto, y la autoridad sigue decidiendo si vale.
     *
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function conCortesiaDelTexto(MarketingConversation $conversation, MarketingMessage $message, array $decision): array
    {
        if (! in_array(SalesIntents::TOOL_COURTESY_REQUEST, (array) ($decision['tools_requested'] ?? []), true)
            || ($decision['courtesy_action'] ?? 'request') === 'cancel') {
            return $decision;
        }
        /*
         * Sólo lo que la conversación está RECOGIENDO: el día o la hora que la
         * persona ya dio para una visita que todavía no se registró. Un
         * objetivo ya SOLICITADO es el hueco registrado y no caduca: rellenar
         * con él registraba a las 18:00 del jueves a quien sólo dijo «quiero
         * volver a ir el jueves», o reescribía el viernes que la persona acababa
         * de rechazar con «el viernes no puedo, mejor el miércoles».
         */
        $objetivo = $this->memoryService->load($conversation)->activeGoal();
        $recogido = ($objetivo['kind'] ?? null) === ConversationMemoryService::GOAL_COURTESY
            && ($objetivo['status'] ?? null) === ConversationMemoryService::GOAL_COLLECTING
            ? (array) ($objetivo['data'] ?? [])
            : [];
        // La aclaración de a. m./p. m. en curso sobre una visita ya solicitada: su día es el recogido.
        if ($recogido === [] && ($objetivo['kind'] ?? null) === ConversationMemoryService::GOAL_COURTESY
            && isset($objetivo['data']['pending_hour']['date'])) {
            $recogido = ['date' => (string) $objetivo['data']['pending_hour']['date']];
        }
        /*
         * El texto sólo cuenta si se lee SIN DUDAS: una negación, una
         * alternativa, una corrección, un rango o una pregunta hacen que no se
         * registre nada con él, y se pregunta. Registrar el día equivocado
         * manda al equipo a preparar una visita que nadie pidió; no registrar
         * sólo cuesta una pregunta más. Y si el texto NOMBRA un día o una hora
         * que no se pudo leer sin dudas, tampoco se rellena ese dato con lo
         * recogido: la persona acaba de decir otra cosa.
         */
        $body = (string) $message->body;
        $delTexto = CourtesyAuthority::textoSinDudas($body);
        $menciones = CourtesyAuthority::mencionesEn($body);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($decision['courtesy_date'] ?? '')) !== 1) {
            $decision['courtesy_date'] = ($delTexto ? CourtesyAuthority::fechaDesdeTexto($body) : null)
                ?? ($menciones['fechas'] === [] ? ($recogido['date'] ?? null) : null);
        }
        if (CourtesyAuthority::minutosDe($decision['courtesy_time'] ?? null) === null) {
            $decision['courtesy_time'] = ($delTexto ? CourtesyAuthority::horaDesdeTexto($body) : null)
                ?? ($menciones['horas'] === [] ? ($recogido['time'] ?? null) : null);
        }

        return $decision;
    }

    /**
     * ¿El texto que sale lleva TODOS los enlaces de la app? Se mira el texto
     * final y no qué guarda lo tocó: la política, la guarda de cortesía o la
     * de servicios pueden retirar justo la frase donde se pegaron.
     */
    private static function llevaLosEnlaces(string $texto): bool
    {
        if (preg_match_all('#https?://\S+#u', MobileAppCatalog::linksInline(), $m) < 1) {
            return str_contains($texto, MobileAppCatalog::linksInline());
        }
        foreach ($m[0] as $url) {
            if (! str_contains($texto, rtrim($url, '.,;:!?)'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * La frase HONESTA sobre una visita que existe: «tu visita quedó
     * solicitada», «quedó registrada tu solicitud de visita». Nombra la
     * visita (o la solicitud DE visita), nunca una «solicitud» cualquiera.
     */
    private const RECUERDO_HONESTO = '/\b(tu|la|su)\s+(visita|cita)(\s+de\s+cortesia)?\s+(ya\s+)?(quedo|queda|esta|sigue)\s+(solicitada|registrada|pendiente|anotada)\b'
        .'|\b(quedo|queda|esta)\s+(solicitada|registrada|anotada)\s+(tu|la|su)\s+(visita|cita|solicitud\s+de\s+(visita|cortesia))\b'
        .'|\b(tu|la|su)\s+solicitud\s+de\s+(visita|cortesia)\s+(ya\s+)?(quedo|queda|esta|sigue)\s+(registrada|solicitada|pendiente|anotada)\b/u';

    /** Lo que, pegado a la frase honesta, la convierte en otra constancia: «y escalada», «y el caso de tu cobro», «de cambio». */
    private const MAS_QUE_EL_RECUERDO = '/\b(escalad|radicad|reportad|marcad|anotad|registrad|solicitad|abiert)\w*|\b(cambio|cambiar\w*|mover\w*|cancel\w*|reprogram\w*|caso|reclamo|reporte|revision|peticion|cobro|pago)\b|\b(y|e)\s+(la|el|lo|las|los)\s+de\b/u';

    /**
     * ¿Toda constancia de efecto durable del borrador es el recuerdo honesto
     * de una visita que sigue viva? Oración a oración: cada una que la
     * invariante vea como constancia tiene que ser la frase honesta sobre un
     * compromiso del lead que aún no pasó, con SU día y SU hora si los nombra
     * (todos los que nombre), y sin nada más coordinado. Lo demás sigue
     * necesitando autoridad: «tu visita quedó solicitada. Lo dejo escalado al
     * equipo» es una promesa.
     *
     * @param  array<int,array<string,mixed>>  $compromisos
     */
    private function soloRecuerdaLaVisita(string $texto, array $compromisos): bool
    {
        $vivos = array_values(array_filter($compromisos, fn (array $c) => ! ($c['past'] ?? false)));
        if ($vivos === []) {
            return false;
        }

        $alguna = false;
        foreach (self::oracionesProtegidas(SalesAgentDecisionSchema::normalize($texto)) as $frase) {
            if ($this->promises->efectoDurableEn($frase) === null) {
                continue;
            }
            if (preg_match(self::RECUERDO_HONESTO, $frase) !== 1) {
                return false;
            }
            $resto = (string) preg_replace(self::RECUERDO_HONESTO, ' ', $frase);
            if (preg_match(self::MAS_QUE_EL_RECUERDO, $resto) === 1 || $this->promises->efectoDurableEn($resto) !== null) {
                return false;
            }
            if (! $this->casaConAlguno(CourtesyAuthority::mencionesEn($frase), $vivos)) {
                return false;
            }
            $alguna = true;
        }

        return $alguna;
    }

    /**
     * Las oraciones de un texto sin partir «a. m.» ni «p. m.»: cortar por el
     * punto de «p.» dejaba «…a las 7:00 p.» por un lado y «m. Trae ropa
     * cómoda» por otro, y la hora se leía de la mañana.
     *
     * @return string[]
     */
    private static function oracionesProtegidas(string $texto): array
    {
        return array_values(array_filter(
            preg_split('/(?<=[.!?;])(?<!\b[aApP]\.)\s+|\n+/u', $texto) ?: [],
            fn (string $f) => trim($f) !== '',
        ));
    }

    /**
     * ¿Lo que un texto nombra de fecha y hora casa ENTERO con algún
     * compromiso? Toda fecha que nombre tiene que ser la suya —una que no se
     * puede resolver no casa con nada— y toda hora tiene que tener, entre sus
     * lecturas, la del compromiso. Sin fecha ni hora nombradas, casa.
     *
     * @param  array{fechas: array<int,?string>, horas: array<int,array<int,string>>}  $menciones
     * @param  array<int,array<string,mixed>>  $compromisos
     */
    private function casaConAlguno(array $menciones, array $compromisos): bool
    {
        foreach ($compromisos as $c) {
            $casa = true;
            foreach ($menciones['fechas'] as $f) {
                $casa = $casa && $f !== null && $f === $c['date'];
            }
            foreach ($menciones['horas'] as $lecturas) {
                $casa = $casa && in_array($c['time'], $lecturas, true);
            }
            if ($casa) {
                return true;
            }
        }

        return false;
    }

    /** La violación de un turno de memoria que da un día, una hora o un estado que no son los del compromiso. */
    public const VIOLACION_MEMORIA_DATO = 'memory_turn_wrong_commitment';

    /**
     * ¿El borrador de un turno de memoria da un día, una hora o un estado que
     * no son los de un compromiso del lead? Se leen TODAS las fechas y horas
     * que nombra (no se elige ni se descarta nada: una fecha de más, una
     * negación o una cláusula sobre el horario no lo vuelven un comodín) y
     * tienen que casar con un mismo compromiso. Si el borrador dice que la
     * visita está confirmada, el compromiso tiene que estarlo. Un compromiso
     * que ya pasó sólo vale si el borrador habla en pasado («era», «estaba»).
     * Sin fecha ni hora nombradas no hay nada que contradecir, salvo que
     * afirme una confirmación que no existe.
     */
    private function contradiceLosCompromisos(MarketingConversation $conversation, string $texto): bool
    {
        $menciones = CourtesyAuthority::mencionesEn($texto);
        $afirmaConfirmada = $this->contentGuard->courtesyConfirmationIn($texto) !== null;
        if ($menciones['fechas'] === [] && $menciones['horas'] === [] && ! $afirmaConfirmada) {
            return false;
        }
        $lead = $conversation->lead;
        $compromisos = $lead !== null ? $this->courtesy->commitmentsForLead($lead) : [];
        $enPasado = preg_match('/\b(era|estaba|fue|tenias)\b/u', SalesAgentDecisionSchema::normalize($texto)) === 1;

        $candidatos = array_values(array_filter(
            $compromisos,
            fn (array $c) => (! $c['past'] || $enPasado) && (! $afirmaConfirmada || $c['status'] === 'confirmed'),
        ));

        return ! $this->casaConAlguno($menciones, $candidatos);
    }

    /**
     * El respaldo de «quiero información», armado con la ficha del gimnasio.
     *
     * Quién somos, qué ofrecemos, dónde estamos, cuándo abrimos y qué clases
     * hay: cada pieza sale de la base de conocimiento o del CRM y falta la
     * que no exista. Cerraba con un menú («planes, horarios o cómo empezar»)
     * y eso suena a máquina; ahora cierra con UNA pregunta abierta, que sigue
     * sin verbo de ofrecimiento para no pisar la oferta viva de la memoria.
     * Cumple la política por construcción: los hechos son los mismos que se
     * exigen.
     */
    private function respuestaConHechos(): ?string
    {
        $piezas = ['business_identity' => null, 'gym_info' => null, 'location' => null, 'schedule' => null];
        foreach ($this->knowledge->activeItems() as $item) {
            /*
             * Sin teléfonos: la ficha de ubicación de producción trae el
             * número de contacto, y el guard de salida lee siete cifras
             * seguidas como un precio inventado. Este respaldo se bloqueó
             * entero por eso en una prueba física y salió el último recurso.
             */
            $c = self::sinTelefonos(trim((string) $item->content));
            if ($c !== '' && array_key_exists($item->category, $piezas) && $piezas[$item->category] === null) {
                $piezas[$item->category] = $c;
            }
        }
        $partes = array_values(array_filter($piezas));
        $clases = $this->nombresDeClases();
        if ($partes === [] && $clases === []) {
            return null;
        }
        if ($clases !== []) {
            $partes[] = 'Nuestras clases son '.$this->enumerar($clases).'.';
        }

        return 'Con gusto te cuento. '.implode(' ', $partes).' ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?';
    }

    /**
     * Retira las oraciones que afirman una clase, disciplina o instalación que
     * el gimnasio no tiene.
     *
     * Se quita la oración entera, no se reescribe: reescribir sería inventar
     * otro servicio. Si no queda nada, sale la verdad con lo que sí hay
     * registrado, en lugar del respaldo genérico: la persona preguntó por una
     * clase y merece una respuesta sobre clases.
     */
    /**
     * @param  string[]  $hechos  la ficha del gimnasio y los nombres de sus clases
     * @param  Plan|null  $plan  el plan de este turno, por si la oración retirada se llevaba su precio
     */
    private function sinServiciosInventados(MarketingConversation $conversation, string $respuesta, MarketingAiAction $action, array $hechos, ?Plan $plan): string
    {
        $ofrecido = $this->loQueOfrecemos($hechos);
        if ($this->style->inventedServiceIn($respuesta, $ofrecido) === null) {
            return $respuesta;
        }

        $retiradas = [];
        $limpias = [];
        foreach ($this->oraciones($respuesta) as $pieza) {
            if ($this->style->inventedServiceIn($pieza[0], $ofrecido) !== null) {
                $retiradas[] = trim($pieza[0]);

                continue;
            }
            $limpias[] = $pieza;
        }

        /*
         * Lo que queda tiene que AFIRMAR algo. «Nuestras clases son:» con la
         * lista retirada, o «¿Cuál prefieres?» a secas, no son un mensaje:
         * son la coletilla de uno que ya no existe. En ese caso sale la
         * verdad con las clases reales, y la pregunta final se conserva.
         *
         * Y si la oración retirada era la que traía el precio que la persona
         * preguntó («las clases de yoga están incluidas en el Plan Mensual,
         * que cuesta $80.000»), el precio del plan de este turno se afirma
         * igual: la clase no existe, el precio sí.
         */
        // Sin `\p{L}`: el PCRE de producción no lo compila.
        $esPregunta = fn (array $p) => str_ends_with(trim($p[0]), '?');
        $declara = array_filter($limpias, fn (array $p) => preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $p[0]) === 1 && preg_match('/[?:]\s*$/u', trim($p[0])) !== 1);
        $precioRetirado = $plan !== null
            && array_filter($retiradas, fn (string $r) => str_contains($r, '$')) !== []
            && ! array_filter($limpias, fn (array $p) => str_contains($p[0], '$'));

        if ($declara === [] || $precioRetirado) {
            $cuerpo = array_map(fn (array $p) => trim($p[0]), array_values(array_filter($limpias, fn (array $p) => isset($declara[array_search($p, $limpias, true)]))));
            if ($cuerpo === []) {
                $cuerpo[] = $this->loQueSiTenemos();
            }
            if ($precioRetirado) {
                $cuerpo[] = 'El '.trim((string) $plan->name).' cuesta '.$this->replies->formatCop((float) $plan->price).'.';
            }
            $preguntas = array_map(fn (array $p) => trim($p[0]), array_filter($limpias, $esPregunta));
            $limpio = trim(implode(' ', $cuerpo).' '.implode(' ', $preguntas));
        } else {
            $salida = '';
            foreach ($limpias as $i => [$texto, $sep]) {
                $salida .= $texto;
                if ($i < count($limpias) - 1) {
                    $salida .= $sep !== '' ? $sep : ' ';
                }
            }
            $limpio = trim($salida);
        }

        ChannelLog::warning('ultron.commit.invented_service_dropped', [
            'conversation_id' => (int) $conversation->id,
            'frases_retiradas' => $retiradas,
            'quedo_sin_afirmacion' => $declara === [],
            'precio_repuesto' => $precioRetirado,
        ]);
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['invented_service_dropped'] = $retiradas;
        $action->forceFill(['metadata' => $meta])->save();

        return $limpio;
    }

    /**
     * El texto partido en oraciones, cada una con el separador que la seguía,
     * para volver a unirlas tal cual tras retirar una.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private function oraciones(string $texto): array
    {
        $trozos = preg_split('/('.ComposerStyleGuard::SEPARADOR_DE_ORACIONES.')/u', trim($texto), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $piezas = [];
        for ($i = 0; $i < count($trozos); $i += 2) {
            if (trim($trozos[$i]) === '') {
                continue;
            }
            $piezas[] = [$trozos[$i], $trozos[$i + 1] ?? ''];
        }

        return $piezas;
    }

    /**
     * Lo que el gimnasio SÍ ofrece, para medir el borrador: los hechos de la
     * ficha más los tipos de las clases activas (el nombre ya va en los hechos).
     *
     * @return string[]
     */
    private function loQueOfrecemos(array $hechos): array
    {
        $ofrecido = $hechos;
        foreach ((array) ($this->gym->forPrompt()['classes'] ?? []) as $clase) {
            if (is_array($clase) && ! empty($clase['type'])) {
                $ofrecido[] = (string) $clase['type'];
            }
        }

        return $ofrecido;
    }

    /** La verdad sobre clases cuando el borrador entero era una clase que no existe. */
    private function loQueSiTenemos(): string
    {
        $clases = $this->nombresDeClases();
        if ($clases === []) {
            return 'No tengo esa información confirmada en este momento, pero puedo ayudarte con lo que sí tenemos registrado.';
        }

        return 'Esa clase no la tengo confirmada en mi información. Lo que sí tenemos registrado es '.$this->enumerar($clases).'.';
    }

    /**
     * Nombres de las clases activas, una vez cada uno: una clase con tres
     * horarios es una clase.
     *
     * @return string[]
     */
    private function nombresDeClases(): array
    {
        $nombres = [];
        foreach ((array) ($this->gym->forPrompt()['classes'] ?? []) as $clase) {
            if (is_array($clase) && ! empty($clase['name'])) {
                $nombres[] = (string) $clase['name'];
            }
        }

        return array_values(array_unique($nombres));
    }

    /**
     * «A, B y C», con la «e» que pide el español delante de un nombre que
     * empieza por i: «IRON PARTY, IRON POWERFLOW e IRON STRENGTH».
     *
     * @param  string[]  $cosas
     */
    private function enumerar(array $cosas): string
    {
        if (count($cosas) <= 1) {
            return (string) ($cosas[0] ?? '');
        }
        $ultimo = array_pop($cosas);
        $nexo = preg_match('/^h?i(?![aeo])/iu', $ultimo) === 1 ? ' e ' : ' y ';

        return implode(', ', $cosas).$nexo.$ultimo;
    }

    /**
     * Retira las frases que especulan sobre por qué un precio cambió.
     *
     * Se quita la oración entera, no se reescribe: reescribir sería inventar
     * otra explicación. Si el mensaje se queda vacío, sale el respaldo de la
     * intención; si sigue habiendo texto, sale lo que queda.
     */
    private function sinExcusasDePrecio(MarketingConversation $conversation, string $respuesta, MarketingAiAction $action, string $intent): string
    {
        if ($this->style->priceExcuseIn($respuesta) === null) {
            return $respuesta;
        }

        $piezas = $this->oraciones($respuesta);
        $limpias = array_values(array_filter($piezas, fn (array $p) => $this->style->priceExcuseIn($p[0]) === null));

        $salida = '';
        foreach ($limpias as $i => [$texto, $sep]) {
            $salida .= $texto;
            if ($i < count($limpias) - 1) {
                $salida .= $sep !== '' ? $sep : ' ';
            }
        }
        $limpio = trim($salida);

        ChannelLog::warning('ultron.commit.price_excuse_dropped', [
            'conversation_id' => (int) $conversation->id,
            'frases_retiradas' => count($piezas) - count($limpias),
            'quedo_vacio' => $limpio === '',
        ]);
        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['price_excuse_dropped'] = count($piezas) - count($limpias);
        $action->forceFill(['metadata' => $meta])->save();

        // Un mensaje vacío no es un mensaje: sale el respaldo de la intención.
        if ($limpio === '') {
            return $this->ultimoRecurso($conversation, $intent) ?? $respuesta;
        }

        return $limpio;
    }

    private function ultimoRecurso(MarketingConversation $conversation, string $intent): ?string
    {
        $previas = $this->previousMachineReplies($conversation);
        $variantes = $this->replies->lastResorts($intent);
        $seguras = [];

        foreach ($variantes as $v) {
            if (! $this->contentGuard->inspect($v, MarketingMessage::SENDER_AI, false)['safe']) {
                ChannelLog::warning('ultron.commit.last_resort_blocked', [
                    'conversation_id' => (int) $conversation->id, 'intent' => $intent,
                ]);

                continue;
            }
            $seguras[] = $v;

            if ($this->novelty->nearDuplicateOf($v, $previas) === null) {
                return $v;
            }
        }

        // Todas dichas ya: se repite la más mínima antes que callar.
        return $seguras === [] ? null : end($seguras);
    }

    /**
     * El respaldo que corresponde a la intención cuando el borrador se
     * descartó por la política: bienvenida para el saludo puro, la ficha
     * real para «quiero información», y el último recurso para el resto
     * (las objeciones y los planes ya tienen su sustituto específico más
     * abajo en la cadena).
     *
     * @param  array<string,mixed>  $policy
     */
    private function respaldoPorIntencion(MarketingConversation $conversation, string $intent, array $policy): ?string
    {
        // Toda salida de máquina pasa por el guard, también la de Laravel:
        // por una que no pasaba salieron dos ofertas de traspaso.
        $seguro = fn (?string $texto): ?string => $texto !== null && trim($texto) !== ''
            && $this->contentGuard->inspect($texto, MarketingMessage::SENDER_AI, false)['safe'] ? $texto : null;

        if ($policy['reception_mode'] ?? false) {
            return $seguro($this->saludoDeBienvenida($conversation)) ?? $this->ultimoRecurso($conversation, $intent);
        }
        if ($policy['memory_mode'] ?? false) {
            return $seguro($this->respaldoDeMemoria($conversation)) ?? $this->ultimoRecurso($conversation, $intent);
        }
        if ($intent === SalesIntents::GENERAL_INFO) {
            return $seguro($this->respuestaConHechos()) ?? $this->ultimoRecurso($conversation, $intent);
        }

        return $this->ultimoRecurso($conversation, $intent);
    }

    /**
     * La respuesta a «¿a qué hora era mi visita?» cuando el modelo no la dio
     * o la dio vendiendo: el compromiso vivo del lead, venga de la
     * conversación que venga, con la verdad de su estado. Confirmada, se dice
     * que el equipo la confirmó; solicitada, que el equipo la revisa; si era
     * hoy y la hora ya pasó, se dice eso y no «te esperamos». No se ofrece
     * moverla ni cancelarla desde aquí: esas herramientas sólo tocan la
     * solicitud de su conversación, y prometerlo sería prometer lo que no se
     * puede cumplir. Sin compromiso, se dice que no hay y se pide el dato.
     * Sin verbos de ofrecimiento.
     */
    private function respaldoDeMemoria(MarketingConversation $conversation): string
    {
        $lead = $conversation->lead;
        $compromisos = $lead !== null ? $this->courtesy->commitmentsForLead($lead) : [];
        // Van primero las que aún no pasaron: el primero es el que importa.
        $c = $compromisos[0] ?? null;

        if ($c === null) {
            return 'No tengo registrada una visita tuya. Dime el día y la hora que te sirven y la dejo solicitada.';
        }
        if ($c['past']) {
            return 'Tu visita estaba para el '.$c['human'].'. Si no alcanzaste a venir, dime qué otro día y hora te sirven y la dejo solicitada.';
        }
        if ($c['status'] === 'confirmed') {
            return 'Claro. El equipo ya confirmó tu visita: '.$c['human'].'. Te esperamos.';
        }

        return 'Claro. Tu visita de cortesía quedó solicitada para el '.$c['human'].'; el equipo la revisa para tenerlo todo listo.';
    }

    /**
     * Las intenciones que tienen su PROPIO texto curado y su propia marca: ni
     * la memoria ni una visita a medias las pisan con el critic caído. La
     * revisión midió «me lesioné la rodilla» contestado con «¿qué día y a qué
     * hora te queda bien?».
     */
    private const RESPALDO_PROPIO = [
        SalesIntents::MEDICAL_RISK_ESCALATION, SalesIntents::FRAUD_OR_PAYMENT_CLAIM, SalesIntents::HUMAN_REQUEST,
        SalesIntents::COMPLAINT, SalesIntents::INVOICE_REQUEST,
        SalesIntents::NOT_INTERESTED, SalesIntents::DO_NOT_CONTACT_REQUEST,
        SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE, SalesIntents::PRICING_QUESTION,
        // Las objeciones y las despedidas también tienen el suyo: «lo voy a pensar» no es un día para la visita.
        ...SalesIntents::OBJECTION_INTENTS,
        SalesIntents::THANKS, SalesIntents::GOODBYE, SalesIntents::BOT_QUESTION,
    ];

    /**
     * El respaldo semántico del camino del critic caído, leído del texto
     * entrante con los MISMOS criterios que el camino normal: un saludo puro
     * recibe bienvenida; una pregunta de memoria sobre la visita, el
     * compromiso real; un fragmento de la visita a medias («mañana», «tipo
     * 7»), la visita; «quiero información», la ficha real. Null = no hay
     * respaldo específico y sigue el curado de su intención.
     */
    private function respaldoSemantico(MarketingConversation $conversation, string $inbound, string $intent): ?string
    {
        if (SalesIntents::isPureGreeting($inbound)) {
            return $this->saludoDeBienvenida($conversation);
        }
        if (in_array($intent, self::RESPALDO_PROPIO, true)) {
            return null;
        }

        $planes = $this->knowledge->activePlans();
        $memoria = $this->memoryService->load($conversation);
        $resolucion = $this->references->resolve($inbound, $memoria, $this->memoryService->sellablePlansForMemory());
        if (CommercialTurnPolicy::esTurnoDeMemoria($intent, $inbound, $planes, $resolucion)) {
            return $this->respaldoDeMemoria($conversation);
        }

        /*
         * Con la visita a medio concretar, el respaldo es la visita SÓLO si el
         * mensaje es un fragmento de la visita. Lo decide la misma política del
         * camino normal (`goal_takes_turn`) leyendo el TEXTO: una pregunta, un
         * precio, los planes o un «ya no quiero ir» se contestan como lo que
         * son. La etiqueta del modelo no cuenta aquí —lo sensible y lo del
         * dinero ya salieron arriba—, porque este camino existe justo cuando el
         * modelo falló: en producción un «mañana miércoles a 2 pm» llegó
         * etiquetado como pregunta de horario y salió el horario de apertura.
         */
        $politica = CommercialTurnPolicy::decide(
            SalesIntents::UNKNOWN,
            (string) ($conversation->commercial_phase ?? ''),
            $memoria,
            $planes,
            $resolucion,
            $inbound,
        );
        if (($politica['goal_takes_turn'] ?? false) === true && self::esFragmentoDeLaVisita($inbound)) {
            $deVisita = CommercialTurnPolicy::courtesyReply($politica);
            if ($deVisita !== null) {
                return $deVisita;
            }
        }
        if ($intent === SalesIntents::GENERAL_INFO) {
            return $this->respuestaConHechos();
        }

        return null;
    }

    /**
     * ¿El mensaje es un FRAGMENTO de la visita a medias? Trae un día, una hora
     * o una franja («mañana», «tipo 7», «en la noche»), o es una respuesta
     * cortísima sin negación («sí», «ok»). «Lo voy a pensar», «no voy a ir» o
     * «me da pena» no lo son: tienen su propio texto.
     */
    private static function esFragmentoDeLaVisita(string $inbound): bool
    {
        $t = SalesAgentDecisionSchema::normalize($inbound);
        $menciones = CourtesyAuthority::mencionesEn($inbound);
        if ($menciones['fechas'] !== [] || $menciones['horas'] !== []
            || preg_match('/\b(manana|tarde|noche|mediodia|madrugada|temprano|hoy|pasado)\b/u', $t) === 1) {
            return true;
        }
        // Sin \p{…}: el PCRE de producción no lo soporta. El texto ya viene en minúsculas y sin tildes.
        $palabras = preg_split('/\s+/u', trim((string) preg_replace('/[^a-z0-9\s]/u', ' ', $t))) ?: [];

        return count(array_filter($palabras)) <= 2 && preg_match('/\b(no|nunca|ni|tampoco|nel|paso)\b/u', $t) !== 1;
    }

    /**
     * La bienvenida con el saludo de la franja real del gimnasio; la primera
     * variante que no se haya dicho en esta conversación, y si todas se
     * dijeron, la última: repetir un saludo es mejor que un menú.
     */
    private function saludoDeBienvenida(MarketingConversation $conversation): string
    {
        $franja = (string) (BusinessClock::forPrompt()['greeting'] ?? '');
        // Ya saludamos en esta conversación, o ya hablamos con esta persona en
        // otra: a quien vuelve se le recibe de nuevo, no se le estrena.
        $yaSaludamos = $this->memoryService->load($conversation)->get('greeted') !== null
            || MarketingConversation::query()->where('lead_id', $conversation->lead_id)->where('id', '!=', $conversation->id)->exists();
        $previas = $this->previousMachineReplies($conversation);
        $variantes = $this->replies->welcomeReplies($franja, $yaSaludamos);

        foreach ($variantes as $v) {
            if ($this->novelty->nearDuplicateOf($v, $previas) === null) {
                return $v;
            }
        }

        return end($variantes) ?: $variantes[0];
    }

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
