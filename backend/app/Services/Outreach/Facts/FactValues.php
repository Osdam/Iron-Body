<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Lectura de los valores CRUDOS de la base hacia valores del H9 (puro). Las
 * fechas sin hora (`date`) son días de calendario tal cual; las marcas de
 * tiempo (`timestamp`) son hora de PARED en UTC (lo que escribe `now()` en
 * producción, `config('app.timezone') = UTC`). En SQLite una `date` puede venir
 * con `00:00:00` detrás: se toman los 10 primeros caracteres, como
 * `User::getMembershipEndDateAttribute()`.
 */
final class FactValues
{
    private const DATE_PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})/';

    /** `Y-m-d` de una columna `date`, o null si no es una fecha real. */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::DATE_PATTERN, $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** El instante de una columna `timestamp` (hora de pared UTC), o null. */
    public static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            // Solo «Y-m-d H:i:s» (con fracción opcional): la forma que guardan los dos motores.
            $limpio = substr($value, 0, 19);
            $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $limpio, new DateTimeZone('UTC'));
        } catch (Throwable) {
            // Tragado a propósito: un valor ilegible es un hecho DESCONOCIDO, nunca un error de la pasada.
            return null;
        }

        return $t === false ? null : $t;
    }

    /** La marca de tiempo en el formato con el que se compara en SQL (hora de pared UTC). */
    public static function sql(DateTimeImmutable $at): string
    {
        return RelativeTime::utc($at)->format('Y-m-d H:i:s');
    }

    /** `Y-m-d` + N días de calendario. */
    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /** El primer instante (UTC) del día de Bogotá `Y-m-d`. */
    public static function startOfBogotaDay(string $date): DateTimeImmutable
    {
        return RelativeTime::utc(new DateTimeImmutable($date.' 00:00:00', new DateTimeZone(RelativeTime::ZONE)));
    }

    public static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /**
     * Un valor del dominio dinámico `class_types`: minúsculas sin tildes, lo
     * demás a guiones, como mucho 40, y empezando por LETRA (la forma de un valor
     * de dominio de `Core\FactSnapshot`). null si no queda nada que valga.
     */
    public static function slug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $texto = strtr(mb_strtolower(trim($value)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $texto = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $texto), '-_');
        $texto = substr($texto, 0, 40);
        $texto = rtrim($texto, '-_');

        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $texto) === 1 ? $texto : null;
    }
}
