<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Los festivos nacionales de Colombia (contrato H8, D8-12 motivo `holiday` y
 * D8-15). PURA: sin configuración, base, red ni reloj (quien pregunta da la
 * fecha o el instante). La puerta de salientes la usa para no llamar un
 * festivo (Ley 2300 de 2023, art. 3).
 *
 * LA REGLA:
 *  - Ley 51 de 1983 (art. 1, que modifica el art. 177 del Código Sustantivo
 *    del Trabajo):
 *      · fijos: 1 de enero, 1 de mayo, 20 de julio, 7 de agosto, 8 y 25 de
 *        diciembre;
 *      · «Ley Emiliani»: el 6 de enero, 19 de marzo, 29 de junio, 15 de
 *        agosto, 12 de octubre, 1 y 11 de noviembre, si no caen en lunes, se
 *        trasladan al lunes siguiente (de domingo, al día después);
 *      · los móviles de Pascua: Jueves y Viernes Santo (fijos), y la Ascensión
 *        (Pascua + 39), el Corpus Christi (Pascua + 60) y el Sagrado Corazón
 *        (Pascua + 68), trasladados al lunes (Pascua + 43, + 64 y + 71).
 *  - Ley 2578 del 1 de junio de 2026 (comprobada el 03-11-2026 en la noticia
 *    del Ministerio del Interior del 05-06-2026 y en la sinopsis del Centro de
 *    Estudios Regulatorios): el 9 de julio, Nuestra Señora del Rosario de
 *    Chiquinquirá, con las disposiciones de la Ley 51 de 1983; rige desde su
 *    promulgación, así que cuenta desde 2026 (en 2026, el lunes 13 de julio).
 *    Una nota de prensa dice que está demandada ante la Corte Constitucional
 *    [por confirmar el estado]: si cae, se quita en {@see CHIQUINQUIRA_SINCE}.
 *
 * Dos festivos pueden caer el mismo lunes (p. ej. en 2038, San Pedro y el
 * Sagrado Corazón): la fecha cuenta una vez y lleva los dos nombres.
 *
 * El domingo de Pascua, el Sábado Santo, el 24 y el 31 de diciembre NO son
 * festivos (y el domingo ya queda fuera del horario legal de salientes).
 *
 * El fixture 2026–2028 lo aprueba el propietario (D8-15, P-13).
 */
final class ColombianHolidays
{
    /** El calendario de las salientes (D8-15): Bogotá, UTC−05:00 todo el año. */
    public const TIMEZONE = 'America/Bogota';

    /** La Ley 51 de 1983 rige desde el 1 de enero de 1984. */
    public const MIN_YEAR = 1984;

    public const MAX_YEAR = 2199;

    /** Ley 2578 de 2026: el primer año con el festivo de Chiquinquirá. */
    public const CHIQUINQUIRA_SINCE = 2026;

    /** Los fijos: mes-día → nombre (identificadores ASCII). */
    public const FIXED = [
        '01-01' => 'ano_nuevo',
        '05-01' => 'dia_del_trabajo',
        '07-20' => 'independencia',
        '08-07' => 'batalla_de_boyaca',
        '12-08' => 'inmaculada_concepcion',
        '12-25' => 'navidad',
    ];

    /** Los de la Ley Emiliani: mes-día de la fecha de origen → nombre. */
    public const MOVED_TO_MONDAY = [
        '01-06' => 'reyes_magos',
        '03-19' => 'san_jose',
        '06-29' => 'san_pedro_y_san_pablo',
        '08-15' => 'asuncion_de_la_virgen',
        '10-12' => 'dia_de_la_raza',
        '11-01' => 'todos_los_santos',
        '11-11' => 'independencia_de_cartagena',
    ];

    /** Ley 2578 de 2026, con la regla de la Ley 51 de 1983. */
    public const CHIQUINQUIRA = ['07-09' => 'virgen_de_chiquinquira'];

    /** Los de Pascua que no se mueven: nombre → días desde el domingo de Pascua. */
    public const EASTER_FIXED = ['jueves_santo' => -3, 'viernes_santo' => -2];

    /** Los de Pascua que van al lunes: nombre → días desde el domingo de Pascua de la fecha de origen. */
    public const EASTER_MOVED_TO_MONDAY = ['ascension' => 39, 'corpus_christi' => 60, 'sagrado_corazon' => 68];

    /**
     * Los festivos de un año: fecha `Y-m-d` → nombres (ordenados), por fecha.
     *
     * @return array<string, list<string>>
     *
     * @throws InvalidArgumentException fuera de MIN_YEAR–MAX_YEAR (un año de dos cifras es un fallo de quien llama)
     */
    public static function forYear(int $year): array
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException('Año fuera de '.self::MIN_YEAR.'–'.self::MAX_YEAR.'.');
        }

        $dias = [];
        $anotar = static function (DateTimeImmutable $fecha, string $nombre) use (&$dias): void {
            $dias[$fecha->format('Y-m-d')][] = $nombre;
        };

        foreach (self::FIXED as $mesDia => $nombre) {
            $anotar(self::date($year, $mesDia), $nombre);
        }
        $emiliani = self::MOVED_TO_MONDAY + ($year >= self::CHIQUINQUIRA_SINCE ? self::CHIQUINQUIRA : []);
        foreach ($emiliani as $mesDia => $nombre) {
            $anotar(self::nextMonday(self::date($year, $mesDia)), $nombre);
        }
        $pascua = self::easterSunday($year);
        foreach (self::EASTER_FIXED as $nombre => $desfase) {
            $anotar(self::shift($pascua, $desfase), $nombre);
        }
        foreach (self::EASTER_MOVED_TO_MONDAY as $nombre => $desfase) {
            $anotar(self::nextMonday(self::shift($pascua, $desfase)), $nombre);
        }

        ksort($dias, SORT_STRING);
        foreach ($dias as &$nombres) {
            sort($nombres, SORT_STRING);
        }
        unset($nombres);

        return $dias;
    }

    /**
     * ¿Es festivo el DÍA DE BOGOTÁ de este instante? (un instante en UTC de
     * las 03:00 del martes es aún lunes en Bogotá).
     *
     * @throws InvalidArgumentException con un año fuera de rango
     */
    public static function isHoliday(DateTimeInterface $instant): bool
    {
        $local = DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone(self::TIMEZONE));

        return array_key_exists($local->format('Y-m-d'), self::forYear((int) $local->format('Y')));
    }

    /**
     * ¿Es festivo esta fecha de calendario (`Y-m-d`, ya en Bogotá)?
     *
     * @throws InvalidArgumentException si no es una fecha real con esa forma
     */
    public static function isHolidayDate(string $date): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('La fecha tiene que ser Y-m-d y existir.');
        }

        return array_key_exists($date, self::forYear((int) $m[1]));
    }

    /**
     * El domingo de Pascua (gregoriano), con el algoritmo anónimo de
     * Meeus/Jones/Butcher: sin tablas ni extensiones (no depende de `calendar`).
     */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return self::date($year, sprintf('%02d-%02d', $mes, $dia));
    }

    /** El lunes de esa semana o el siguiente: un lunes se queda; de martes a domingo, al lunes siguiente. */
    private static function nextMonday(DateTimeImmutable $fecha): DateTimeImmutable
    {
        $diaSemana = (int) $fecha->format('N');   // 1 = lunes … 7 = domingo

        return $diaSemana === 1 ? $fecha : self::shift($fecha, 8 - $diaSemana);
    }

    private static function shift(DateTimeImmutable $fecha, int $dias): DateTimeImmutable
    {
        return $fecha->modify(($dias >= 0 ? '+' : '').$dias.' days');
    }

    /** Medianoche de esa fecha en Bogotá (la zona no cambia el día de la semana: solo se usa el calendario). */
    private static function date(int $year, string $mesDia): DateTimeImmutable
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%s', $year, $mesDia), new DateTimeZone(self::TIMEZONE));
        if ($fecha === false) {
            throw new InvalidArgumentException('Fecha imposible.');
        }

        return $fecha;
    }
}
