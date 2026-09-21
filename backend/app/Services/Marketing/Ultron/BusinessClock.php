<?php

namespace App\Services\Marketing\Ultron;

use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * QUÉ HORA ES EN EL GIMNASIO.
 *
 * El modelo no recibía la hora por ninguna vía —lo verifiqué sobre el
 * constructor entero del contexto—, así que cualquier saludo por franja salía
 * de lo que el modelo se imaginara. Y un «buenos días» a las nueve de la noche
 * es de las cosas que más delatan a un bot.
 *
 * Tampoco se le da la hora a secas: se le da ya interpretada. Si le llega
 * «21:40» tiene que decidir él qué franja es eso, y decidir es donde se
 * equivoca. Con `saludo` ya resuelto, el modelo copia en vez de calcular.
 *
 * La zona es la del negocio, no la del servidor: la aplicación corre en UTC y
 * en Neiva son cinco horas menos, así que a las 02:00 UTC de un martes aquí
 * todavía es lunes por la noche. Esa diferencia ya se pagó una vez en el
 * cierre de caja.
 */
class BusinessClock
{
    /** La misma zona que usa el resto del dominio. */
    public const TZ = Member::BUSINESS_TZ;

    /**
     * La franja del día, en la convención colombiana.
     *
     * La madrugada se saluda con «buenas noches», que es lo que dice la gente
     * a las tres de la mañana; separarla de `noche` sirve para otra cosa: a esa
     * hora no se empuja una venta.
     */
    public static function daypart(Carbon $t): string
    {
        return match (true) {
            $t->hour < 5 => 'madrugada',
            $t->hour < 12 => 'manana',
            $t->hour < 19 => 'tarde',
            default => 'noche',
        };
    }

    /** El saludo que corresponde a esa franja, ya escrito. */
    public static function greeting(string $daypart): string
    {
        return match ($daypart) {
            'manana' => 'buenos dias',
            'tarde' => 'buenas tardes',
            default => 'buenas noches',
        };
    }

    /**
     * El bloque que viaja al modelo.
     *
     * Todo en horario del gimnasio y con el día de la semana escrito: sin él,
     * «¿abren mañana?» obliga al modelo a calcular qué día es mañana, y ese
     * cálculo tampoco es suyo.
     *
     * @return array<string,string>
     */
    public static function forPrompt(?Carbon $ahora = null): array
    {
        $t = ($ahora ?? Carbon::now())->copy()->setTimezone(self::TZ);
        $franja = self::daypart($t);

        return [
            'timezone' => self::TZ,
            'iso' => $t->toIso8601String(),
            'date' => $t->toDateString(),
            'time' => $t->format('H:i'),
            'weekday' => self::DIAS[$t->dayOfWeek],
            'tomorrow' => $t->copy()->addDay()->toDateString(),
            'tomorrow_weekday' => self::DIAS[$t->copy()->addDay()->dayOfWeek],
            'daypart' => $franja,
            'greeting' => self::greeting($franja),
        ];
    }

    /** En español y sin tildes, como todo lo que cruza al modelo. */
    private const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
}
