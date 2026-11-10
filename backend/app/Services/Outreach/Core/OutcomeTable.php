<?php

namespace App\Services\Outreach\Core;

/**
 * Los RESULTADOS NORMALIZADOS del H9 y lo que cada uno permite (contrato H9,
 * D9-10; fixture `outcome-transitions.json`). PURA: solo datos y reglas.
 *
 * 18 resultados en tres grupos (entrega, respuesta y negocio), con sus fuentes
 * (los 8 códigos de `outreach_outcomes.source`, DF-O1) y sus transiciones
 * permitidas; la estrategia elige UNA por resultado (`on`) y, si no dice nada,
 * rige la de por defecto (*). Transiciones: `next_step`, `retry_step` (el
 * mismo paso, intento + 1; `failed` admite 1 reintento y `no_answer` 2,
 * DF-O6), `wait`, `end_success`, `end_exit` y `handoff_human`.
 *
 * {@see resolve()} normaliza un resultado que llega (T14):
 *  - un código fuera de los 18, una fuente desconocida o una que ese resultado
 *    no admite: `ignore` con incidente; la secuencia no avanza (DF-O2);
 *  - LA REGLA ASIMÉTRICA DE LA IA [P9-6, DF-O3]: el clasificador solo puede
 *    llevar a `end_exit`, `handoff_human` o `wait`; con confianza entera de 0
 *    a 100, `opted_out` se acepta desde 50 y el resto desde 90; por debajo, sin
 *    confianza o con la etiqueta `ambiguous`, `needs_review`: la secuencia para
 *    y se abre una tarea asistida (`handoff_human`, §13.1 #21 y #22);
 *  - un `on` que pide una transición que el resultado no admite: rige la de
 *    por defecto e incidente (DF-O5). `opted_out` lleva SIEMPRE a `end_exit`
 *    (§13.1 #10), diga lo que diga la estrategia;
 *  - el estado de la inscripción es el de la transición, salvo `opted_out` →
 *    `opted_out` y `no_response` → `expired`; los resultados sin transición no
 *    lo cambian (DF-O7). Los efectos (DF-O8) van siempre.
 */
final class OutcomeTable
{
    /** En el orden de la tabla de D9-10. */
    public const OUTCOMES = [
        'blocked', 'not_attempted', 'failed', 'ambiguous', 'sent', 'delivered', 'read', 'no_answer', 'answered', 'replied',
        'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked', 'converted', 'no_response',
    ];

    public const OPTED_OUT = 'opted_out';

    public const NO_RESPONSE = 'no_response';

    public const AMBIGUOUS = 'ambiguous';

    public const NEXT_STEP = 'next_step';

    public const RETRY_STEP = 'retry_step';

    public const WAIT = 'wait';

    public const END_SUCCESS = 'end_success';

    public const END_EXIT = 'end_exit';

    public const HANDOFF_HUMAN = 'handoff_human';

    public const TRANSITIONS = [self::NEXT_STEP, self::RETRY_STEP, self::WAIT, self::END_SUCCESS, self::END_EXIT, self::HANDOFF_HUMAN];

    public const SOURCE_CLASSIFIER = 'classifier';

    /** Las 8 fuentes de `outreach_outcomes.source`, por bytes. */
    public const SOURCES = ['classifier', 'crm_fact', 'inbound_message', 'meta_status', 'simulated', 'staff', 'system', 'voice_call'];

    /** Las fuentes que existen el 15-11 (T14): W9, V9 y AI9 añaden las demás. */
    public const SOURCES_AVAILABLE = ['crm_fact', 'simulated', 'staff', 'system'];

    /** La tabla de D9-10 (grupo, fuentes, permitidas, por defecto y efectos), ordenada por bytes. */
    public const TABLE = [
        'ambiguous' => ['group' => 'delivery', 'sources' => ['system'], 'allowed' => [], 'default' => null, 'effects' => ['reconcile']],
        'answered' => ['group' => 'response', 'sources' => ['staff', 'voice_call'], 'allowed' => ['end_exit', 'end_success', 'handoff_human', 'next_step'], 'default' => 'end_exit', 'effects' => []],
        'appointment_booked' => ['group' => 'business', 'sources' => ['crm_fact', 'staff'], 'allowed' => ['end_success', 'next_step'], 'default' => 'end_success', 'effects' => []],
        'blocked' => ['group' => 'delivery', 'sources' => ['system'], 'allowed' => ['end_exit', 'wait'], 'default' => 'wait', 'effects' => []],
        'callback_requested' => ['group' => 'response', 'sources' => ['classifier', 'staff'], 'allowed' => ['end_exit', 'wait'], 'default' => 'wait', 'effects' => []],
        'converted' => ['group' => 'business', 'sources' => ['crm_fact', 'system'], 'allowed' => ['end_success'], 'default' => 'end_success', 'effects' => []],
        'delivered' => ['group' => 'delivery', 'sources' => ['meta_status'], 'allowed' => [], 'default' => null, 'effects' => []],
        'failed' => ['group' => 'delivery', 'sources' => ['meta_status', 'system', 'voice_call'], 'allowed' => ['end_exit', 'next_step', 'retry_step'], 'default' => 'end_exit', 'effects' => []],
        'human_requested' => ['group' => 'response', 'sources' => ['classifier', 'inbound_message', 'staff', 'voice_call'], 'allowed' => ['handoff_human'], 'default' => 'handoff_human', 'effects' => ['assisted_task']],
        'no_answer' => ['group' => 'delivery', 'sources' => ['staff', 'voice_call'], 'allowed' => ['end_exit', 'next_step', 'retry_step', 'wait'], 'default' => 'end_exit', 'effects' => []],
        'no_response' => ['group' => 'business', 'sources' => ['system'], 'allowed' => ['end_exit'], 'default' => 'end_exit', 'effects' => []],
        'not_attempted' => ['group' => 'delivery', 'sources' => ['simulated'], 'allowed' => [], 'default' => null, 'effects' => []],
        'not_interested' => ['group' => 'response', 'sources' => ['classifier', 'staff'], 'allowed' => ['end_exit'], 'default' => 'end_exit', 'effects' => ['cooldown']],
        'opted_out' => ['group' => 'response', 'sources' => ['classifier', 'inbound_message', 'staff', 'voice_call'], 'allowed' => ['end_exit'], 'default' => 'end_exit', 'effects' => ['cancel_pending_all_strategies', 'suppress_purpose_channel']],
        'read' => ['group' => 'delivery', 'sources' => ['meta_status'], 'allowed' => [], 'default' => null, 'effects' => []],
        'replied' => ['group' => 'response', 'sources' => ['inbound_message'], 'allowed' => ['end_exit', 'handoff_human'], 'default' => 'handoff_human', 'effects' => []],
        'sent' => ['group' => 'delivery', 'sources' => ['meta_status', 'voice_call'], 'allowed' => [], 'default' => null, 'effects' => []],
        'wrong_person' => ['group' => 'response', 'sources' => ['classifier', 'staff', 'voice_call'], 'allowed' => ['end_exit'], 'default' => 'end_exit', 'effects' => ['suppress_channel']],
    ];

    /** Las únicas transiciones de un resultado del clasificador (P9-6). */
    public const CLASSIFIER_SAFE_TRANSITIONS = [self::END_EXIT, self::HANDOFF_HUMAN, self::WAIT];

    /** Confianza entera de 0 a 100 (DF-O3): `opted_out` frena y se acepta con el umbral bajo. */
    public const CLASSIFIER_LOW = 50;

    public const CLASSIFIER_HIGH = 90;

    /** Reintentos de negocio por resultado (DF-O6): `failed` 1 (intento ≤ 2) y `no_answer` 2 (intento ≤ 3). */
    public const RETRY_MAX = ['failed' => 1, 'no_answer' => 2];

    public const STATUS_BY_TRANSITION = [
        self::NEXT_STEP => 'active', self::RETRY_STEP => 'active', self::WAIT => 'waiting',
        self::END_SUCCESS => 'completed', self::END_EXIT => 'exited', self::HANDOFF_HUMAN => 'handed_off',
    ];

    public const ENROLLMENT_STATUSES = ['active', 'waiting', 'completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];

    public const FINAL_STATUSES = ['completed', 'exited', 'opted_out', 'handed_off', 'expired', 'canceled'];

    public const VERDICT_APPLY = 'apply';

    public const VERDICT_NEEDS_REVIEW = 'needs_review';

    public const VERDICT_IGNORE = 'ignore';

    /** @return list<string> los 18 resultados ordenados por bytes (la forma de los dominios del catálogo) */
    public static function sortedOutcomes(): array
    {
        return array_keys(self::TABLE);
    }

    public static function isOutcome(mixed $outcome): bool
    {
        return is_string($outcome) && array_key_exists($outcome, self::TABLE);
    }

    /**
     * ¿Admite este resultado esta transición en el `on` de una estrategia?
     * (la sección `matrix` del fixture).
     *
     * @return array{allowed: bool, isDefault: bool, error: string|null}
     */
    public static function check(string $outcome, string $transition): array
    {
        if (! self::isOutcome($outcome)) {
            return ['allowed' => false, 'isDefault' => false, 'error' => 'unknown_outcome'];
        }
        if (! in_array($transition, self::TRANSITIONS, true)) {
            return ['allowed' => false, 'isDefault' => false, 'error' => 'value_not_in_domain'];
        }
        $permitida = in_array($transition, self::TABLE[$outcome]['allowed'], true);

        return [
            'allowed' => $permitida,
            'isDefault' => $permitida && self::TABLE[$outcome]['default'] === $transition,
            'error' => $permitida ? null : 'transition_not_allowed',
        ];
    }

    /**
     * Normaliza un resultado y dice qué transición rige.
     *
     * @param  array<string, mixed>|null  $on  el `on` del paso: resultado → transición
     * @return array{verdict: string, outcome: string|null, transition: string|null, enrollmentStatus: string|null, effects: list<string>, incident: bool}
     */
    public static function resolve(mixed $outcome, mixed $source, mixed $confidence, ?array $on, int $attempt): array
    {
        $ignorar = ['verdict' => self::VERDICT_IGNORE, 'outcome' => null, 'transition' => null, 'enrollmentStatus' => null, 'effects' => [], 'incident' => true];
        $revisar = ['verdict' => self::VERDICT_NEEDS_REVIEW, 'outcome' => null, 'transition' => self::HANDOFF_HUMAN, 'enrollmentStatus' => 'handed_off', 'effects' => ['assisted_task'], 'incident' => false];
        if (! self::isOutcome($outcome) || ! is_string($source) || ! in_array($source, self::SOURCES, true)) {
            return $ignorar;
        }
        $clasificador = $source === self::SOURCE_CLASSIFIER;
        if ($clasificador && $outcome === self::AMBIGUOUS) {
            return $revisar;
        }
        if (! in_array($source, self::TABLE[$outcome]['sources'], true)) {
            return $ignorar;
        }
        if ($clasificador) {
            $umbral = $outcome === self::OPTED_OUT ? self::CLASSIFIER_LOW : self::CLASSIFIER_HIGH;
            if (! is_int($confidence) || $confidence < 0 || $confidence > 100 || $confidence < $umbral) {
                return $revisar;
            }
        }

        $fila = self::TABLE[$outcome];
        $permitidas = $clasificador ? array_values(array_intersect($fila['allowed'], self::CLASSIFIER_SAFE_TRANSITIONS)) : $fila['allowed'];
        $pedida = $on[$outcome] ?? null;
        $incidente = false;
        $transicion = $fila['default'];
        if ($pedida !== null) {
            if ($outcome !== self::OPTED_OUT && is_string($pedida) && in_array($pedida, $permitidas, true)) {
                $transicion = $pedida;
            } elseif ($pedida !== $fila['default']) {
                // DF-O5: una transición que el resultado (o la regla asimétrica) no admite: rige la de por defecto.
                $incidente = true;
            }
        }
        if ($transicion === self::RETRY_STEP && $attempt > (self::RETRY_MAX[$outcome] ?? 0)) {
            $transicion = $fila['default'];
        }
        if ($clasificador && ! in_array($transicion, self::CLASSIFIER_SAFE_TRANSITIONS, true)) {
            // DF-O4: con el clasificador, si ni lo pedido ni el defecto frenan o derivan, sale.
            $transicion = self::END_EXIT;
        }

        return [
            'verdict' => self::VERDICT_APPLY,
            'outcome' => $outcome,
            'transition' => $transicion,
            'enrollmentStatus' => $transicion === null ? null : match ($outcome) {
                self::OPTED_OUT => 'opted_out',
                self::NO_RESPONSE => 'expired',
                default => self::STATUS_BY_TRANSITION[$transicion],
            },
            'effects' => $fila['effects'],
            'incident' => $incidente,
        ];
    }
}
