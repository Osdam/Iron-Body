<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Carga y actualiza la {@see ConversationMemory} de una conversación.
 *
 * La actualización ocurre en commit, con el texto que DE VERDAD salió y el
 * plan real que se cotizó: la memoria registra lo entregado, no lo propuesto.
 * La carga en decide es pura (sin escrituras) y añade lo que se puede derivar
 * de los mensajes sin guardarlo: la pregunta del usuario que sigue sin
 * respuesta porque ningún mensaje nuestro la siguió.
 */
final class ConversationMemoryService
{
    /** Preguntas que el CRM sabe que ya respondió, por intención. */
    private const INTENT_TO_FACT = [
        SalesIntents::LOCATION_QUESTION => 'location',
        SalesIntents::PRICING_QUESTION => 'price',
        SalesIntents::SCHEDULE_QUESTION => 'schedule',
    ];

    public const GOAL_COURTESY = 'courtesy_request';

    public const GOAL_COLLECTING = 'collecting';

    public const GOAL_REQUESTED = 'requested';

    /** Horas que una visita a medio concretar sigue siendo el tema de la conversación. */
    public const GOAL_COLLECTING_HOURS = 24;

    private const OFERTAS = [
        'explain_how_to_start' => '/(como (empezar|iniciar|seguir|inscribirte|arrancar)|los pasos|siguiente paso)/u',
        'send_payment_link' => '/(link|enlace).{0,20}(pago|pagar)|pago.{0,20}(link|enlace)/u',
        'book_visit' => '/(visita|valoracion|cita|agendar|agendemos|pases a conocer)/u',
        'plan_details' => '/(detalles|te cuente mas|te explique (los|las) (planes|opciones)|te cuente sobre|te cuente como|te explique como|profundi)/u',
        'plan_recommendation' => '/(te recomiende|te recomiendo|que plan|cual plan|opciones)/u',
    ];

    public function __construct(
        private readonly MarketingKnowledgeBaseService $knowledge,
    ) {}

    public function load(MarketingConversation $conversation): ConversationMemory
    {
        $memory = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null);

        // Derivado, no guardado: la última pregunta de la persona a la que no
        // siguió ningún mensaje nuestro sigue sin responder.
        $memory = $this->withUnresolvedQuestion($conversation, $memory);

        return $this->withoutStaleGoal($memory);
    }

    /**
     * Un objetivo en recogida de datos caduca solo.
     *
     * «Te faltaba decirme la hora» tiene sentido al minuto siguiente y es
     * absurdo tres días después: quien vuelve tras un fin de semana no está
     * retomando una visita, está empezando otra vez. Se decide al LEER y no
     * se persiste, igual que la pregunta sin resolver: el hecho sigue en la
     * fila por si hace falta auditarlo, pero el turno no lo ve.
     */
    private function withoutStaleGoal(ConversationMemory $memory): ConversationMemory
    {
        $goal = $memory->activeGoal();
        if ($goal === null || $goal['status'] !== self::GOAL_COLLECTING || $goal['since'] === null) {
            return $memory;
        }

        try {
            $desde = Carbon::parse($goal['since']);
        } catch (Throwable) {
            // Una fecha ilegible no es un objetivo vigente ni caducado: se deja como está.
            return $memory;
        }

        if ($desde->diffInHours(now()) < self::GOAL_COLLECTING_HOURS) {
            return $memory;
        }

        $data = $memory->toArray();
        $data['active_goal'] = null;

        return ConversationMemory::fromArray($data);
    }

    /**
     * Registra un turno cuya respuesta SALIÓ.
     *
     * @param  array<string,mixed>  $resolution  lo que decidió {@see ReferenceResolver} para este mensaje
     */
    public function recordSentTurn(
        MarketingConversation $conversation,
        MarketingMessage $inbound,
        string $replyFinal,
        ?Plan $plan,
        string $intent,
        array $resolution,
        ?int $outboundMessageId,
        bool $esUltimoRecurso = false,
    ): ConversationMemory {
        $m = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null);
        $at = now()->toIso8601String();
        $mid = (int) ($outboundMessageId ?? 0);
        $reply = SalesAgentDecisionSchema::normalize($replyFinal);

        // Lo que la persona preguntó y a qué se refería. Redactado: la memoria
        // vive más que la ventana de mensajes y el modelo la lee cada turno.
        $m->userAsked(MemoryRedactor::lead($this->questionIn((string) $inbound->body)), $at, (int) $inbound->id);
        $m->referenceResolved($resolution);
        if (in_array($resolution['type'] ?? null, [ReferenceResolver::ACCEPT_OFFER, ReferenceResolver::DECLINE_OFFER], true)) {
            $m->offerResolved();
        }
        if (($resolution['type'] ?? null) === ReferenceResolver::CHOOSE_PLAN && ($resolution['plan_id'] ?? null) !== null) {
            $m->commitTo((int) $resolution['plan_id'], $at);
        }
        if (in_array($intent, [SalesIntents::HIGH_INTENT_CLOSE, SalesIntents::PAYMENT_LINK_REQUEST], true) && $plan !== null) {
            $m->commitTo($plan->id, $at);
        }

        // Lo que se entregó de verdad.
        if ($plan !== null) {
            if (preg_match('/\$\s?\d|\d{2,3}[.,]\d{3}|cop\b/u', $reply) === 1) {
                $m->deliverPrice($plan->id, $at, $mid);
            }
            foreach ($plan->benefitsArray() as $benefit) {
                if ($this->mentions($reply, (string) $benefit)) {
                    $m->deliverBenefit($plan->id, (string) $benefit, $at);
                }
            }
            $m->discussPlan($plan->id);
            if (preg_match('/(te recomiendo|te recomendaria|lo que mejor te (sirve|encaja)|el ideal para ti)/u', $reply) === 1) {
                $m->recommend([$plan->id], $at);
            }
        }
        foreach ($this->knowledge->activePlans() as $p) {
            $full = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));
            $core = trim(preg_replace('/^plan\s+/u', '', $full) ?? '');
            if ($core === '') {
                continue;
            }
            /*
             * Un nombre que también es palabra del calendario sólo cuenta
             * ESCRITO ENTERO. «¿Qué días tienes disponibles en la semana?»
             * anotaba el Plan Semana como discutido; a partir de ahí era el
             * plan pendiente, la novedad ofrecía sus beneficios como «lo
             * fresco» y el redactor lo nombró. Así entró «Plan Semana» en una
             * conversación donde nadie había hablado de planes.
             */
            $patron = ReferenceResolver::isCalendarWord($core) ? $full : $core;
            if (preg_match('/\b'.preg_quote($patron, '/').'\b/u', $reply) === 1) {
                $m->discussPlan((int) $p['id']);
            }
        }
        foreach ($this->knowledge->activeItems() as $item) {
            $content = SalesAgentDecisionSchema::normalize(trim((string) $item->content));
            if (mb_strlen($content) >= 12 && str_contains($reply, mb_substr($content, 0, min(25, mb_strlen($content))))) {
                $m->deliverFact('kb:'.$item->category.':'.$item->id, $at, $mid);
                if ($item->category === 'location') {
                    $m->deliverFact('location', $at, $mid);
                }
            }
        }
        if (preg_match('/\b(\d+|dos|tres|cuatro|cinco|seis)\s+entrenador/u', $reply) === 1) {
            $m->deliverFact('trainers_count', $at, $mid);
        }
        if (preg_match('/(para empezar|para iniciar|el primer paso|los pasos|vienes con|llegas y|te inscribes)/u', $reply) === 1) {
            $m->deliverFact('how_to_start', $at, $mid);
        }
        if (isset(self::INTENT_TO_FACT[$intent])) {
            $m->answerQuestion(self::INTENT_TO_FACT[$intent], $at);
        }
        if (in_array($intent, [SalesIntents::PRICE_OBJECTION, SalesIntents::DELAY_OBJECTION], true)) {
            $m->seeObjection($intent, $at);
        }

        // Lo que el agente dejó abierto: su última pregunta y, si era una oferta,
        // cuál. Sin cifras: el texto final lleva el precio puesto y el modelo no
        // debe volver a verlo por esta vía.
        $question = $this->lastQuestionIn($replyFinal);
        $offerKind = $question === null ? null : $this->offerKindOf($question);

        /*
         * UN TEXTO DE RELLENO NO BORRA LO QUE SÍ SE OFRECIÓ.
         *
         * El último recurso es lo que sale cuando el texto del modelo no puede
         * salir: pregunta qué necesitas y no ofrece nada. Sin esta excepción
         * pisaba la oferta viva —`agentAsked` sobreescribe siempre— y un «sí,
         * por favor» posterior dejaba de resolverse contra «te explico cómo
         * empezar». Lo cazó el arco de memoria, que guarda un incidente real.
         *
         * La pregunta sí se anota: es la última que hizo el agente. Lo que no
         * se toca es la OFERTA.
         */
        if ($esUltimoRecurso && $offerKind === null && $m->get('last_agent_offer') !== null) {
            $m->touch($at);
            $conversation->forceFill(['memory' => $m->toArray()])->save();

            return $m;
        }

        $m->agentAsked(MemoryRedactor::agent($question), $offerKind, $plan?->id ?? $m->pendingPlanId(), $at, $mid);

        /*
         * OFRECER LA VISITA ABRE UN OBJETIVO; DECIR QUE NO LO CIERRA.
         *
         * `last_agent_offer` no sirve para esto: la siguiente pregunta del
         * agente la pisa. Así se perdió la cortesía en la prueba física —se
         * ofreció, la persona dio el día, y el turno siguiente vendió un plan
         * porque para entonces la oferta viva era otra—. El objetivo dura
         * hasta que se registra, se rechaza o caduca.
         */
        if ($offerKind === 'book_visit' && ! $m->hasActiveGoal(self::GOAL_COURTESY)) {
            $conocido = (array) ($m->get('courtesy_request') ?? []);
            $m->setActiveGoal(self::GOAL_COURTESY, self::GOAL_COLLECTING, [
                'date' => $conocido['date'] ?? null,
                'time' => $conocido['time'] ?? null,
            ], $at);
        }
        if ($m->hasActiveGoal(self::GOAL_COURTESY, self::GOAL_COLLECTING)
            && (($resolution['type'] ?? null) === ReferenceResolver::DECLINE_OFFER
                || CommercialTurnPolicy::declinesVisit((string) $inbound->body))) {
            $m->clearActiveGoal();
        }

        /*
         * Ya saludamos. Esto es un HECHO del CRM, no una instrucción.
         *
         * El prompt pedía no repetir el saludo y el segundo turno de una
         * prueba física volvió a abrir con «Buenas tardes»: no había nada que
         * consultar. Se marca en cuanto sale el primer mensaje nuestro, salga
         * con saludo o sin él, porque a partir de ahí la conversación ya
         * empezó.
         */
        if ($outboundMessageId !== null) {
            $m->greeted($at, $outboundMessageId, true);
        }

        $m->touch($at);
        $conversation->forceFill(['memory' => $m->toArray()])->save();

        return $m;
    }

    /**
     * La persona dijo que no a un plan: queda anotado para no insistir.
     *
     * «Menciona otro plan» y «está muy caro» son un no a ESE plan, y volver a
     * ofrecérselo en el turno siguiente es no haber escuchado.
     */
    public function recordPlanRejected(MarketingConversation $conversation, int $planId): ConversationMemory
    {
        $m = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null);
        $m->rejectPlan($planId)->touch(now()->toIso8601String());
        $conversation->forceFill(['memory' => $m->toArray()])->save();

        return $m;
    }

    /** Un turno sin respuesta nuestra deja constancia de a qué se refería la persona. */
    public function recordSilentTurn(MarketingConversation $conversation, MarketingMessage $inbound, array $resolution): ConversationMemory
    {
        $m = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null);
        $at = now()->toIso8601String();
        $m->userAsked(MemoryRedactor::lead($this->questionIn((string) $inbound->body)), $at, (int) $inbound->id);
        $m->referenceResolved($resolution)->touch($at);
        $conversation->forceFill(['memory' => $m->toArray()])->save();

        return $m;
    }

    /**
     * Planes vendibles con nombre y beneficios: la forma que consumen el
     * resolutor y la guía de novedad. Sin precios.
     *
     * @return array<int, array{id:int, name:string, benefits:string[]}>
     */
    public function sellablePlansForMemory(): array
    {
        $out = [];
        /*
         * EN EL ORDEN DEL NEGOCIO, no por duración.
         *
         * Por duración, el primero era el Plan Semana (siete días), y la guía
         * de novedad tomaba sus beneficios como «lo fresco que contar» desde
         * el primer turno, sin que nadie hubiera hablado de planes. Así llegó
         * «Plan Semana» a la boca del redactor en una prueba física. El
         * insignia va primero, igual que en `activePlans()`.
         */
        foreach (Plan::query()->sellable()->orderByDesc('is_recommended')->orderBy('sort_order')->get() as $p) {
            $out[] = ['id' => (int) $p->id, 'name' => (string) $p->name, 'benefits' => array_values($p->benefitsArray())];
        }

        return $out;
    }

    private function withUnresolvedQuestion(MarketingConversation $conversation, ConversationMemory $memory): ConversationMemory
    {
        $recent = $conversation->messages()->latest('id')->limit(6)->get()->reverse()->values();
        $pending = null;
        foreach ($recent as $msg) {
            if ($msg->direction === MarketingMessage::DIRECTION_INBOUND) {
                $q = MemoryRedactor::lead($this->questionIn((string) $msg->body));
                if ($q !== null) {
                    $pending = ['source' => 'lead_verbatim', 'text' => $q, 'at' => optional($msg->created_at)->toIso8601String(), 'message_id' => (int) $msg->id];
                }
            } elseif ($msg->sender_type === MarketingMessage::SENDER_AI || $msg->sender_type === MarketingMessage::SENDER_HUMAN) {
                $pending = null;
            }
        }

        $data = $memory->toArray();
        $data['unresolved_question'] = $pending;

        return ConversationMemory::fromArray($data);
    }

    /** El texto es una pregunta si lleva «?» o empieza como una. */
    private function questionIn(string $text): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return null;
        }
        $n = SalesAgentDecisionSchema::normalize($t);
        if (str_contains($t, '?') || preg_match('/^(que|cual|cuales|cuanto|cuantos|cuanta|cuantas|como|donde|cuando|quien|tienes|tienen|hay|puedo|se puede|me (puedes|podrias))\b/u', $n) === 1) {
            return mb_substr($t, 0, 200);
        }

        return null;
    }

    private function lastQuestionIn(string $reply): ?string
    {
        // «…te queda en [precio], ¿quieres que te explique cómo empezar?» son
        // dos frases: la pregunta empieza en el «¿».
        $sentences = preg_split('/(?<=[.!?])\s+|,\s*(?=¿)/u', trim($reply)) ?: [];
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $s = trim($sentences[$i]);
            if ($s !== '' && str_ends_with($s, '?')) {
                return mb_substr($s, 0, 200);
            }
        }

        return null;
    }

    /**
     * Anota la solicitud de cortesía en la memoria de la conversación.
     *
     * El dato nace de un EFECTO —la fila que se acaba de escribir—, no de
     * leer el texto, así que sigue el patrón de `recordPaymentLink()`: cargar,
     * tocar una sola clave y guardar. Sirve para dos cosas concretas: no
     * volver a preguntar un día que la persona ya dijo, y saber que hay algo
     * vivo cuando diga «mejor el domingo» o «cancélalo».
     *
     * @param  array<string,mixed>  $datos
     */
    public function recordCourtesyRequest(MarketingConversation $conversation, array $datos): void
    {
        $m = $this->load($conversation);
        $d = $m->toArray();
        $d['courtesy_request'] = $datos;

        $m = ConversationMemory::fromArray($d);
        $m->setActiveGoal(self::GOAL_COURTESY, self::GOAL_REQUESTED, [
            'date' => $datos['date'] ?? null,
            'time' => $datos['time'] ?? null,
            'action_id' => $datos['action_id'] ?? null,
        ], (string) ($datos['at'] ?? now()->toIso8601String()));

        $conversation->forceFill(['memory' => $m->toArray()])->save();
    }

    /**
     * La visita se ofreció o se pidió, pero todavía faltan el día o la hora.
     *
     * Es el estado que no existía: la herramienta se pedía sin hora, la
     * autoridad la rechazaba —correcto— y de ese rechazo no quedaba nada. El
     * turno siguiente no tenía forma de saber que había una visita a medio
     * concretar, y se ponía a vender. Lo que ya se sabe (el día, si lo dio)
     * se guarda para no volver a preguntarlo.
     *
     * @param  array{date?:?string,time?:?string}  $conocido
     */
    public function recordCourtesyCollecting(MarketingConversation $conversation, array $conocido): void
    {
        $m = $this->load($conversation);
        $previo = $m->activeGoal();
        $data = array_merge(
            $previo !== null && $previo['kind'] === self::GOAL_COURTESY ? $previo['data'] : [],
            array_filter(['date' => $conocido['date'] ?? null, 'time' => $conocido['time'] ?? null]),
        );
        $m->setActiveGoal(self::GOAL_COURTESY, self::GOAL_COLLECTING, $data, now()->toIso8601String());

        $conversation->forceFill(['memory' => $m->toArray()])->save();
    }

    /** Cancelar la visita cierra el objetivo; la fila de la solicitud queda, cancelada, para auditar. */
    public function recordCourtesyCancelled(MarketingConversation $conversation): void
    {
        $m = $this->load($conversation);
        $d = $m->toArray();
        if (is_array($d['courtesy_request'] ?? null)) {
            $d['courtesy_request']['status'] = 'cancelled';
        }
        $m = ConversationMemory::fromArray($d)->clearActiveGoal();

        $conversation->forceFill(['memory' => $m->toArray()])->save();
    }

    private function offerKindOf(string $question): ?string
    {
        $q = SalesAgentDecisionSchema::normalize($question);
        if (preg_match('/\b(quieres|te gustaria|te interesa|prefieres|deseas|te parece)\b/u', $q) !== 1) {
            return null;
        }
        foreach (self::OFERTAS as $kind => $rx) {
            if (preg_match($rx, $q) === 1) {
                return $kind;
            }
        }

        return 'generic_offer';
    }

    private function mentions(string $normalizedReply, string $benefit): bool
    {
        $b = SalesAgentDecisionSchema::normalize(trim($benefit));
        if (mb_strlen($b) < 6) {
            return false;
        }
        // Los beneficios se redactan a mano: basta con que aparezcan sus dos
        // primeras palabras significativas seguidas.
        $words = array_values(array_filter(preg_split('/\s+/u', $b) ?: [], fn ($w) => mb_strlen($w) >= 4));
        if (count($words) >= 2) {
            return str_contains($normalizedReply, $words[0].' '.$words[1]) || str_contains($normalizedReply, $b);
        }

        return str_contains($normalizedReply, $b);
    }

    /**
     * El motor de pagos deja constancia del link vivo: referencia, plan, vigencia y
     * cuándo salió. Nunca la URL: esa vive en la transacción.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordPaymentLink(MarketingConversation $conversation, array $context): void
    {
        $data = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null)->toArray();
        $data['payment_context'] = array_filter($context, fn ($v) => $v !== null) + ['updated_at' => now()->toIso8601String()];
        $conversation->forceFill(['memory' => ConversationMemory::fromArray($data)->toArray()])->save();
    }

    /** El pago cambió de estado (aprobado, rechazado, vencido): la memoria lo sabe antes del siguiente turno. */
    public function recordPaymentOutcome(MarketingConversation $conversation, string $outcome, string $reference): void
    {
        $data = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null)->toArray();
        $ctx = is_array($data['payment_context'] ?? null) ? $data['payment_context'] : [];
        $data['payment_context'] = array_filter($ctx + ['reference' => $reference], fn ($v) => $v !== null) + ['status' => $outcome, $outcome.'_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];
        $conversation->forceFill(['memory' => ConversationMemory::fromArray($data)->toArray()])->save();
    }

    /** Los enlaces de la app ya se enviaron: el siguiente turno no los vuelve a anunciar como novedad. */
    public function recordAppLinksSent(MarketingConversation $conversation): void
    {
        $data = ConversationMemory::fromArray(is_array($conversation->memory) ? $conversation->memory : null)->toArray();
        $ctx = is_array($data['app_context'] ?? null) ? $data['app_context'] : [];
        // array_merge y no `+`: la unión de arrays de PHP conserva la clave
        // existente, y la marca quedaba congelada en el primer envío; el freno
        // de execAppLinks la lee y solo frenaba durante la primera hora.
        $data['app_context'] = array_merge($ctx, ['links_sent_at' => now()->toIso8601String()]);
        $conversation->forceFill(['memory' => ConversationMemory::fromArray($data)->toArray()])->save();
    }
}
