<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Commercial\Tools\Commercial\EscalateToHumanTool;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentOrchestratorService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesPaymentReadinessService;

/**
 * Lo que ULTRON necesita saber para proponer una respuesta, y nada más.
 *
 * Es la mitad de solo lectura del contrato: aquí no se escribe una fila. Llama
 * al cerebro que ya existe —`SalesAgentOrchestratorService::analyze()`, que
 * está documentado como puro— y le añade el estado de la máquina comercial y
 * las listas blancas con las que ULTRON tiene que jugar.
 *
 * Dos omisiones son deliberadas y son la mitad del diseño:
 *
 *  - **Sin PII.** Ni teléfono, ni wa_id, ni meta_user_id, ni identificadores de
 *    Meta. ULTRON contesta por `conversation_id`; no necesita saber a quién le
 *    escribe, y lo que no viaja no se filtra.
 *  - **Sin precios.** Los planes van con nombre, duración y beneficios, pero
 *    sin `price`. ULTRON elige el plan; Laravel lo cotiza al escribir el
 *    mensaje, leyendo `Plan::price` en ese momento. Un modelo que nunca ve una
 *    cifra no puede inventarse una.
 */
class UltronDecideService
{
    /**
     * Herramientas siempre permitidas en v1 (no dependen de ninguna configuración).
     * El menú real de cada turno lo da {@see allowedTools()}: `payment_link_send`
     * entra solo cuando Wompi está productivo Y el negocio autorizó el link automático.
     */
    public const V1_ALLOWED_TOOLS = [
        SalesIntents::TOOL_STAFF_REVIEW,
        SalesIntents::TOOL_MARK_DNC,
        SalesIntents::TOOL_APP_LINKS_SEND,
    ];

    /**
     * Todo lo que el modelo puede NOMBRAR como herramienta (vocabulario del
     * endpoint). Pedir una que hoy no está permitida no es un error de forma:
     * commit la descarta y lo anota en tools_rejected. El PERMISO lo da
     * {@see allowedTools()}, turno a turno.
     */
    public const TOOL_VOCABULARY = [
        SalesIntents::TOOL_STAFF_REVIEW,
        SalesIntents::TOOL_MARK_DNC,
        SalesIntents::TOOL_APP_LINKS_SEND,
        SalesIntents::TOOL_PAYMENT_LINK_SEND,
    ];

    public function __construct(
        private readonly SalesAgentOrchestratorService $orchestrator,
        private readonly CommercialPhaseMachine $phases,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly SalesPaymentReadinessService $paymentReadiness,
        private readonly UltronDecideToken $tokens,
        private readonly ConversationMemoryService $memoryService,
        private readonly ReferenceResolver $references,
        private readonly NoveltyGuard $novelty,
        private readonly CustomerIntelligenceService $customers,
        private readonly GymFactsProvider $gym,
        private readonly PaymentStatusProvider $payments,
        private readonly MobileAppCatalog $appCatalog,
        private readonly MembershipFactsProvider $membership,
        private readonly HumanHandoffAuthority $handoff = new HumanHandoffAuthority,
    ) {}

    /**
     * Motivo por el que este mensaje no es decidible, o null si lo es.
     *
     * Se comprueba aquí y se vuelve a comprobar en el commit: entre las dos
     * llamadas pasa tiempo, y el estado que importa es el de después.
     */
    public function ineligibleReason(MarketingConversation $conversation, MarketingMessage $message): ?string
    {
        $lead = $conversation->lead;

        if ($lead === null) {
            return 'lead_missing';
        }
        if ((int) $message->conversation_id !== (int) $conversation->id) {
            return 'message_not_in_conversation';
        }
        if ($message->direction !== MarketingMessage::DIRECTION_INBOUND) {
            return 'message_not_inbound';
        }
        if ($message->sender_type !== MarketingMessage::SENDER_LEAD) {
            return 'message_not_from_lead';
        }
        if ($conversation->status === 'closed') {
            return 'conversation_closed';
        }
        if (! $lead->canReplyReactively()) {
            return 'do_not_contact';
        }
        if ($conversation->human_takeover && $conversation->human_takeover_source === 'manual') {
            return 'human_takeover';
        }
        if (! $conversation->ai_enabled) {
            return 'ai_disabled';
        }

        return null;
    }

    /**
     * ¿Llegó algo más nuevo de esta persona mientras pensábamos?
     *
     * El caso real: alguien escribe «hola», «quiero información» y «cuánto
     * vale?» en quince segundos. Son tres eventos y tres recorridos de ULTRON;
     * sin esto, la persona recibiría tres respuestas, y las dos primeras
     * contestarían a preguntas que ella misma ya superó.
     *
     * Gana el último. Los anteriores se descartan sin ruido.
     *
     * PERO sólo puede ceder el turno a quien va a tener turno propio. Ésa es la
     * condición que faltaba y que producía silencio absoluto: una nota de voz,
     * una foto sin pie, un sticker o una reacción 👍 crean fila INBOUND/LEAD en
     * `MetaConversationService::recordInbound()`, y el enrutador las aparta
     * ANTES de `UltronEventEmitter::emit()`. Contando esas filas, la pregunta
     * anterior se descartaba «porque llegó algo más nuevo» y lo más nuevo no lo
     * iba a contestar nadie: cero respuestas a una persona que sí preguntó.
     *
     * El criterio no se reimplementa: el que manda es el evento. Existe fila en
     * `marketing_automation_events` ⇔ Laravel consideró ese mensaje atendible y
     * lo mandó a ULTRON. Si no la hay, ese mensaje no es un relevo, es ruido del
     * inbox. Tampoco releva un evento `failed` (n8n no llegó a recibirlo tras
     * sus tres reintentos) ni uno `skipped`: prometen un turno que no habrá.
     * Y en el empate —el evento del mensaje nuevo todavía no existe— se falla
     * del lado de contestar, que es el lado seguro.
     */
    public function supersededBy(MarketingConversation $conversation, MarketingMessage $message): ?int
    {
        $ultimo = MarketingMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_INBOUND)
            ->where('sender_type', MarketingMessage::SENDER_LEAD)
            ->where('id', '>', (int) $message->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('marketing_automation_events')
                ->whereColumn('marketing_automation_events.message_id', 'marketing_messages.id')
                ->whereIn('marketing_automation_events.status', [
                    MarketingAutomationEvent::STATUS_PENDING,
                    MarketingAutomationEvent::STATUS_SENT,
                ])
            )
            ->max('id');

        return $ultimo !== null ? (int) $ultimo : null;
    }

    /** Fase actual de la conversación, normalizada. */
    public function currentPhase(MarketingConversation $conversation): string
    {
        return $this->phases->normalize($conversation->commercial_phase);
    }

    /**
     * Hechos que condicionan las transiciones. Los decide Laravel; ULTRON los
     * recibe ya resueltos y no puede discutirlos.
     *
     * @return array<string,mixed>
     */
    public function canOfferLink(): bool
    {
        return $this->paymentReadiness->canGenerateAutomaticLink();
    }

    public function phaseContext(MarketingConversation $conversation, MarketingMessage $message): array
    {
        $lead = $conversation->lead;

        return [
            'do_not_contact' => $lead !== null && ! $lead->canReplyReactively(),
            'human_takeover' => (bool) $conversation->human_takeover,
            'can_offer_link' => $this->paymentReadiness->canGenerateAutomaticLink(),
            'needs_human' => $this->needsHuman($conversation, $message),
        ];
    }

    /**
     * ¿Está autorizada HOY la derivación a una persona en esta conversación?
     *
     * Son HECHOS de Laravel, nunca la etiqueta del modelo:
     *
     *   1. Ya hay una persona al mando (`human_takeover`).
     *   2. El lead está en `needs_human`. Ese estado NO lo pone el router de
     *      entrada (que ante queja o lesión solo marca
     *      `conversation.staff_review_pending`): lo escribe el subsistema
     *      Commercial ({@see EscalateToHumanTool})
     *      o una persona desde el CRM. Lesión y queja, por tanto, NO abren
     *      esta puerta por sí solas.
     *   3. La persona PIDIÓ un humano en el mensaje de este turno, corroborado
     *      contra el texto real por {@see HumanHandoffAuthority}.
     *
     * Se calcula sobre `(conversación, mensaje)`, los mismos dos objetos que
     * tienen decide y commit, y el mensaje es OBLIGATORIO a propósito: el
     * token de decide firma la lista de transiciones y commit la recalcula.
     * Un llamador que lo omitiera obtendría `needs_human = false`, y toda
     * derivación legítima moriría como `stale_decision`.
     */
    private function needsHuman(MarketingConversation $conversation, MarketingMessage $message): bool
    {
        if ((bool) $conversation->human_takeover) {
            return true;
        }

        $lead = $conversation->lead;
        if ($lead !== null && (string) $lead->status === MarketingLead::STATUS_NEEDS_HUMAN) {
            return true;
        }

        return $this->handoff->peticionDeHumanoEn((string) $message->body) !== null;
    }

    /**
     * El paquete completo. SIN efectos: la llamada al modelo puede ocurrir, la
     * escritura de negocio no.
     *
     * @return array<string,mixed>
     */
    public function decide(MarketingConversation $conversation, MarketingMessage $message): array
    {
        $lead = $conversation->lead;

        // El cerebro de siempre. No se reimplementa el clasificador, ni el
        // override de precio, ni el scoring, ni los guardrails: se llaman.
        $decision = $this->orchestrator->analyze($lead, (string) $message->body, [
            'lead' => $lead,
            'channel' => $conversation->channel,
            'conversation' => $conversation,
            'plan' => null,
        ]);

        $phase = $this->currentPhase($conversation);
        $transitions = $this->phases->allowedTransitions($phase, $this->phaseContext($conversation, $message));

        /*
         * MEMORIA ESTRUCTURADA Y REFERENTE. Tres hechos que Laravel calcula y
         * el modelo recibe resueltos: qué se entregó ya (para no repetirlo), a
         * qué se refiere este mensaje («sí por favor» = la oferta de la última
         * frase del agente) y qué queda por contar. Sin esto el modelo tenía
         * que adivinar el referente en diez mensajes crudos, y adivinó
         * «traspaso» donde la persona había dicho «sí, explícame cómo empezar».
         */
        $memory = $this->memoryService->load($conversation);
        $plansForMemory = $this->memoryService->sellablePlansForMemory();
        $resolved = $this->references->resolve((string) $message->body, $memory, $plansForMemory);
        $novelty = $this->novelty->guidance($memory, $plansForMemory, $resolved);
        $customer = $this->customers->profile($conversation, $message, $memory, $resolved, (string) ($decision['intent'] ?? SalesIntents::UNKNOWN));
        $payment = $this->payments->forLead($conversation->lead, (string) $message->body);
        $canOfferLink = $this->paymentReadiness->canGenerateAutomaticLink();
        $membershipFacts = $this->membership->forPrompt($conversation->lead);
        $strategyHints = StrategyContract::hints($customer, $resolved, $canOfferLink, (string) ($decision['intent'] ?? SalesIntents::UNKNOWN), $payment, $membershipFacts);
        // El MENÚ del que ULTRON puede elegir, no un filtrado de lo que Laravel
        // ya propuso: lo que la decisión base pidiera viaja aparte, dentro de
        // `decision`. Mezclar las dos cosas haría que el techo dependiera de la
        // propuesta, que es justo al revés de como debe funcionar un techo.
        $tools = $this->allowedTools();
        $knowledgeVersion = $this->knowledge->version();

        $token = $this->tokens->issue(
            (int) $conversation->id,
            (int) $message->id,
            $phase,
            $transitions,
            $knowledgeVersion,
            // Lo que commit no puede recalcular sin volver a salir a la red (el
            // estado del pago) o sin fiarse de la etiqueta del modelo (las
            // pistas) viaja aquí, firmado: es el mundo que decide vio.
            ['hints' => [
                'hot_lead_fast_path' => $strategyHints['hot_lead_fast_path'],
                'lifecycle_mode' => $strategyHints['lifecycle_mode'],
            ], 'payment_state' => (string) ($payment['state'] ?? 'none')],
        );

        return [
            'ok' => true,
            'source' => [
                'source_type' => UltronSource::INBOUND_MESSAGE,
                'source_event_id' => (int) $message->id,
                'idempotency_key' => $message->meta_message_id,
            ],
            'decision' => $decision,
            'context' => [
                'lead' => [
                    'name' => $lead->name,
                    'objective' => $lead->objective,
                    'temperature' => $lead->temperature,
                    'do_not_contact' => (bool) $lead->do_not_contact,
                    'is_member' => $lead->member_id !== null,
                ],
                'memory' => [
                    'summary' => $conversation->summary,
                    'detected_objective' => $conversation->detected_objective,
                    'primary_intent' => $conversation->primary_intent,
                    'last_intent' => $conversation->last_intent,
                    'lead_score' => (int) ($conversation->lead_score ?? 0),
                    'lead_stage' => $conversation->lead_stage,
                    'commercial_phase' => $phase,
                    'main_barrier' => $conversation->main_barrier,
                    'structured' => $memory->toArray(),
                ],
                'resolved_reference' => $resolved,
                'novelty' => $novelty,
                /*
                 * Quién es la persona, con honestidad: KNOWN (hechos del CRM),
                 * INFERRED (lo que sugiere la conversación) y UNKNOWN (lo que
                 * se puede preguntar). Temperatura por señales, ciclo de vida
                 * por hechos. A quien ya pagó no se le vende lo mismo.
                 */
                'customer' => $customer,
                /*
                 * El pago, como hecho del CRM: none | pending | approved | declined |
                 * expired, si la persona dice que pagó y si se consultó a Wompi en
                 * vivo. Nunca la URL ni la referencia.
                 */
                'payment' => $payment,
                /*
                 * Lo que el estratega no tiene que adivinar: si la persona ya
                 * quiere pagar (nada de descubrimiento), en qué modo está la
                 * relación, si ya hay con qué recomendar y qué se puede preguntar.
                 */
                'strategy_hints' => $strategyHints,
                'recent_messages' => $this->recentMessages($conversation),
                'knowledge_base' => $this->knowledge->groupedForPrompt(),
                'active_plans' => $this->plansWithoutPrice(),
                /*
                 * Hechos del gimnasio que el modelo puede afirmar: clases con día y
                 * hora, cuántos entrenadores y de qué (nunca quiénes), y horario de
                 * apertura solo si existe. Lo que el CRM no tiene va como
                 * SOURCE_NOT_AVAILABLE, para que se diga que el dato no está
                 * confirmado —no que lo confirmará alguien: prometer una persona
                 * que nadie autorizó es otra forma de mentir— y se siga con lo
                 * que sí existe. Los cupos no se afirman.
                 */
                'gym' => $this->gym->forPrompt(),
                /*
                 * La app, desde su código: qué hace de verdad, cómo se entra y se
                 * registra uno, y los enlaces oficiales (que envía Laravel, nunca el
                 * modelo). account.has_account es hecho del CRM.
                 */
                'app' => $this->appCatalog->forPrompt((bool) data_get($customer, 'known.has_app_account', false)),
                /*
                 * La membresía de quien escribe, como hecho del CRM y solo con
                 * identidad (lead.member_id): estado, plan, fecha de fin, días,
                 * asistencia y cobro automático; sin datos personales. Lo que el
                 * asistente no resuelve solo va en team_only (staff_review).
                 */
                'membership' => $membershipFacts,
                /*
                 * Cuál cotizar cuando la pregunta es genérica («¿cuánto vale?»).
                 *
                 * Sin esto ULTRON tiene que ADIVINARLO, y adivinar mal no es un
                 * detalle: hay cuatro planes activos de 30 días, así que la
                 * elección no se deduce de `active_plans`. El id sale de la
                 * misma regla de negocio que ya usa el cerebro local
                 * (`defaultMonthlyPlan`), para que no existan dos respuestas
                 * distintas a «cuánto vale» según quién conteste.
                 *
                 * Va el id, nunca el precio: ULTRON sigue sin ver una cifra.
                 */
                'default_plan_id' => $this->knowledge->defaultMonthlyPlan()?->id,
                'flags' => [
                    'can_offer_link' => $this->paymentReadiness->canGenerateAutomaticLink(),
                    'payment_readiness' => $this->paymentReadiness->state(),
                    'ultron_payment_links_enabled' => (bool) config('marketing.ultron.payment_links_enabled', false),
                ],
            ],
            'commercial_phase' => $phase,
            'allowed_transitions' => $transitions,
            'allowed_tools' => $tools,
            'knowledge_version' => $knowledgeVersion,
            'decide_token' => $token['token'],
            'expires_at' => $token['expires_at'],
        ];
    }

    /**
     * Últimos mensajes con el rol, el texto y la hora. Ni identificadores de
     * Meta, ni adjuntos, ni metadatos: ULTRON necesita el hilo, no el expediente.
     *
     * @return array<int, array{role:string, body:?string, at:?string}>
     */
    private function recentMessages(MarketingConversation $conversation): array
    {
        return $conversation->messages()
            ->latest('id')->limit(10)->get()
            ->map(fn (MarketingMessage $m) => [
                'role' => $m->sender_type,
                // Un mensaje de máquina con URL (el link de pago) no vuelve al modelo
                // ni al historial de n8n: la URL es pagable por quien la tenga.
                'body' => $this->redactedBody($m),
                'at' => optional($m->created_at)->toIso8601String(),
            ])->reverse()->values()->all();
    }

    /**
     * Planes activos SIN `price`.
     *
     * No es cosmético: es lo que hace imposible que ULTRON escriba una cifra
     * «de memoria». Elige un id; el precio lo pone Laravel al redactar.
     *
     * @return array<int, array<string,mixed>>
     */
    private function plansWithoutPrice(): array
    {
        return array_map(
            fn (array $p) => array_filter([
                'id' => $p['id'],
                'name' => $p['name'],
                'duration_days' => $p['duration_days'],
                'benefits' => $p['benefits'],
                // Lo que permite distinguir un plan de otro. Sin esto los
                // beneficios son idénticos dentro de cada gama y no hay con qué
                // recomendar: ULTRON acababa cotizando siempre el mismo.
                'tier' => $p['tier'] ?? null,
                'is_recommended' => $p['is_recommended'] ?? null,
                'badge' => $p['badge'] ?? null,
                'access_classes' => $p['access_classes'] ?? null,
                'restrictions' => $p['restrictions'] ?? null,
                // Rebaja REAL tomada de la fila, no una promoción inventada.
                // Va sin cifra: sólo dice que existe, y Laravel pone el número.
                'has_discount' => ! empty($p['original_price']),
            ], fn ($v) => $v !== null && $v !== []),
            $this->knowledge->activePlans(),
        );
    }

    /**
     * El menú de herramientas de este turno. `payment_link_send` solo entra cuando
     * Wompi está productivo Y el negocio autorizó el link automático
     * ({@see SalesPaymentReadinessService::canGenerateAutomaticLink()}): el modelo
     * no puede pedir lo que el sistema no puede cumplir.
     *
     * @return string[]
     */
    public function allowedTools(): array
    {
        return array_values(array_merge(self::V1_ALLOWED_TOOLS, $this->canOfferLink() ? [SalesIntents::TOOL_PAYMENT_LINK_SEND] : []));
    }

    /**
     * Lo que el modelo ve donde había una cifra.
     *
     * Deliberadamente NO es el marcador que Laravel sustituye
     * ({@see UltronDraftPlaceholders::PRICE}). Reutilizarlo parecía elegante
     * —el modelo lee lo mismo que puede escribir— y era un arma: «esto parece
     * un precio» es una heurística, así que un año, un teléfono o un NIT del
     * historial se convertirían en el precio REAL del plan en cuanto el modelo
     * copiara la frase. Un marcador que nadie resuelve no puede fabricar una
     * cifra: en el peor caso el texto queda raro, y eso se ve.
     */
    private const PRICE_MARK = '[precio]';

    /**
     * Un mensaje del historial tal y como PUEDE verlo el modelo.
     *
     * Lo que dice la PERSONA va intacto: «tengo 50.000» es el dato con el que se
     * la entiende, y sin él no hay forma de responderle. Lo que escribió la
     * MÁQUINA pierde dos cosas:
     *
     *  - la URL (el link de pago es pagable por quien lo tenga), y
     *  - la CIFRA, que es la que cierra la puerta de atrás: el precio entra en el
     *    mensaje DESPUÉS de validar ({@see UltronDraftPlaceholders}), sale por
     *    WhatsApp, queda guardado y volvía al modelo en el turno siguiente. Un
     *    modelo que lee su propia cifra la repite, y su propio guard de salida
     *    la rechaza (`machine_reply_invented_price`): la persona se quedaba sin
     *    respuesta ese turno por un número que le dimos nosotros.
     */
    private function redactedBody(MarketingMessage $m): ?string
    {
        if ($m->sender_type === MarketingMessage::SENDER_LEAD) {
            return $m->body;
        }

        if (OutboundContentGuard::containsUrl((string) $m->body)) {
            // El link de pago es pagable por quien lo tenga: el mensaje entero
            // desaparece, incluida la frase que lo anunciaba.
            if (data_get($m->metadata, 'kind') === 'payment_link') {
                return '[link de pago enviado]';
            }

            /*
             * Los enlaces de la app viajan DENTRO de la respuesta desde que
             * dejaron de salir en un mensaje aparte. Taparla entera le borraría
             * al modelo lo que acaba de decir —y un modelo que no recuerda su
             * último mensaje se repite—, así que se tapan sólo las URLs. Si la
             * limpieza no queda convincente, se cae al texto opaco de siempre.
             */
            $limpio = OutboundContentGuard::withoutUrls((string) $m->body);

            return $limpio === null ? '[enlace enviado por el CRM]' : $this->withoutPrices($limpio);
        }

        return $this->withoutPrices($m->body);
    }

    /**
     * El texto sin cifras de precio, todavía legible.
     *
     * «Esto parece un precio» se pregunta con la definición que YA existe
     * ({@see SalesAgentDecisionSchema::PRICE_PATTERN}, la misma del validador y
     * del guard de salida). Dos regex distintas para la misma pregunta son dos
     * reglas que se separan el día que cambie una, y la que se quedara corta
     * sería precisamente la fuga.
     */
    private function withoutPrices(?string $body): ?string
    {
        if ($body === null || ! SalesAgentDecisionSchema::containsPrice($body)) {
            return $body;
        }

        $masked = preg_replace(SalesAgentDecisionSchema::PRICE_PATTERN, self::PRICE_MARK, $body);

        if ($masked === null) {
            // Fail-closed: si la sustitución falla no se devuelve el original,
            // que es justo el texto con la cifra dentro.
            return '[mensaje del CRM]';
        }

        /*
         * El patrón detecta, no delimita: sobre «$80.000 COP» acierta tres veces
         * («$8», «0.000», «COP») y dejaría el marcador tartamudeando. El caso que
         * importa de verdad es el formato que el CRM genera para los planes de
         * siete cifras —«$1.770.000 COP», {@see formatCop()}—, donde los grupos de
         * miles quedan separados por el punto: sin juntar también por ahí, el
         * modelo leía «[precio].[precio]» y podía copiarlo tal cual.
         */
        $mark = preg_quote(self::PRICE_MARK, '/');

        return preg_replace('/'.$mark.'(?:[\s.,]*'.$mark.'|[.,]\d{3})+/', self::PRICE_MARK, $masked) ?? '[mensaje del CRM]';
    }
}
