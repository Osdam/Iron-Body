<?php

namespace App\Services\Outreach\Core;

use InvalidArgumentException;

/**
 * La PRIORIDAD entre estrategias que califican para la MISMA persona el mismo
 * día (contrato H9, D9-8 etapa 3, [P9-7]; fixture `priority-cases.json`;
 * §13.1 #5 y #12). PURA y determinista: el orden de llegada no importa.
 *
 * Gana UNA, regla a regla:
 *  1. nunca dos finalidades el mismo día (DF-P2): con `todayPurpose` (la
 *     finalidad que la persona ya tiene hoy: un trabajo o un contacto), las de
 *     otra finalidad quedan fuera con `purpose_conflict_today`; sin él, la
 *     ganadora fija la finalidad del día;
 *  2. `service` > `collections` > `commercial`;
 *  3. `priority` (mayor primero, DF-P1);
 *  4. `GOAL_PRIORITY` del objetivo (mayor primero);
 *  5. el `uuid` (menor por bytes primero: estable).
 * Las que pierden quedan en `defer`: `lower_priority` si son de la finalidad
 * de la ganadora y `purpose_conflict_today` si no.
 */
final class PriorityArbiter
{
    public const LOWER_PRIORITY = 'lower_priority';

    public const PURPOSE_CONFLICT = 'purpose_conflict_today';

    /**
     * @param  list<array{uuid: string, purpose: string, priority: int, goal: string}>  $candidates
     * @return array{winner: string|null, ranking: list<string>, deferred: list<array{uuid: string, reason: string}>}
     *
     * @throws InvalidArgumentException si un candidato no tiene la forma o se repite
     */
    public static function arbitrate(array $candidates, ?string $todayPurpose = null): array
    {
        $vistos = [];
        foreach ($candidates as $c) {
            if (! is_array($c) || ! is_string($c['uuid'] ?? null) || preg_match(OutreachCatalog::UUID_PATTERN, $c['uuid']) !== 1
                || ! in_array($c['purpose'] ?? null, OutreachCatalog::PURPOSES, true)
                || ! is_int($c['priority'] ?? null) || $c['priority'] < 1 || $c['priority'] > 100
                || ! in_array($c['goal'] ?? null, OutreachCatalog::GOALS, true)) {
                throw new InvalidArgumentException('Un candidato es {uuid (minúscula), purpose, priority 1–100, goal}.');
            }
            if (isset($vistos[$c['uuid']])) {
                throw new InvalidArgumentException('Una estrategia aparece dos veces.');
            }
            $vistos[$c['uuid']] = true;
        }
        if ($todayPurpose !== null && ! in_array($todayPurpose, OutreachCatalog::PURPOSES, true)) {
            throw new InvalidArgumentException('La finalidad de hoy es del conjunto cerrado.');
        }

        $compiten = [];
        $fuera = [];
        foreach ($candidates as $c) {
            $todayPurpose !== null && $c['purpose'] !== $todayPurpose ? $fuera[] = $c : $compiten[] = $c;
        }
        usort($compiten, self::compare(...));
        usort($fuera, self::compare(...));

        $ganadora = $compiten[0] ?? null;
        $aplazadas = [];
        foreach (array_slice($compiten, 1) as $c) {
            $aplazadas[] = ['uuid' => $c['uuid'], 'reason' => $c['purpose'] === $ganadora['purpose'] ? self::LOWER_PRIORITY : self::PURPOSE_CONFLICT];
        }
        foreach ($fuera as $c) {
            $aplazadas[] = ['uuid' => $c['uuid'], 'reason' => self::PURPOSE_CONFLICT];
        }

        return ['winner' => $ganadora['uuid'] ?? null, 'ranking' => array_column($compiten, 'uuid'), 'deferred' => $aplazadas];
    }

    /** Negativo si `$a` va antes que `$b`. */
    private static function compare(array $a, array $b): int
    {
        return OutreachCatalog::PURPOSE_RANK[$b['purpose']] <=> OutreachCatalog::PURPOSE_RANK[$a['purpose']]
            ?: $b['priority'] <=> $a['priority']
            ?: (OutreachCatalog::GOAL_PRIORITY[$b['goal']] ?? 0) <=> (OutreachCatalog::GOAL_PRIORITY[$a['goal']] ?? 0)
            ?: strcmp($a['uuid'], $b['uuid']);
    }
}
