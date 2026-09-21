<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;

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

        return $memory;
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
            $core = trim(preg_replace('/^plan\s+/u', '', SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''))) ?? '');
            if ($core !== '' && preg_match('/\b'.preg_quote($core, '/').'\b/u', $reply) === 1) {
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

        $m->touch($at);
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
        foreach (Plan::query()->sellable()->orderBy('duration_days')->get() as $p) {
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

        $conversation->forceFill(['memory' => ConversationMemory::fromArray($d)->toArray()])->save();
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
