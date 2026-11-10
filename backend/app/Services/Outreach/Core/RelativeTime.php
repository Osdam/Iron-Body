<?php

namespace App\Services\Outreach\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * El TIEMPO RELATIVO del H9 en días de Bogotá (contrato H9, D9-4
 * `date_relative`, D9-5 «Unidades»; DF-C3 y la sección `bogotaDays` de
 * `conditions-cases.json`): UNA definición de «hace N días» y «en la ventana
 * de N horas» para el evaluador, los adaptadores de hechos (B9-facts) y sus
 * prefiltros. PURA: «ahora» entra siempre como argumento.
 *
 *  - Bogotá es UTC−05:00 todo el año (sin horario de verano).
 *  - `hours`: tiempo transcurrido: el instante está en la ventana si
 *    `ahora − instante ≤ n · 3600 s`.
 *  - `days` y `weeks` (= 7 días): días de CALENDARIO de Bogotá: la fecha de
 *    Bogotá de «ahora» menos la del instante ≤ n (o n · 7). Hoy cuenta.
 *  - Lo posterior a «ahora» nunca está en una ventana (un reloj inyectado
 *    distinto del de la base no cuela el futuro).
 *  - Sin meses: son ambiguos (D9-5).
 *
 * Los instantes que salen de aquí van en UTC al segundo (`Y-m-d\TH:i:s\Z`).
 */
final class RelativeTime
{
    public const ZONE = 'America/Bogota';

    public const INSTANT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const INSTANT_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D';

    private const DATE_PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D';

    /** El instante en UTC al segundo (sin microsegundos): la forma que se guarda. */
    public static function utc(DateTimeInterface $at): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    /** `Y-m-d\TH:i:s\Z` de un instante. */
    public static function format(DateTimeInterface $at): string
    {
        return self::utc($at)->format(self::INSTANT_FORMAT);
    }

    /**
     * Un instante escrito `Y-m-d\TH:i:s\Z` (estricto: la fecha tiene que existir).
     *
     * @throws InvalidArgumentException si no tiene exactamente esa forma
     */
    public static function parseInstant(string $value): DateTimeImmutable
    {
        $instante = preg_match(self::INSTANT_PATTERN, $value) === 1
            ? DateTimeImmutable::createFromFormat('!'.self::INSTANT_FORMAT, $value, new DateTimeZone('UTC'))
            : false;
        if ($instante === false || $instante->format(self::INSTANT_FORMAT) !== $value) {
            throw new InvalidArgumentException('El instante tiene que ser Y-m-d\\TH:i:s\\Z (UTC) y existir.');
        }

        return $instante;
    }

    /** ¿Tiene la forma estricta de un instante? */
    public static function isInstant(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        try {
            self::parseInstant($value);
        } catch (InvalidArgumentException) {
            // Tragado a propósito: aquí solo se pregunta si la forma vale.
            return false;
        }

        return true;
    }

    /** El instante en la hora de Bogotá. */
    public static function local(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone(self::ZONE));
    }

    /** La fecha de Bogotá (`Y-m-d`) de un instante. */
    public static function bogotaDate(DateTimeInterface $at): string
    {
        return self::local($at)->format('Y-m-d');
    }

    /** Días de calendario de Bogotá entre la fecha del instante y la de «ahora» (≥ 0 si ya pasó). */
    public static function daysAgo(DateTimeInterface $instant, DateTimeInterface $now): int
    {
        return self::dayNumber(self::bogotaDate($now)) - self::dayNumber(self::bogotaDate($instant));
    }

    /**
     * Días de calendario de Bogotá desde hoy hasta una fecha (`Y-m-d`, ya en
     * Bogotá): positivo en el futuro, 0 hoy, negativo en el pasado (el valor de
     * `membership.days_to_expiry` y de los `date_relative` en días).
     *
     * @throws InvalidArgumentException si la fecha no es real
     */
    public static function daysUntilDate(string $date, DateTimeInterface $now): int
    {
        return self::dayNumber($date) - self::dayNumber(self::bogotaDate($now));
    }

    /** Horas COMPLETAS transcurridas desde el instante hasta «ahora» (negativas si es futuro). */
    public static function hoursSince(DateTimeInterface $instant, DateTimeInterface $now): int
    {
        return intdiv(self::utc($now)->getTimestamp() - self::utc($instant)->getTimestamp(), 3600);
    }

    /**
     * ¿Cae el instante en la ventana `{n, unit}` que termina en «ahora» (DF-C3)?
     *
     * @throws InvalidArgumentException con una unidad desconocida
     */
    public static function withinLast(DateTimeInterface $instant, DateTimeInterface $now, int $n, string $unit): bool
    {
        $t = self::utc($instant);
        $ahora = self::utc($now);
        if ($t > $ahora) {
            return false;
        }
        if ($unit === 'hours') {
            return $ahora->getTimestamp() - $t->getTimestamp() <= $n * 3600;
        }
        if (! isset(OutreachCatalog::UNIT_DAYS[$unit])) {
            throw new InvalidArgumentException('Unidad desconocida.');
        }

        return self::daysAgo($t, $ahora) <= $n * OutreachCatalog::UNIT_DAYS[$unit];
    }

    /**
     * El primer instante de esa ventana (para los prefiltros indexados:
     * `columna >= inicio AND columna <= ahora` da lo mismo que {@see withinLast()}).
     *
     * @throws InvalidArgumentException con una unidad desconocida
     */
    public static function windowStart(DateTimeInterface $now, int $n, string $unit): DateTimeImmutable
    {
        if ($unit === 'hours') {
            return self::utc($now)->modify('-'.$n.' hours');
        }
        if (! isset(OutreachCatalog::UNIT_DAYS[$unit])) {
            throw new InvalidArgumentException('Unidad desconocida.');
        }

        return self::utc(self::local($now)->setTime(0, 0)->modify('-'.($n * OutreachCatalog::UNIT_DAYS[$unit]).' days'));
    }

    /**
     * Días desde 1970-01-01 de una fecha `Y-m-d` (solo calendario: la
     * medianoche UTC de esa fecha). Sin la extensión `calendar`, que producción
     * no tiene por qué tener (como `ColombianHolidays`).
     */
    private static function dayNumber(string $date): int
    {
        if (preg_match(self::DATE_PATTERN, $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('La fecha tiene que ser Y-m-d y existir.');
        }

        // La medianoche UTC es siempre múltiplo exacto de 86 400 s (también antes de 1970).
        return intdiv(DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'))->getTimestamp(), 86400);
    }
}
