<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * La frecuencia por contacto de las salientes (contrato H8, D8-15; Ley 2300,
 * art. 3; [P-11]; fixture `policy-tables.json` §frequency). Pura.
 *
 *  - Cuenta como intento (DF-P3): `requested`, `ambiguous`, `dialing`,
 *    `active`, `completed`, `busy`, `no_answer`, `failed` y `hung_up`. No
 *    cuentan `canceled` ni `failed_to_start` (no salió nada). Las entrantes
 *    nunca cuentan, tampoco las `denied`.
 *  - «Hoy» es la fecha de Bogotá de `now`. La semana, desde el lunes 00:00 de
 *    Bogotá (la lectura de la ley) o los 7 días móviles `(now − 7 d, now]`,
 *    la lectura más estricta (DF-P4). El propietario elige [P-11]; por
 *    defecto, la estricta.
 *  - Se excede si los de hoy llegan a 1 o los de la semana a su tope (2).
 */
final class VoiceFrequency
{
    /** Ley 2300, art. 3: una vez al día. No es configurable. */
    public const PER_DAY = 1;

    public const PER_WEEK_DEFAULT = 2;

    public const WEEK_CALENDAR = 'calendar';

    public const WEEK_ROLLING = 'rolling';

    public const WEEK_MODES = [self::WEEK_CALENDAR, self::WEEK_ROLLING];

    public const COUNTED_STATUSES = ['requested', 'ambiguous', 'dialing', 'active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'];

    /**
     * @param  list<array{at: DateTimeInterface|string, status: string, direction: string}>  $history
     * @return array{today: int, week: int, rolling7: int}
     */
    public static function count(DateTimeInterface $now, array $history): array
    {
        $zona = new DateTimeZone(OutboundSchedule::ZONE);
        $ahora = DateTimeImmutable::createFromInterface($now);
        $hoy = $ahora->setTimezone($zona)->format('Y-m-d');
        $lunes = $ahora->setTimezone($zona)->modify('monday this week')->setTime(0, 0);
        $hace7 = $ahora->modify('-7 days');

        $cuenta = ['today' => 0, 'week' => 0, 'rolling7' => 0];
        foreach ($history as $fila) {
            if (($fila['direction'] ?? null) !== 'outbound' || ! in_array($fila['status'] ?? null, self::COUNTED_STATUSES, true)) {
                continue;
            }
            $en = $fila['at'] instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($fila['at']) : new DateTimeImmutable((string) $fila['at']);
            if ($en > $ahora) {
                continue;
            }
            if ($en->setTimezone($zona)->format('Y-m-d') === $hoy) {
                $cuenta['today']++;
            }
            if ($en >= $lunes) {
                $cuenta['week']++;
            }
            if ($en > $hace7) {
                $cuenta['rolling7']++;
            }
        }

        return $cuenta;
    }

    /** @param  array{today: int, week: int, rolling7: int}  $counts */
    public static function exceeded(array $counts, int $perWeek, string $weekMode): bool
    {
        $semana = $weekMode === self::WEEK_CALENDAR ? $counts['week'] : $counts['rolling7'];

        return $counts['today'] >= self::PER_DAY || $semana >= max(1, $perWeek);
    }

    /** El modo de la semana de `voice.outbound.frequency.week` (cualquier otro valor, la estricta). */
    public static function weekModeFromConfig(): string
    {
        return config('voice.outbound.frequency.week') === self::WEEK_CALENDAR ? self::WEEK_CALENDAR : self::WEEK_ROLLING;
    }

    public static function perWeekFromConfig(): int
    {
        $tope = config('voice.outbound.frequency.per_week');

        return is_int($tope) ? min(self::PER_WEEK_DEFAULT, max(1, $tope)) : self::PER_WEEK_DEFAULT;
    }
}
