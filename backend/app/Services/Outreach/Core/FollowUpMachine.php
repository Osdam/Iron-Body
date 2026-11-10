<?php

namespace App\Services\Outreach\Core;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * La MÁQUINA DE SEGUIMIENTOS (contrato H9, D9-8 etapa 9 y D9-10; fixture
 * `followup-cases.json`; T23). PURA: dado el estado de una inscripción, sus
 * pasos y un resultado YA resuelto ({@see OutcomeTable::resolve()}), dice qué
 * pasa después. No escribe nada: B9-run aplica lo que devuelve (un reintento
 * de negocio es una fila nueva, intento + 1).
 *
 * LAS REGLAS:
 *  - un resultado ignorado (`outcome_ignored`) o sin transición
 *    (`non_terminal`: `not_attempted`, `ambiguous`, `sent`, `delivered`,
 *    `read`) no cambia nada (DF-F5); el seguimiento solo ocurre si la regla del
 *    paso lo permite (`no_answer` → `next_step` SOLO con `on.no_answer`);
 *  - `next_step` planifica el paso siguiente con intento 1; `retry_step` y
 *    `wait`, el MISMO paso con intento + 1; `wait` deja la inscripción en
 *    `waiting` y lo demás en `active` (DF-F1); las salidas cierran (`closed`);
 *  - NUNCA ACORTA UN INTERVALO (DF-F2): `notBefore` es el primer hueco
 *    permitido (ley ∩ horario propio ∩ el de la estrategia) desde el más tardío
 *    entre «ahora», la base + el retraso del paso planificado (base = la
 *    inscripción o «ahora», según `from`), «ahora» + la espera pedida y la hora
 *    que pidió la persona; nunca un domingo ni un festivo;
 *  - sin más pasos, o con el hueco en o después del fin de la ventana, no hay
 *    trabajo: la inscripción espera a vencer (`no_response`, DF-F3);
 *  - con los contactos de la inscripción en su tope, no se planifica nada y
 *    sale (`max_touches_reached`, DF-F4).
 */
final class FollowUpMachine
{
    public const DEFAULT_MAX_TOUCHES = 6;

    public const DELAY_FROM_ENROLLMENT = 'enrollment';

    public const DELAY_FROM_PREVIOUS = 'previous';

    public const REASONS = [
        'closed', 'next_step', 'retry_step', 'wait', 'outcome_ignored', 'non_terminal', 'sequence_done_waits_window',
        'window_over_no_job', 'max_touches_reached', 'enrollment_final',
    ];

    /**
     * @param  array{status: string, stepIndex: int, attempt: int, touches: int, enrolledAt: string, windowEndsAt: string}  $enrollment
     * @param  list<array{id: string, delay: array{n: int, unit: string, from: string}}>  $steps
     * @param  array{verdict: string, transition: string|null, enrollmentStatus: string|null}  $resolution
     * @param  array{waitFor?: array{n: int, unit: string}|null, callbackAt?: string|null, maxTouches?: int|null, ownHours?: mixed, schedule?: array|null}  $extras
     * @return array{status: string, stepIndex: int, attempt: int, nextJob: array{stepIndex: int, attempt: int, notBefore: string}|null, reason: string}
     *
     * @throws InvalidArgumentException si la inscripción o los pasos no tienen la forma
     */
    public static function apply(array $enrollment, array $steps, array $resolution, DateTimeInterface $now, array $extras = []): array
    {
        $e = self::enrollment($enrollment, $steps);
        $ahora = RelativeTime::utc($now);
        $igual = static fn (string $motivo, ?string $estado = null): array => ['status' => $estado ?? $e['status'], 'stepIndex' => $e['stepIndex'], 'attempt' => $e['attempt'], 'nextJob' => null, 'reason' => $motivo];

        if (in_array($e['status'], OutcomeTable::FINAL_STATUSES, true)) {
            return $igual('enrollment_final');
        }
        if (($resolution['verdict'] ?? null) === OutcomeTable::VERDICT_IGNORE) {
            return $igual('outcome_ignored');
        }
        $transicion = $resolution['transition'] ?? null;
        if ($transicion === null) {
            return $igual('non_terminal');
        }
        if (! in_array($transicion, [OutcomeTable::NEXT_STEP, OutcomeTable::RETRY_STEP, OutcomeTable::WAIT], true)) {
            return $igual('closed', (string) $resolution['enrollmentStatus']);
        }

        if ($e['touches'] >= (is_int($extras['maxTouches'] ?? null) ? $extras['maxTouches'] : self::DEFAULT_MAX_TOUCHES)) {
            return $igual('max_touches_reached', 'exited');
        }
        $siguiente = $transicion === OutcomeTable::NEXT_STEP;
        $paso = $siguiente ? $e['stepIndex'] + 1 : $e['stepIndex'];
        if (! isset($steps[$paso])) {
            return $igual('sequence_done_waits_window', 'waiting');
        }
        $intento = $siguiente ? 1 : $e['attempt'] + 1;

        $retraso = $steps[$paso]['delay'];
        $base = $retraso['from'] === self::DELAY_FROM_ENROLLMENT ? RelativeTime::parseInstant($e['enrolledAt']) : $ahora;
        $desde = max($ahora, self::plus($base, $retraso));
        if ($transicion === OutcomeTable::WAIT && is_array($extras['waitFor'] ?? null)) {
            $desde = max($desde, self::plus($ahora, $extras['waitFor']));
        }
        if (is_string($extras['callbackAt'] ?? null)) {
            $desde = max($desde, RelativeTime::parseInstant($extras['callbackAt']));
        }
        $hueco = ContactCalendar::nextSlot($desde, $extras['ownHours'] ?? null, $extras['schedule'] ?? null);
        if ($hueco === null || $hueco >= RelativeTime::parseInstant($e['windowEndsAt'])) {
            return $igual('window_over_no_job', 'waiting');
        }

        return [
            'status' => $transicion === OutcomeTable::WAIT ? 'waiting' : 'active',
            'stepIndex' => $paso,
            'attempt' => $intento,
            'nextJob' => ['stepIndex' => $paso, 'attempt' => $intento, 'notBefore' => RelativeTime::format($hueco)],
            'reason' => $transicion,
        ];
    }

    /** Un instante más una duración exacta (1 día = 24 h, 1 semana = 168 h). */
    private static function plus(DateTimeImmutable $base, array $duracion): DateTimeImmutable
    {
        return $base->modify('+'.($duracion['n'] * OutreachCatalog::UNIT_HOURS[$duracion['unit']]).' hours');
    }

    /** @return array{status: string, stepIndex: int, attempt: int, touches: int, enrolledAt: string, windowEndsAt: string} */
    private static function enrollment(array $e, array $steps): array
    {
        if (! in_array($e['status'] ?? null, OutcomeTable::ENROLLMENT_STATUSES, true)
            || ! is_int($e['stepIndex'] ?? null) || $e['stepIndex'] < 0 || ! is_int($e['attempt'] ?? null) || $e['attempt'] < 1
            || ! is_int($e['touches'] ?? null) || $e['touches'] < 0
            || ! RelativeTime::isInstant($e['enrolledAt'] ?? null) || ! RelativeTime::isInstant($e['windowEndsAt'] ?? null)) {
            throw new InvalidArgumentException('La inscripción es {status, stepIndex, attempt, touches, enrolledAt, windowEndsAt}.');
        }
        if (! array_is_list($steps) || ! isset($steps[$e['stepIndex']])) {
            throw new InvalidArgumentException('El paso de la inscripción no está en la secuencia.');
        }
        foreach ($steps as $s) {
            $d = $s['delay'] ?? null;
            if (! is_array($d) || ! is_int($d['n'] ?? null) || $d['n'] < 0 || ! isset(OutreachCatalog::UNIT_HOURS[$d['unit'] ?? ''])
                || ! in_array($d['from'] ?? null, [self::DELAY_FROM_ENROLLMENT, self::DELAY_FROM_PREVIOUS], true)) {
                throw new InvalidArgumentException('Cada paso lleva delay {n ≥ 0, unit, from}.');
            }
        }

        return $e;
    }
}
