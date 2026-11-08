<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * El horario de las salientes (contrato H8, D8-15, D8-18; Ley 2300 de 2023,
 * art. 3; fixture `policy-tables.json` §scheduleLegal y §scheduleOwn). Pura:
 * la hora la pone quien pregunta y se convierte SIEMPRE a Bogotá.
 *
 *  - Legal: de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00,
 *    intervalos SEMIABIERTOS (las 19:00:00 ya están fuera, DF-P1); ni
 *    domingos ni festivos ({@see ColombianHolidays}).
 *  - Propio [P-13]: la INTERSECCIÓN con el legal (nunca lo amplía). Sin
 *    configurar, solo el legal; mal configurado (apertura ≥ cierre o mal
 *    escrito), fuera de horario: falla cerrado (DF-P2).
 *
 * Lo mismo sirve para el horario del personal de las transferencias [P-12]
 * ({@see within()}): sin configurar, nunca hay personal.
 */
final class OutboundSchedule
{
    public const ZONE = 'America/Bogota';

    /** Día ISO (1 = lunes … 6 = sábado) → [apertura, cierre) en minutos. El domingo no está. */
    public const LEGAL = [1 => [420, 1140], 2 => [420, 1140], 3 => [420, 1140], 4 => [420, 1140], 5 => [420, 1140], 6 => [480, 900]];

    /** `HH:MM-HH:MM`, 24 h. */
    public const WINDOW_PATTERN = '/^([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]|24):([0-5][0-9])$/D';

    /**
     * @param  array{weekdays: array{0:int,1:int}|null, saturday: array{0:int,1:int}|null}|false|null  $own
     * @return array{outsideHours: bool, holiday: bool}
     */
    public static function evaluate(DateTimeInterface $at, array|false|null $own): array
    {
        $local = self::local($at);
        $dia = (int) $local->format('N');
        $segundo = self::secondOfDay($local);

        $legal = self::LEGAL[$dia] ?? null;
        $dentro = $legal !== null && self::inside($segundo, $legal);
        if ($dentro && $own !== null) {
            $propia = $own === false ? null : ($dia === 6 ? $own['saturday'] : $own['weekdays']);
            $dentro = $propia !== null && self::inside($segundo, $propia);
        }

        return ['outsideHours' => ! $dentro, 'holiday' => ColombianHolidays::isHoliday($at)];
    }

    /**
     * ¿Cae `$at` dentro de unas ventanas (el horario del personal)? Sin
     * ventanas, mal escritas, en domingo o en festivo: no.
     *
     * @param  array{weekdays: array{0:int,1:int}|null, saturday: array{0:int,1:int}|null}|false|null  $windows
     */
    public static function within(DateTimeInterface $at, array|false|null $windows): bool
    {
        if (! is_array($windows) || ColombianHolidays::isHoliday($at)) {
            return false;
        }
        $local = self::local($at);
        $dia = (int) $local->format('N');
        $ventana = match (true) {
            $dia >= 1 && $dia <= 5 => $windows['weekdays'],
            $dia === 6 => $windows['saturday'],
            default => null,
        };

        return $ventana !== null && self::inside(self::secondOfDay($local), $ventana);
    }

    /**
     * Una ventana escrita (`09:00-18:00`): `''` → null (sin ventana); bien
     * escrita y con apertura < cierre → [apertura, cierre) en minutos; si no,
     * false (mal configurada).
     *
     * @return array{0:int,1:int}|false|null
     */
    public static function window(string $raw): array|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match(self::WINDOW_PATTERN, $raw, $m) !== 1) {
            return false;
        }
        $abre = (int) $m[1] * 60 + (int) $m[2];
        $cierra = (int) $m[3] * 60 + (int) $m[4];
        if ($cierra > 1440 || $abre >= $cierra) {
            return false;
        }

        return [$abre, $cierra];
    }

    /**
     * Dos ventanas escritas (laborables y sábado): las dos vacías → null (sin
     * horario propio); alguna mal escrita → false (fuera siempre); si no, las
     * ventanas (una vacía = ese tipo de día cerrado).
     *
     * @return array{weekdays: array{0:int,1:int}|null, saturday: array{0:int,1:int}|null}|false|null
     */
    public static function windows(string $weekdays, string $saturday): array|false|null
    {
        $laborables = self::window($weekdays);
        $sabado = self::window($saturday);
        if ($laborables === false || $sabado === false) {
            return false;
        }
        if ($laborables === null && $sabado === null) {
            return null;
        }

        return ['weekdays' => $laborables, 'saturday' => $sabado];
    }

    /** El horario propio de las salientes [P-13], de `voice.outbound.own_hours`. */
    public static function ownFromConfig(): array|false|null
    {
        return self::windows((string) config('voice.outbound.own_hours.weekdays', ''), (string) config('voice.outbound.own_hours.saturday', ''));
    }

    /** El horario del personal para transferir [P-12], de `voice.transfer.staff_hours`. */
    public static function staffFromConfig(): array|false|null
    {
        return self::windows((string) config('voice.transfer.staff_hours.weekdays', ''), (string) config('voice.transfer.staff_hours.saturday', ''));
    }

    public static function local(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(self::ZONE));
    }

    /** @param  array{0:int,1:int}  $ventana  [apertura, cierre) en minutos */
    private static function inside(int $segundo, array $ventana): bool
    {
        return $segundo >= $ventana[0] * 60 && $segundo < $ventana[1] * 60;
    }

    private static function secondOfDay(DateTimeImmutable $local): int
    {
        return (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
    }
}
