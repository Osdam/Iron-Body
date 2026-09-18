<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;

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
     * Pistas deterministas para el estratega, derivadas del perfil (PUNTO 2)
     * y del referente (PUNTO 1). Laravel las calcula; el modelo las sigue.
     *
     * @param  array<string,mixed>  $customer
     * @param  array<string,mixed>  $resolved
     * @return array<string,mixed>
     */
    public static function hints(array $customer, array $resolved, bool $canOfferLink, ?string $baseIntent = null, array $payment = []): array
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

        return [
            'hot_lead_fast_path' => $hotPath,
            'fast_path_kind' => $fastPathKind,
            'lifecycle_mode' => $mode,
            'should_recommend_now' => $enough && in_array($mode, ['consultative', 'closing', 'winback'], true),
            'open_objection' => $objecionAbierta,
            'may_ask' => $mayAsk,
            'question_budget' => $questionBudget,
            'payment_possible' => $canOfferLink,
            'do_not_sell' => $esCliente && ! $renewal,
        ];
    }

    /** ¿La respuesta final le hace una pregunta de descubrimiento a quien ya quiere pagar? */
    public static function asksDiscoveryToReadyBuyer(string $replyFinal, array $hints): bool
    {
        if (! ($hints['hot_lead_fast_path'] ?? false)) {
            return false;
        }

        return preg_match(self::PREGUNTA_DESCUBRIMIENTO, SalesAgentDecisionSchema::normalize($replyFinal)) === 1;
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
