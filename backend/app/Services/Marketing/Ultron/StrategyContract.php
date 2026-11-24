<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;

/**
 * Lo que el Strategist puede PROPONER, y lo que Laravel le adelanta para que
 * no tenga que adivinarlo.
 *
 * El estratega decide estrategia y lenguaje; nunca precio, vendibilidad,
 * estado de pago, hechos ni derivación final. Por eso su contrato son enums
 * cerrados y booleanos de oportunidad: propuestas que el backend puede
 * validar, contrastar con los hechos y persistir para auditarlas después.
 */
final class StrategyContract
{
    public const CONVERSATION_GOALS = ['understand', 'recommend', 'resolve_objection', 'close', 'collect_payment', 'onboard', 'support', 'recover', 'escalate', 'wait'];

    public const BUYING_SIGNALS = ['none', 'weak', 'strong'];

    public const RECOMMENDATION_GOALS = ['none', 'single_plan', 'compare_plans'];

    public const LIFECYCLE_MODES = ['consultative', 'closing', 'payment_pending', 'onboarding', 'support', 'winback'];

    /** Lo único que se puede preguntar: nombres de campos desconocidos, nunca texto libre. */
    public const ASKABLE = ['objective', 'experience_level', 'time_constraints', 'class_interest', 'main_barrier', 'budget_signal'];

    private const BOOLEANS = ['question_needed', 'closing_opportunity', 'payment_opportunity', 'app_support_opportunity'];

    /** Campos de propuesta del Strategist que el commit acepta y persiste. */
    public const PROPOSAL_FIELDS = ['conversation_goal', 'information_needed', 'question_needed', 'response_goal', 'buying_signal', 'closing_opportunity', 'payment_opportunity', 'app_support_opportunity', 'recommendation_goal'];

    /** Preguntas de descubrimiento que a quien ya quiere pagar le sobran. */
    private const PREGUNTA_DESCUBRIMIENTO = '/\b(cual es tu (objetivo|meta)|que (buscas|quieres lograr|te gustaria lograr|te motiva)|has entrenado antes|tienes experiencia|cuantos dias (a la semana|puedes)|que horario te (queda|sirve)|cual es tu presupuesto|cuantos anos tienes|que edad tienes|por que quieres)\b/u';

    /**
     * Las mismas preguntas, DE USTED (cierre V2, apartado 4, H4-9; añadido del
     * lead del 19-11). Solo se miran con la versión en «profesional», y solo
     * DENTRO de una pregunta: de usted coinciden con la tercera persona («el
     * equipo tiene experiencia», «lo que busca la gente»), y de tú no hay esa
     * ambigüedad. Sin ese tono, el detector es exactamente el de antes.
     */
    private const PREGUNTA_DESCUBRIMIENTO_DE_USTED = '/\b(cual es su (objetivo|meta)|que (busca|quiere lograr|le gustaria lograr|le motiva)|ha entrenado antes|tiene experiencia|cuantos dias (a la semana|puede)|que horario le (queda|sirve)|cual es su presupuesto|cuantos anos tiene|que edad tiene|por que quiere)\b/u';

    /**
     * Pistas deterministas para el estratega, derivadas del perfil (PUNTO 2)
     * y del referente (PUNTO 1). Laravel las calcula; el modelo las sigue.
     *
     * @param  array<string,mixed>  $customer
     * @param  array<string,mixed>  $resolved
     * @return array<string,mixed>
     */
    public static function hints(array $customer, array $resolved, bool $canOfferLink, ?string $baseIntent = null, array $payment = [], array $membership = [], bool $greetingOnly = false, bool $memoryOnly = false): array
    {
        /*
         * MODO RECEPCIÓN: un saludo y nada más se contesta recibiendo.
         *
         * Con la memoria comercial del lead (READY_TO_BUY, hot lead) estas
         * pistas empujaban a cerrar o recomendar ante un «hola» a secas; el
         * Critic tumbaba el borrador por vender ante un saludo y salía el
         * respaldo genérico. La memoria no se borra —sigue en `customer`—,
         * pero no decide ESTE turno: primero se recibe, después se entiende.
         */
        if ($greetingOnly) {
            return array_merge(self::base($customer, $resolved, $canOfferLink, $baseIntent, $payment, $membership), [
                'hot_lead_fast_path' => false,
                'fast_path_kind' => null,
                'lifecycle_mode' => 'consultative',
                'should_recommend_now' => false,
                'open_objection' => false,
                'may_ask' => [],
                'question_budget' => 1,
                'suggested_question' => null,
                'greeting_only' => true,
                'memory_only' => false,
            ]);
        }

        /*
         * MODO MEMORIA: «¿a qué hora era mi visita?» se resuelve y se espera.
         *
         * Es el mismo fallo que el saludo: con un lead READY las pistas
         * empujaban a cerrar ante una pregunta de memoria, el estratega fijaba
         * el plan, el redactor vendía y la política tumbaba el borrador. Ni vía
         * rápida, ni recomendación, ni pregunta de descubrimiento en este turno.
         */
        if ($memoryOnly) {
            return array_merge(self::base($customer, $resolved, $canOfferLink, $baseIntent, $payment, $membership), [
                'hot_lead_fast_path' => false,
                'fast_path_kind' => null,
                'lifecycle_mode' => 'support',
                'should_recommend_now' => false,
                'open_objection' => false,
                'may_ask' => [],
                'question_budget' => 0,
                'suggested_question' => null,
                'greeting_only' => false,
                'memory_only' => true,
            ]);
        }

        return array_merge(self::base($customer, $resolved, $canOfferLink, $baseIntent, $payment, $membership), ['greeting_only' => false, 'memory_only' => false]);
    }

    /**
     * La pregunta adaptativa, sin tildes porque cruza al modelo. Null cuando
     * ya se sabe lo suficiente: entonces se recomienda, no se pregunta.
     *
     * @param  string[]  $mayAsk
     */
    public static function preguntaAdaptativa(?string $objective, ?string $experience, ?string $availability, array $mayAsk): ?string
    {
        if ($objective === null && in_array('objective', $mayAsk, true)) {
            return match ($experience) {
                'experienced', 'advanced' => 'vienes buscando mantener tu rendimiento o cambiar tu composicion corporal?',
                // Sin «te gustaría»: el detector de ofertas lo registra como una oferta y pisa la viva.
                'beginner' => 'que buscas lograr al empezar: bajar grasa, ganar masa o coger el habito?',
                default => 'seria tu primera vez entrenando o ya tienes experiencia?',
            };
        }
        if ($experience === null && in_array('experience_level', $mayAsk, true)) {
            return 'seria tu primera vez entrenando o ya vienes con experiencia?';
        }
        if ($availability === null && in_array('time_constraints', $mayAsk, true)) {
            return 'que dias o en que franja te queda mejor entrenar?';
        }

        return null;
    }

    /**
     * LAS CINCO PREGUNTAS, DE USTED (cierre V2, apartado 4, H4-9; plan del
     * arquitecto §2.3, decisión T-5 del lead). Con la versión ofrecida en
     * «profesional», `decide` sustituye la pregunta sugerida por su variante:
     * se le pasaba al modelo ya redactada de tú («seria tu primera vez…?»)
     * con la orden de decirla con sus palabras, y eso empujaba hacia el
     * tuteo. Sin tildes, como las de arriba: cruzan al modelo. Las mismas
     * cinco, en el mismo orden que {@see self::preguntaAdaptativa()}.
     */
    public const PREGUNTAS_DE_USTED = [
        'vienes buscando mantener tu rendimiento o cambiar tu composicion corporal?' => 'viene buscando mantener su rendimiento o cambiar su composicion corporal?',
        'que buscas lograr al empezar: bajar grasa, ganar masa o coger el habito?' => 'que busca lograr al empezar: bajar grasa, ganar masa o coger el habito?',
        'seria tu primera vez entrenando o ya tienes experiencia?' => 'seria su primera vez entrenando o ya tiene experiencia?',
        'seria tu primera vez entrenando o ya vienes con experiencia?' => 'seria su primera vez entrenando o ya viene con experiencia?',
        'que dias o en que franja te queda mejor entrenar?' => 'que dias o en que franja le queda mejor entrenar?',
    ];

    /**
     * Las pistas con la pregunta sugerida de usted si el tono ofrecido en este
     * turno es «profesional»; si no, las mismas pistas, sin tocar. El tono es
     * el `t` del `sp` que `decide` acaba de calcular para el token (la misma
     * oferta, sin otra lectura de la cadena).
     *
     * @param  array<string,mixed>  $hints
     * @param  array<string,mixed>|null  $sp  lo ofrecido en este turno ({@see UltronPromptOffer}); null sin negociar
     * @return array<string,mixed>
     */
    public static function conElRegistro(array $hints, ?array $sp): array
    {
        $pregunta = $hints['suggested_question'] ?? null;
        if (! is_array($sp) || ($sp['m'] ?? null) !== UltronPromptOffer::MODE_STUDIO
            || ! UltronFormalReplies::aplica($sp['t'] ?? null) || ! is_string($pregunta)) {
            return $hints;
        }
        $hints['suggested_question'] = self::PREGUNTAS_DE_USTED[$pregunta] ?? $pregunta;

        return $hints;
    }

    /**
     * LAS PISTAS DE UN TURNO DE LISTA (PT-17, puerta 1, L3; causa C1 del
     * diagnóstico del 24-11-2026). Si `decide` ofrece la lista de planes
     * (`plans_overview`, firmada en `po`), el turno RESPONDE con esa lista: ni
     * se recomienda un plan ahora ni se pregunta. Medido con el compilador 7:
     * toda `pricing_question` traía `should_recommend_now=true` (por
     * `price_engagement`) y el estratega, que lee «PISTAS… síguelas», pedía un
     * solo plan mientras el texto de la lista pedía la lista; los 20 primeros
     * borradores cerraron con una pregunta de descubrimiento. Lo arregla el
     * servidor, no el prompt: `should_recommend_now=false`,
     * `question_budget=0`, `may_ask=[]` y `suggested_question=null`.
     *
     * Las mismas pistas en `decide` y en el laboratorio (las dos llaman aquí
     * después de elegir la lista); sin lista, las de siempre, sin tocar. Las
     * dos que viajan firmadas en el token (`hot_lead_fast_path`,
     * `lifecycle_mode`) no cambian.
     *
     * @param  array<string,mixed>  $hints
     * @param  bool  $plansOverview  `decide` ofrece la lista en este turno
     * @return array<string,mixed>
     */
    public static function conLaLista(array $hints, bool $plansOverview): array
    {
        if (! $plansOverview) {
            return $hints;
        }

        return array_replace($hints, [
            'should_recommend_now' => false,
            'question_budget' => 0,
            'may_ask' => [],
            'suggested_question' => null,
        ]);
    }

    /**
     * Una pregunta de descubrimiento comercial, la que no cabe en un saludo.
     * Con `$tono` = «profesional», también la de usted; sin él (la llamada de
     * hoy, Legacy, cualquier otro tono), solo la de tú, como siempre.
     */
    public static function asksDiscovery(string $text, ?string $tono = null): bool
    {
        return self::preguntaDeDescubrimiento(SalesAgentDecisionSchema::normalize($text), $tono);
    }

    /** El texto ya normalizado: la de tú en cualquier sitio; la de usted, solo dentro de una pregunta y con «profesional». */
    private static function preguntaDeDescubrimiento(string $t, ?string $tono): bool
    {
        if (preg_match(self::PREGUNTA_DESCUBRIMIENTO, $t) === 1) {
            return true;
        }
        if (! UltronFormalReplies::aplica($tono)) {
            return false;
        }
        // Cada pregunta, desde el último signo que la separa de lo anterior hasta su «?».
        preg_match_all('/[^.!?¿]*\?/u', $t, $preguntas);
        foreach ($preguntas[0] as $pregunta) {
            if (preg_match(self::PREGUNTA_DESCUBRIMIENTO_DE_USTED, $pregunta) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private static function base(array $customer, array $resolved, bool $canOfferLink, ?string $baseIntent, array $payment, array $membership): array
    {
        $temperature = $customer['lead_temperature'] ?? CustomerIntelligenceService::COLD;
        $lifecycle = $customer['customer_lifecycle'] ?? CustomerIntelligenceService::PROSPECT;
        $type = $resolved['type'] ?? ReferenceResolver::NONE;
        $renewal = (bool) ($customer['renewal_window'] ?? false);

        // A quien ya es cliente no se le vende (salvo renovar): «ya pagué y quiero
        // empezar» es onboarding, no un hot lead.
        $esCliente = in_array($lifecycle, [CustomerIntelligenceService::PAID, CustomerIntelligenceService::ONBOARDING, CustomerIntelligenceService::ACTIVE_MEMBER], true);
        $sellingAllowed = ! $esCliente || $renewal;

        $quierePagar = $temperature === CustomerIntelligenceService::READY
            || in_array($type, [ReferenceResolver::SEND_IT, ReferenceResolver::CHOOSE_PLAN], true)
            || ($type === ReferenceResolver::ACCEPT_OFFER && ($resolved['offer_kind'] ?? null) === 'send_payment_link');
        $quiereEmpezar = $type === ReferenceResolver::HOW_TO_START
            || ($type === ReferenceResolver::ACCEPT_OFFER && ($resolved['offer_kind'] ?? null) === 'explain_how_to_start');

        $fastPathKind = match (true) {
            $quierePagar && $sellingAllowed => 'buy',
            $quiereEmpezar || ($quierePagar && ! $sellingAllowed) => 'start',
            default => null,
        };
        $hotPath = $fastPathKind === 'buy';

        $mode = match (true) {
            $lifecycle === CustomerIntelligenceService::PAYMENT_PENDING, ($payment['state'] ?? null) === 'pending' => 'payment_pending',
            $hotPath => 'closing', // cierre primero: también para renovar y para el exsocio que vuelve decidido
            $lifecycle === CustomerIntelligenceService::LAPSED => 'winback',
            $lifecycle === CustomerIntelligenceService::ACTIVE_MEMBER => 'support',
            in_array($lifecycle, [CustomerIntelligenceService::PAID, CustomerIntelligenceService::ONBOARDING], true) => 'onboarding',
            $lifecycle === CustomerIntelligenceService::READY_TO_BUY => 'closing',
            default => 'consultative',
        };

        // Una objeción abierta se atiende antes de volver a recomendar.
        $objecionAbierta = in_array($baseIntent, [SalesIntents::PRICE_OBJECTION, SalesIntents::TIME_OBJECTION, SalesIntents::DELAY_OBJECTION, SalesIntents::BEGINNER_FEAR, SalesIntents::INSECURITY_BODY], true)
            || $type === ReferenceResolver::DECLINE_OFFER;
        $objective = ($customer['known']['objective'] ?? null) ?? ($customer['inferred']['objective'] ?? null);
        $planInterest = $customer['inferred']['plan_interest'] ?? null;
        $priceAsked = in_array('price_engagement', $customer['inferred']['buying_signals'] ?? [], true) && $baseIntent !== SalesIntents::PRICE_OBJECTION;
        $enough = ($objective !== null || $planInterest !== null || $priceAsked || $hotPath) && ! $objecionAbierta;

        $questionBudget = $hotPath || $mode === 'onboarding' ? 0 : 1;
        // Lo que se puede preguntar: sólo lo desconocido y sólo si hay presupuesto.
        // Con una objeción abierta, la barrera es la única pregunta que vale.
        $mayAsk = $questionBudget === 0 ? [] : array_values(array_filter((array) ($customer['unknown'] ?? []), fn ($k) => in_array($k, self::ASKABLE, true)));
        if ($objecionAbierta && $questionBudget > 0 && ! in_array('main_barrier', $mayAsk, true) && ($customer['inferred']['main_barrier'] ?? null) === null) {
            $mayAsk[] = 'main_barrier';
        }

        $recomiendaYa = $enough && in_array($mode, ['consultative', 'closing', 'winback'], true);

        return [
            'hot_lead_fast_path' => $hotPath,
            'fast_path_kind' => $fastPathKind,
            'lifecycle_mode' => $mode,
            'should_recommend_now' => $recomiendaYa,
            'open_objection' => $objecionAbierta,
            'may_ask' => $mayAsk,
            'question_budget' => $questionBudget,
            /*
             * LA PREGUNTA QUE TOCA, si toca alguna: adaptada a lo que ya se
             * sabe. «¿Cuál es tu objetivo?» por rutina es un formulario; a
             * quien ya entrenó se le pregunta si quiere mantener rendimiento o
             * cambiar composición, a quien no dijo nada si es su primera vez,
             * y a quien ya quiere pagar no se le pregunta nada. Cuando ya toca
             * recomendar tampoco: se recomienda, no se pregunta. El redactor la
             * dice con sus palabras; el backend no la impone.
             */
            // Sólo a quien todavía no conocemos: a un socio, a un exsocio o a quien tiene un pago
            // pendiente, «¿sería tu primera vez entrenando?» le dice que no sabemos quién es.
            'suggested_question' => $questionBudget === 0 || $hotPath || $objecionAbierta || $recomiendaYa
                || $esCliente || ! in_array($mode, ['consultative', 'closing'], true)
                ? null
                : self::preguntaAdaptativa($objective, $customer['inferred']['experience_level'] ?? ($customer['known']['experience_level'] ?? null), $customer['inferred']['availability'] ?? ($customer['known']['availability'] ?? null), $mayAsk),
            'payment_possible' => $canOfferLink,
            'do_not_sell' => $esCliente && ! $renewal,
            // Renovar es la única venta a un cliente: solo en ventana, solo con un
            // plan que hoy se vende (context.membership.renewal.plan_id) y solo
            // con link si el motor de pagos lo permite. Sin plan vendible, la
            // estrategia recomienda desde active_plans; el equipo cobra.
            'renewal_plan_id' => $renewal ? ($membership['renewal']['plan_id'] ?? null) : null,
            'renewal_possible' => $renewal && $canOfferLink && ($membership['renewal']['plan_id'] ?? null) !== null,
        ];
    }

    /** ¿La respuesta final le hace una pregunta de descubrimiento a quien ya quiere pagar? */
    public static function asksDiscoveryToReadyBuyer(string $replyFinal, array $hints, ?string $tono = null): bool
    {
        if (! ($hints['hot_lead_fast_path'] ?? false)) {
            return false;
        }

        return self::preguntaDeDescubrimiento(SalesAgentDecisionSchema::normalize($replyFinal), $tono);
    }

    /**
     * La propuesta estratégica saneada para persistir: sólo los campos del
     * contrato, con sus enums, para poder auditar qué propuso el modelo.
     *
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    public static function fromProposal(array $proposal): array
    {
        $out = [];
        foreach (self::PROPOSAL_FIELDS as $f) {
            if (! array_key_exists($f, $proposal) || $proposal[$f] === null) {
                continue;
            }
            $v = $proposal[$f];
            if (in_array($f, self::BOOLEANS, true)) {
                // La regla `boolean` deja pasar "1"/"0"; lo que se guarda es un booleano.
                $v = filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
            } elseif ($f === 'information_needed') {
                $v = array_values(array_filter(is_array($v) ? $v : [], fn ($x) => is_string($x) && in_array($x, self::ASKABLE, true)));
            } elseif ($f === 'response_goal') {
                // Texto libre del modelo hacia el almacén de auditoría: sin
                // etiquetas, sin caracteres de control, sin cifras ni identificadores.
                $limpio = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $v)) ?? '');
                $v = MemoryRedactor::agent(MemoryRedactor::lead($limpio));
                if ($v === null) {
                    continue;
                }
            }
            $out[$f] = $v;
        }

        return $out;
    }
}
