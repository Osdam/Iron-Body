<?php

namespace App\Services\Marketing;

/**
 * En qué punto de la conversación comercial está esta persona, y adónde puede
 * ir desde ahí.
 *
 * Existe porque `lead_stage` no es una máquina de estados: se DERIVA de la
 * intención del último mensaje (`SalesLeadScoringService::leadStage()`), así que
 * alguien que ya estaba en `ready_to_pay` vuelve a `new` en cuanto escribe
 * «hola». Para el CRM eso da igual —es una etiqueta de color en una lista—,
 * pero un agente que decide qué decir a continuación no puede olvidar que la
 * persona ya dijo que quería pagar.
 *
 * Reglas de esta clase:
 *
 *  - PURA. Sin base de datos, sin side effects, sin config. Entra una fase y un
 *    contexto de hechos; sale una lista de fases.
 *  - Las transiciones son EXPLÍCITAS. Nada de «todo a todo»: una lista blanca
 *    que se puede leer y probar.
 *  - NO es lineal. Un cliente objeta después de que le recomienden, vuelve a
 *    contar su objetivo, compra de golpe o retoma una conversación de hace un
 *    mes. Todo eso está permitido; lo que no está permitido es retroceder sin
 *    motivo.
 *  - Tres salidas son alcanzables desde CUALQUIER fase, porque las tres las
 *    decide la persona y no el embudo: pedir que no le escriban, pedir un
 *    humano, y el cierre en negativo.
 *
 * `FOLLOW_UP` y `NURTURE` existen en el vocabulario y son alcanzables, pero en
 * la v1 nadie escribe primero: son un estado de espera, no una acción.
 */
final class CommercialPhaseMachine
{
    // ── Fases ─────────────────────────────────────────────────────────────────
    public const NEW_LEAD = 'NEW_LEAD';

    public const RAPPORT = 'RAPPORT';

    public const DISCOVERY = 'DISCOVERY';

    public const GOAL_DISCOVERY = 'GOAL_DISCOVERY';

    public const BARRIER_DISCOVERY = 'BARRIER_DISCOVERY';

    public const QUALIFICATION = 'QUALIFICATION';

    public const VALUE_BUILDING = 'VALUE_BUILDING';

    public const RECOMMENDATION = 'RECOMMENDATION';

    public const OBJECTION_HANDLING = 'OBJECTION_HANDLING';

    public const BUYING_SIGNAL = 'BUYING_SIGNAL';

    public const CLOSING = 'CLOSING';

    public const PAYMENT_HANDOFF = 'PAYMENT_HANDOFF';

    public const FOLLOW_UP = 'FOLLOW_UP';

    public const NURTURE = 'NURTURE';

    public const HUMAN_HANDOFF = 'HUMAN_HANDOFF';

    public const WON = 'WON';

    public const LOST = 'LOST';

    public const DO_NOT_CONTACT = 'DO_NOT_CONTACT';

    public const PHASES = [
        self::NEW_LEAD, self::RAPPORT, self::DISCOVERY, self::GOAL_DISCOVERY,
        self::BARRIER_DISCOVERY, self::QUALIFICATION, self::VALUE_BUILDING,
        self::RECOMMENDATION, self::OBJECTION_HANDLING, self::BUYING_SIGNAL,
        self::CLOSING, self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        self::HUMAN_HANDOFF, self::WON, self::LOST, self::DO_NOT_CONTACT,
    ];

    /**
     * Terminales de verdad: de aquí no se sale sola la conversación.
     *
     * `LOST` no está: alguien que dijo «ahora no» vuelve a escribir tres semanas
     * después, y tratar eso como irreversible sería perder al cliente por una
     * decisión de diseño.
     */
    public const TERMINAL = [self::DO_NOT_CONTACT, self::WON];

    /**
     * Alcanzables desde cualquier fase no terminal.
     *
     * Las decide la persona, no el embudo: pedir que no le escriban o cerrarse
     * en banda. Ninguna depende de por dónde iba la venta.
     *
     * HUMAN_HANDOFF NO está aquí. Estuvo, y eso lo hacía alcanzable en todos y
     * cada uno de los turnos: el menú que ve el modelo ofrecía «derivar» como
     * salida permanente, y el modelo la tomaba. Ahora es alcanzable solo cuando
     * `needs_human` lo dice, que es lo que este archivo llevaba prometiendo en
     * su propia documentación sin cumplirlo.
     */
    private const ALWAYS_REACHABLE = [
        self::DO_NOT_CONTACT,
        self::LOST,
    ];

    /**
     * Grafo explícito. Cada fase lista a dónde puede ir POR AVANCE natural de la
     * conversación; las tres salidas globales se añaden aparte.
     *
     * Quedarse en la misma fase siempre vale: dos mensajes seguidos explorando
     * el objetivo no son un error, son una conversación.
     *
     * @var array<string, string[]>
     */
    private const TRANSITIONS = [
        self::NEW_LEAD => [
            self::RAPPORT, self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY,
            self::QUALIFICATION, self::VALUE_BUILDING, self::RECOMMENDATION,
            self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF,
        ],
        self::RAPPORT => [
            self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION,
            self::VALUE_BUILDING, self::RECOMMENDATION, self::OBJECTION_HANDLING,
            self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF, self::FOLLOW_UP,
        ],
        self::DISCOVERY => [
            self::RAPPORT, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION,
            self::VALUE_BUILDING, self::RECOMMENDATION, self::OBJECTION_HANDLING,
            self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        self::GOAL_DISCOVERY => [
            self::DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION, self::VALUE_BUILDING,
            self::RECOMMENDATION, self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        self::BARRIER_DISCOVERY => [
            self::DISCOVERY, self::GOAL_DISCOVERY, self::QUALIFICATION, self::VALUE_BUILDING,
            self::RECOMMENDATION, self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        self::QUALIFICATION => [
            self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::VALUE_BUILDING,
            self::RECOMMENDATION, self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        self::VALUE_BUILDING => [
            self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION,
            self::RECOMMENDATION, self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        // Después de recomendar, lo normal es que objeten. Y que pregunten otra
        // cosa que no habían contado todavía.
        self::RECOMMENDATION => [
            self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION,
            self::VALUE_BUILDING, self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        self::OBJECTION_HANDLING => [
            self::BARRIER_DISCOVERY, self::GOAL_DISCOVERY, self::QUALIFICATION,
            self::VALUE_BUILDING, self::RECOMMENDATION, self::BUYING_SIGNAL, self::CLOSING,
            self::PAYMENT_HANDOFF, self::FOLLOW_UP, self::NURTURE,
        ],
        // Ya dijo que quiere. Volver a explorar el objetivo aquí es hacerle
        // perder el tiempo a quien ya decidió.
        self::BUYING_SIGNAL => [
            self::CLOSING, self::PAYMENT_HANDOFF, self::OBJECTION_HANDLING,
            self::RECOMMENDATION, self::FOLLOW_UP, self::WON,
        ],
        self::CLOSING => [
            self::PAYMENT_HANDOFF, self::OBJECTION_HANDLING, self::BUYING_SIGNAL,
            self::RECOMMENDATION, self::FOLLOW_UP, self::WON,
        ],
        self::PAYMENT_HANDOFF => [
            self::CLOSING, self::OBJECTION_HANDLING, self::FOLLOW_UP, self::WON,
        ],
        // Estados de espera: la persona vuelve a escribir y la conversación
        // retoma por donde tenga sentido.
        self::FOLLOW_UP => [
            self::RAPPORT, self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY,
            self::QUALIFICATION, self::VALUE_BUILDING, self::RECOMMENDATION,
            self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF,
            self::NURTURE, self::WON,
        ],
        self::NURTURE => [
            self::RAPPORT, self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY,
            self::QUALIFICATION, self::VALUE_BUILDING, self::RECOMMENDATION,
            self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF,
            self::FOLLOW_UP, self::WON,
        ],
        // La lleva una persona. Solo se sale cuando esa persona la devuelve, y
        // eso lo escribe el CRM, no el agente.
        self::HUMAN_HANDOFF => [
            self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY, self::QUALIFICATION,
            self::VALUE_BUILDING, self::RECOMMENDATION, self::OBJECTION_HANDLING,
            self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF, self::FOLLOW_UP,
            self::NURTURE, self::WON,
        ],
        // Perdido no es definitivo: vuelve a escribir y la conversación revive.
        self::LOST => [
            self::RAPPORT, self::DISCOVERY, self::GOAL_DISCOVERY, self::BARRIER_DISCOVERY,
            self::QUALIFICATION, self::VALUE_BUILDING, self::RECOMMENDATION,
            self::OBJECTION_HANDLING, self::BUYING_SIGNAL, self::CLOSING, self::PAYMENT_HANDOFF,
            self::FOLLOW_UP, self::NURTURE,
        ],
        self::WON => [],
        self::DO_NOT_CONTACT => [],
    ];

    /** Fase → etapa legada del CRM. Las dos conviven; esta no sustituye a aquella. */
    private const LEAD_STAGE_MAP = [
        self::NEW_LEAD => SalesIntents::LEAD_STAGE_NEW,
        self::RAPPORT => SalesIntents::LEAD_STAGE_NEW,
        self::DISCOVERY => SalesIntents::LEAD_STAGE_INFORMED,
        self::GOAL_DISCOVERY => SalesIntents::LEAD_STAGE_INFORMED,
        self::BARRIER_DISCOVERY => SalesIntents::LEAD_STAGE_INFORMED,
        self::QUALIFICATION => SalesIntents::LEAD_STAGE_INTERESTED,
        self::VALUE_BUILDING => SalesIntents::LEAD_STAGE_INTERESTED,
        self::RECOMMENDATION => SalesIntents::LEAD_STAGE_INTERESTED,
        self::OBJECTION_HANDLING => SalesIntents::LEAD_STAGE_INTERESTED,
        self::BUYING_SIGNAL => SalesIntents::LEAD_STAGE_READY_TO_PAY,
        self::CLOSING => SalesIntents::LEAD_STAGE_READY_TO_PAY,
        self::PAYMENT_HANDOFF => SalesIntents::LEAD_STAGE_READY_TO_PAY,
        self::FOLLOW_UP => SalesIntents::LEAD_STAGE_INTERESTED,
        self::NURTURE => SalesIntents::LEAD_STAGE_INFORMED,
        self::HUMAN_HANDOFF => SalesIntents::LEAD_STAGE_NEEDS_HUMAN,
        self::WON => SalesIntents::LEAD_STAGE_READY_TO_PAY,
        self::LOST => SalesIntents::LEAD_STAGE_LOST,
        self::DO_NOT_CONTACT => SalesIntents::LEAD_STAGE_LOST,
    ];

    /** Barreras que el agente puede reconocer. Lista cerrada: se cuentan. */
    public const BARRIERS = [
        'price', 'time', 'location', 'beginner_fear', 'body_insecurity',
        'motivation', 'previous_bad_experience', 'not_ready', 'other',
    ];

    public function isPhase(?string $phase): bool
    {
        return $phase !== null && in_array($phase, self::PHASES, true);
    }

    public function isBarrier(?string $barrier): bool
    {
        return $barrier !== null && in_array($barrier, self::BARRIERS, true);
    }

    /** Fase de arranque cuando la conversación todavía no tiene ninguna. */
    public function initial(): string
    {
        return self::NEW_LEAD;
    }

    /** Una fase desconocida o nula se trata como el principio, no como un error. */
    public function normalize(?string $phase): string
    {
        return $this->isPhase($phase) ? (string) $phase : $this->initial();
    }

    /**
     * A dónde puede ir esta conversación desde donde está.
     *
     * El `$context` son HECHOS que ya decidió Laravel, no opiniones del modelo:
     *
     *   do_not_contact  el lead pidió que no le escriban → única salida.
     *   human_takeover  la lleva una persona → no se avanza la venta sola.
     *   needs_human     Laravel autorizó la derivación → HUMAN_HANDOFF es un
     *                   destino legal. Sin ese hecho NO lo es: es la única
     *                   fase que el modelo no puede alcanzar por su cuenta.
     *   can_offer_link  sin permiso de link automático, PAYMENT_HANDOFF no es
     *                   un destino válido: no hay nada que entregar.
     *
     * @param  array<string,mixed>  $context
     * @return string[] siempre incluye la fase actual (quedarse quieto es legal)
     */
    public function allowedTransitions(?string $currentPhase, array $context = []): array
    {
        $current = $this->normalize($currentPhase);

        // Quien pidió que no le escriban no tiene más recorrido.
        if ($current === self::DO_NOT_CONTACT || ($context['do_not_contact'] ?? false)) {
            return [self::DO_NOT_CONTACT];
        }

        // Con una persona al mando, el agente no mueve la venta.
        if ($context['human_takeover'] ?? false) {
            return array_values(array_unique([self::HUMAN_HANDOFF, $current]));
        }

        if ($current === self::WON) {
            return [self::WON];
        }

        $allowed = array_merge(
            [$current],                       // quedarse es siempre legal
            self::TRANSITIONS[$current] ?? [],
            self::ALWAYS_REACHABLE,
            /*
             * Derivar a una persona no es una fase del embudo: es una excepción
             * que autoriza Laravel. Sin `needs_human` no es un destino, y el
             * menú que recibe el modelo no la menciona siquiera.
             */
            ($context['needs_human'] ?? false) === true ? [self::HUMAN_HANDOFF] : [],
        );

        /*
         * Sin permiso para ofrecer un link, el traspaso a pago deja de ser un
         * destino: la fase describiría algo que el sistema no puede hacer.
         *
         * Salvo que la conversación YA esté ahí. El permiso se apaga con un
         * flag, y en ese instante puede haber conversaciones en esa fase; si el
         * filtro se las llevara por delante se quedarían sin ninguna transición
         * legal, ni siquiera quedarse quietas. Quedarse quieto siempre vale.
         */
        if (($context['can_offer_link'] ?? false) !== true && $current !== self::PAYMENT_HANDOFF) {
            $allowed = array_filter($allowed, fn ($p) => $p !== self::PAYMENT_HANDOFF);
        }

        // WON solo se alcanza desde el tramo de cierre. Nadie «gana» una venta
        // desde una pregunta de ubicación.
        if (! in_array(self::WON, self::TRANSITIONS[$current] ?? [], true)) {
            $allowed = array_filter($allowed, fn ($p) => $p !== self::WON);
        }

        return array_values(array_unique($allowed));
    }

    /** ¿Es legal ir de aquí a allí con estos hechos? */
    public function canTransition(?string $from, ?string $to, array $context = []): bool
    {
        return $this->isPhase($to)
            && in_array($to, $this->allowedTransitions($from, $context), true);
    }

    /**
     * La transición, o la fase actual si la propuesta no es legal.
     *
     * Devolver la actual y no lanzar es deliberado para los llamadores que
     * prefieren continuar; quien necesite rechazar la petición usa
     * `canTransition()` y decide él. El endpoint de commit hace lo segundo.
     */
    public function transition(?string $from, ?string $to, array $context = []): string
    {
        $current = $this->normalize($from);

        return $this->canTransition($current, $to, $context) ? (string) $to : $current;
    }

    /** Etapa legada equivalente, para que el CRM de Mercadeo siga funcionando. */
    public function toLeadStage(?string $phase): string
    {
        return self::LEAD_STAGE_MAP[$this->normalize($phase)] ?? SalesIntents::LEAD_STAGE_NEW;
    }
}
