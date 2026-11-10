<?php

namespace App\Services\Outreach\Core;

use App\Services\Telephony\ColombianHolidays;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * El CALENDARIO de los contactos del H9 (contrato H9, D9-7 «Ventana horaria»,
 * D9-6 `schedule`, D9-15; Ley 2300 de 2023, arts. 3 y 5; fixture
 * `calendar-cases.json`). PURO: el instante entra como argumento.
 *
 * TRES CAPAS, cada una solo ESTRECHA la anterior (nunca la amplía):
 *  1. N1, la ventana permitida (DF-W1). La Ley 2300 de 2023 (art. 3) permite
 *     de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00, nunca
 *     domingos ni festivos ({@see ColombianHolidays}, Ley 2578 incluida); las
 *     horas de silencio de `ContactPolicy` (de 21:00 a 08:00) cierran además
 *     la hora de 07:00 a 08:00 entre semana. N1 es la intersección: de lunes a
 *     viernes [08:00, 19:00) y sábados [08:00, 15:00), SEMIABIERTA (las
 *     19:00:00 ya están fuera, como `OutboundSchedule`). Su motivo se llama
 *     `calendar_outside_legal_hours` también de 07:00 a 08:00, cuando lo
 *     cierra el silencio y no la ley (lo dice su texto en
 *     {@see DecisionTraceText});
 *  2. el horario PROPIO de la operación [P9-17]: `null` = sin horario propio;
 *     `'invalid'` (o `false`, lo que da `OutboundSchedule::windows()`) = mal
 *     configurado → todo fuera (falla cerrado, DF-W4); `{weekdays, saturday}`
 *     con `[abre, cierra]` en minutos o null (ese tipo de día cerrado). Fuera
 *     de él es `calendar_outside_own_hours`, de N5 (L9-15, DF-W5), y solo se
 *     mira cuando la ley ya se cumple;
 *  3. el horario de la ESTRATEGIA (N5): `{days: [1..6], windows: [{from, to}]}`
 *     en minutos de Bogotá; solo estrecha (una franja que se sale de N1 cuenta
 *     solo en su parte dentro de N1).
 *
 * MOTIVOS DE N1 (DF-W2): en domingo solo `calendar_sunday`; en festivo
 * `calendar_holiday` (y `calendar_sunday` si además es domingo);
 * `calendar_outside_legal_hours` solo en un día hábil fuera de la franja de N1.
 *
 * EL PRIMER HUECO (DF-W3, {@see nextSlot()}): si el instante ya está dentro, es
 * él mismo (sin redondear); si no, la apertura siguiente al minuto exacto,
 * saltando domingos, festivos y días sin franja.
 */
final class ContactCalendar
{
    public const ZONE = RelativeTime::ZONE;

    /** Día ISO (1 = lunes … 6 = sábado) → [abre, cierra) en minutos. El domingo no está. */
    public const N1_WINDOWS = [1 => [480, 1140], 2 => [480, 1140], 3 => [480, 1140], 4 => [480, 1140], 5 => [480, 1140], 6 => [480, 900]];

    public const OUTSIDE_LEGAL_HOURS = 'calendar_outside_legal_hours';

    public const SUNDAY = 'calendar_sunday';

    public const HOLIDAY = 'calendar_holiday';

    /** L9-15: el horario propio tiene su código, en N5 (la traza distingue la ley de la política propia). */
    public const OUTSIDE_OWN_HOURS = 'calendar_outside_own_hours';

    /** El horario propio mal configurado (todo fuera). */
    public const OWN_INVALID = 'invalid';

    /** Hasta dónde se busca un hueco (la ventana de una inscripción es ≤ 60 días; holgura para festivos). */
    public const SEARCH_DAYS = 400;

    /**
     * La ventana de un instante: los motivos de N1 (la ley ∩ el silencio) y,
     * solo si la ley se cumple, el de N5 (el horario propio, L9-15).
     *
     * @return array{inside: bool, n1: list<string>, n5: list<string>}
     */
    public static function window(DateTimeInterface $at, mixed $ownHours): array
    {
        $local = RelativeTime::local($at);
        $dia = (int) $local->format('N');
        $n1 = [];
        if ($dia === 7) {
            $n1[] = self::SUNDAY;
        }
        if (ColombianHolidays::isHolidayDate($local->format('Y-m-d'))) {
            $n1[] = self::HOLIDAY;
        }
        $segundo = self::secondOfDay($local);
        if ($n1 === [] && ! self::insideAny($segundo, self::dayIntervals($dia, null, null))) {
            $n1[] = self::OUTSIDE_LEGAL_HOURS;
        }
        $n5 = [];
        if ($n1 === [] && $ownHours !== null && ! self::insideAny($segundo, self::dayIntervals($dia, $ownHours, null))) {
            $n5[] = self::OUTSIDE_OWN_HOURS;
        }

        return ['inside' => $n1 === [] && $n5 === [], 'n1' => $n1, 'n5' => $n5];
    }

    /**
     * ¿Permite el horario de la estrategia este instante (dentro de N1 y del
     * propio)? Sin horario de la estrategia, lo que diga N1.
     */
    public static function allows(DateTimeInterface $at, mixed $ownHours, ?array $schedule): bool
    {
        $local = RelativeTime::local($at);
        $dia = (int) $local->format('N');
        if ($dia === 7 || ColombianHolidays::isHolidayDate($local->format('Y-m-d'))) {
            return false;
        }

        return self::insideAny(self::secondOfDay($local), self::dayIntervals($dia, $ownHours, $schedule));
    }

    /**
     * El primer instante ≥ `$from` permitido por las tres capas, en UTC al
     * segundo; null si no hay ninguno en {@see SEARCH_DAYS} días (p. ej. un
     * horario propio mal configurado).
     */
    public static function nextSlot(DateTimeInterface $from, mixed $ownHours, ?array $schedule = null): ?DateTimeImmutable
    {
        $inicio = RelativeTime::utc($from);
        // `utc()` quita los microsegundos; una fracción se redondea hacia ARRIBA (nunca antes de lo pedido).
        if ((int) $from->format('u') > 0) {
            $inicio = $inicio->modify('+1 second');
        }
        $local = RelativeTime::local($inicio);
        $zona = new DateTimeZone(self::ZONE);
        $hoy = DateTimeImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), $zona);
        $algunDia = false;
        foreach (array_keys(self::N1_WINDOWS) as $d) {
            $algunDia = $algunDia || self::dayIntervals($d, $ownHours, $schedule) !== [];
        }
        if (! $algunDia) {
            // Ningún día de la semana tiene franja (horario propio mal configurado o estrategia sin días): no hay hueco.
            return null;
        }
        for ($i = 0; $i <= self::SEARCH_DAYS; $i++) {
            $fecha = $hoy->modify("+{$i} days");
            $dia = (int) $fecha->format('N');
            if ($dia === 7 || ColombianHolidays::isHolidayDate($fecha->format('Y-m-d'))) {
                continue;
            }
            $desde = $i === 0 ? self::secondOfDay($local) : 0;
            foreach (self::dayIntervals($dia, $ownHours, $schedule) as [$abre, $cierra]) {
                if ($cierra * 60 <= $desde) {
                    continue;
                }

                return RelativeTime::utc($fecha->modify('+'.max($abre * 60, $desde).' seconds'));
            }
        }

        return null;
    }

    /**
     * Las franjas permitidas de un día ISO (1–6): N1 ∩ propio ∩ estrategia,
     * como `[abre, cierra)` en minutos, ordenadas y sin solapes.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function dayIntervals(int $dia, mixed $ownHours, ?array $schedule): array
    {
        $n1 = self::N1_WINDOWS[$dia] ?? null;
        if ($n1 === null || $ownHours === false || $ownHours === self::OWN_INVALID) {
            return [];
        }
        $franjas = [$n1];
        if ($ownHours !== null) {
            $propia = is_array($ownHours) ? ($dia === 6 ? ($ownHours['saturday'] ?? null) : ($ownHours['weekdays'] ?? null)) : null;
            if (! self::isWindow($propia)) {
                return [];
            }
            $franjas = self::intersect($franjas, [[$propia[0], $propia[1]]]);
        }
        if ($schedule !== null) {
            $dias = $schedule['days'] ?? [];
            if (! is_array($dias) || ! in_array($dia, $dias, true)) {
                return [];
            }
            $suyas = [];
            foreach ($schedule['windows'] ?? [] as $w) {
                if (is_array($w) && self::isWindow([$w['from'] ?? null, $w['to'] ?? null])) {
                    $suyas[] = [$w['from'], $w['to']];
                }
            }
            $franjas = self::intersect($franjas, self::merge($suyas));
        }

        return $franjas;
    }

    /**
     * La intersección de dos uniones de intervalos [a, b), ordenada.
     *
     * @param  list<array{0:int,1:int}>  $a
     * @param  list<array{0:int,1:int}>  $b
     * @return list<array{0:int,1:int}>
     */
    public static function intersect(array $a, array $b): array
    {
        $out = [];
        foreach ($a as [$a0, $a1]) {
            foreach ($b as [$b0, $b1]) {
                $desde = max($a0, $b0);
                $hasta = min($a1, $b1);
                if ($desde < $hasta) {
                    $out[] = [$desde, $hasta];
                }
            }
        }

        return self::merge($out);
    }

    /**
     * @param  list<array{0:int,1:int}>  $intervalos
     * @return list<array{0:int,1:int}>
     */
    private static function merge(array $intervalos): array
    {
        usort($intervalos, static fn (array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $fusion = [];
        foreach ($intervalos as $i) {
            $ultimo = count($fusion) - 1;
            if ($ultimo >= 0 && $i[0] <= $fusion[$ultimo][1]) {
                $fusion[$ultimo][1] = max($fusion[$ultimo][1], $i[1]);
            } else {
                $fusion[] = $i;
            }
        }

        return $fusion;
    }

    private static function isWindow(mixed $w): bool
    {
        return is_array($w) && count($w) === 2 && is_int($w[0] ?? null) && is_int($w[1] ?? null) && $w[0] >= 0 && $w[1] <= 1440 && $w[0] < $w[1];
    }

    /** @param  list<array{0:int,1:int}>  $intervalos */
    private static function insideAny(int $segundo, array $intervalos): bool
    {
        foreach ($intervalos as [$abre, $cierra]) {
            if ($segundo >= $abre * 60 && $segundo < $cierra * 60) {
                return true;
            }
        }

        return false;
    }

    private static function secondOfDay(DateTimeImmutable $local): int
    {
        return (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');
    }
}
