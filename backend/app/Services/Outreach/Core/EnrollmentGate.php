<?php

namespace App\Services\Outreach\Core;

use DateTimeInterface;

/**
 * La PUERTA DE INSCRIPCIÓN (contrato H9, D9-5 «Solo `true` inscribe», D9-8
 * etapa 2): hechos → `audience` → `exclusions` → `success`. PURA. Se evalúan
 * las tres siempre (la simulación enseña las tres trazas) y se decide en ese
 * orden:
 *
 *  - la audiencia tiene que ser `true`: `false` → `not_in_audience`;
 *    `unknown` → `insufficient_facts` (el lado seguro: va a los recuentos de la
 *    pasada, no a una fila);
 *  - las exclusiones tienen que ser `false`: `true` → `excluded`; `unknown` →
 *    `insufficient_facts` (no se puede probar que no está excluida);
 *  - el éxito tiene que ser `false`: `true` → `success_reached` (el objetivo ya
 *    está cumplido); `unknown` → `insufficient_facts`.
 *
 * Solo así `enroll`. Los mismos tres valores son el contexto que la escalera
 * vuelve a mirar antes de despachar (N6, {@see context()}).
 */
final class EnrollmentGate
{
    public const ENROLL = 'enroll';

    public const NOT_IN_AUDIENCE = 'not_in_audience';

    public const INSUFFICIENT_FACTS = 'insufficient_facts';

    public const EXCLUDED = 'excluded';

    public const SUCCESS_REACHED = 'success_reached';

    /**
     * @return array{enroll: bool, reason: string, audience: ConditionResult, exclusions: ConditionResult|null, success: ConditionResult}
     */
    public static function evaluate(CanonicalCondition $audience, ?CanonicalCondition $exclusions, CanonicalCondition $success, FactSnapshot $facts, DateTimeInterface $now): array
    {
        $a = ConditionEvaluator::evaluate($audience, $facts, $now, 'audience');
        $x = $exclusions === null ? null : ConditionEvaluator::evaluate($exclusions, $facts, $now, 'exclusions');
        $s = ConditionEvaluator::evaluate($success, $facts, $now, 'success');

        $motivo = match (true) {
            $a->value === false => self::NOT_IN_AUDIENCE,
            $a->value === null => self::INSUFFICIENT_FACTS,
            $x?->value === true => self::EXCLUDED,
            $x !== null && $x->value === null => self::INSUFFICIENT_FACTS,
            $s->value === true => self::SUCCESS_REACHED,
            $s->value === null => self::INSUFFICIENT_FACTS,
            default => self::ENROLL,
        };

        return ['enroll' => $motivo === self::ENROLL, 'reason' => $motivo, 'audience' => $a, 'exclusions' => $x, 'success' => $s];
    }

    /**
     * Las tres como las espera la escalera en N6 (`context.audience`,
     * `context.exclusions` —`false` si la estrategia no tiene— y `context.success`).
     *
     * @param  array{audience: ConditionResult, exclusions: ConditionResult|null, success: ConditionResult}  $gate
     * @return array{audience: string, exclusions: string, success: string}
     */
    public static function context(array $gate): array
    {
        return [
            'audience' => $gate['audience']->label(),
            'exclusions' => $gate['exclusions']?->label() ?? ConditionEvaluator::FALSE,
            'success' => $gate['success']->label(),
        ];
    }
}
