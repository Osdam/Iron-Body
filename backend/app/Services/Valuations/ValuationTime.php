<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Las horas de la agenda de valoraciones.
 *
 *  · Se GUARDA en UTC (columnas `timestamp`): el servidor corre en UTC.
 *  · Se PIENSA en Bogotá: el día de la semana de una regla, «hoy», «mañana», la
 *    hora que lee el socio. Bogotá no tiene horario de verano, pero se convierte
 *    siempre con la zona, nunca restando cinco horas a mano.
 *
 * Y se ESCRIBE en español sin `ext-intl`: producción no la tiene, así que
 * `translatedFormat`/`IntlDateFormatter` no sirven. Meses y «a. m.»/«p. m.» van
 * aquí, a mano.
 */
final class ValuationTime
{
    public const TZ = 'America/Bogota';

    private const MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** Años que la agenda admite (fuera de esto, 422: ni el calendario ni Carbon los esperan). */
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /**
     * Un instante que llega del CRM, en ISO 8601 ESTRICTO:
     * `YYYY-MM-DDTHH:MM[:SS][Z|±HH:MM]`. Con desfase se respeta; SIN desfase es
     * la hora de pared de Bogotá (la que ve quien la escribe). Devuelve UTC.
     *
     * Nada de `Carbon::parse` libre (revisión de seguridad V-4): aceptaba
     * «…09:00 +1 day», zonas por nombre («Asia/Tokyo») y fechas desbordadas
     * (`02-31` → marzo), y el servidor agendaba otra cosa que la enviada. Aquí
     * la cadena entera tiene que ser la forma de arriba, el día tiene que
     * existir y el año estar entre 2000 y 2100 (V-3).
     *
     * @throws ValuationException 422 `validation_failed`
     */
    public static function parseInstant(string $valor, string $campo = 'starts_at'): Carbon
    {
        // `\z` y no `$`: `$` admite un salto de línea al final.
        $patron = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:\d{2})?\z/';
        if (preg_match($patron, $valor, $m) !== 1) {
            throw self::invalid($campo);
        }
        [$anio, $mes, $dia, $hora, $minuto] = array_map('intval', array_slice($m, 1, 5));
        $segundo = (int) ($m[6] ?? 0);
        $zona = $m[7] ?? '';
        if (! self::validDate($anio, $mes, $dia) || $hora > 23 || $minuto > 59 || $segundo > 59) {
            throw self::invalid($campo);
        }
        if ($zona === 'Z') {
            $zona = '+00:00';
        } elseif ($zona !== '' && ((int) substr($zona, 1, 2) > 14 || (int) substr($zona, 4, 2) > 59)) {
            throw self::invalid($campo);
        }

        $pared = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $anio, $mes, $dia, $hora, $minuto, $segundo);
        try {
            $instante = Carbon::createFromFormat('!Y-m-d H:i:s', $pared, $zona === '' ? self::TZ : $zona);
        } catch (Throwable) {
            throw self::invalid($campo);
        }
        // Sin desbordes: lo leído es exactamente lo escrito.
        if (! $instante instanceof Carbon || $instante->format('Y-m-d H:i:s') !== $pared) {
            throw self::invalid($campo);
        }

        $utc = $instante->utc();
        if ($utc->year < self::MIN_YEAR || $utc->year > self::MAX_YEAR) {
            throw self::invalid($campo);
        }

        return $utc;
    }

    /** `YYYY-MM-DD` de calendario válido entre 2000 y 2100, o null. */
    public static function plainDate(?string $valor): ?string
    {
        if ($valor === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $valor, $m) !== 1) {
            return null;
        }

        return self::validDate((int) $m[1], (int) $m[2], (int) $m[3]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private static function validDate(int $anio, int $mes, int $dia): bool
    {
        return $anio >= self::MIN_YEAR && $anio <= self::MAX_YEAR && checkdate($mes, $dia, $anio);
    }

    /** Hoy en el gimnasio, a medianoche de Bogotá. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** El instante en Bogotá. */
    public static function local(CarbonInterface $instante): CarbonImmutable
    {
        return CarbonImmutable::instance($instante)->setTimezone(self::TZ);
    }

    /** `Y-m-d` + `HH:MM` (pared de Bogotá) → instante UTC. */
    public static function wallToUtc(string $fecha, string $hora): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $fecha.' '.substr($hora, 0, 5), self::TZ)->startOfMinute()->utc();
    }

    /** Medianoche de Bogotá de esa fecha, en UTC. */
    public static function dayStartUtc(string $fecha): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $fecha.' 00:00', self::TZ)->startOfMinute()->utc();
    }

    /** 1 = lunes … 7 = domingo, en el calendario de Bogotá. */
    public static function isoWeekday(string $fecha): int
    {
        return (int) CarbonImmutable::createFromFormat('Y-m-d', $fecha, self::TZ)->isoWeekday();
    }

    /** «24 de octubre» */
    public static function dayMonth(CarbonInterface $instante): string
    {
        $l = self::local($instante);

        return $l->day.' de '.self::MONTHS[$l->month];
    }

    /** «9:00 a. m.», «12:30 p. m.», «12:15 a. m.» */
    public static function clock(CarbonInterface $instante): string
    {
        $l = self::local($instante);
        $h = $l->hour % 12 === 0 ? 12 : $l->hour % 12;

        return $h.':'.str_pad((string) $l->minute, 2, '0', STR_PAD_LEFT).' '.($l->hour < 12 ? 'a. m.' : 'p. m.');
    }

    /** `HH:MM` de pared. */
    public static function hm(CarbonInterface $instante): string
    {
        return self::local($instante)->format('H:i');
    }

    /** «hoy», «mañana» o «el 24 de octubre», respecto al día de Bogotá de `$ahora`. */
    public static function relativeDay(CarbonInterface $instante, CarbonInterface $ahora): string
    {
        $dia = self::local($instante)->toDateString();
        $hoy = self::local($ahora)->startOfDay();

        return match ($dia) {
            $hoy->toDateString() => 'hoy',
            $hoy->addDay()->toDateString() => 'mañana',
            default => 'el '.self::dayMonth($instante),
        };
    }

    private static function invalid(string $campo): ValuationException
    {
        return new ValuationException(
            'La fecha no es válida: usa ISO 8601 (AAAA-MM-DDTHH:MM, con desfase o sin él para la hora de Bogotá) entre los años 2000 y 2100.',
            'validation_failed',
            422,
            ['errors' => [$campo => ['Fecha u hora no válida.']]],
        );
    }
}
