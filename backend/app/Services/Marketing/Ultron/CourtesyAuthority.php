<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use Illuminate\Support\Carbon;

/**
 * SI EL DÍA Y LA HORA QUE PIDE LA PERSONA SE PUEDEN REGISTRAR.
 *
 * Decide Laravel, no el modelo. El modelo propone «el sábado a las 6» y aquí
 * se contrasta contra el horario aprobado del gimnasio, el calendario y la
 * hora real de Neiva. Es el mismo reparto de siempre: la INTENCIÓN la lee el
 * modelo, la ACCIÓN la autoriza el backend.
 *
 * Importa que sea una clase aparte y pura: lo que decide —«ese día ya
 * cerramos»— es lo que la persona va a leer, y equivocarse manda a alguien al
 * gimnasio a una hora en la que se va a encontrar la puerta cerrada.
 */
class CourtesyAuthority
{
    /** Hasta dónde se acepta una fecha. Más allá no es una visita, es un deseo. */
    private const DIAS_MAXIMOS = 90;

    public const OK = 'ok';

    /** La fecha no se entiende o no es una fecha. */
    public const FECHA_INVALIDA = 'courtesy_date_invalid';

    /** Ya pasó. Pedir cortesía para ayer no es un caso raro: es un modelo calculando mal. */
    public const FECHA_PASADA = 'courtesy_date_past';

    /** Demasiado lejos para que signifique algo. */
    public const FECHA_LEJANA = 'courtesy_date_too_far';

    /** La hora no se entiende. */
    public const HORA_INVALIDA = 'courtesy_time_invalid';

    /** Ese día el gimnasio no abre. */
    public const DIA_CERRADO = 'courtesy_day_closed';

    /** Ese día abre, pero no a esa hora. */
    public const FUERA_DE_HORARIO = 'courtesy_time_outside_hours';

    /**
     * No hay horario que consultar.
     *
     * No es lo mismo que «está fuera de horario»: aquí no se sabe, y no
     * saberlo NO puede leerse como un sí. Quien reciba esto tiene que
     * preguntar, nunca registrar a ciegas.
     */
    public const SIN_HORARIO = 'courtesy_hours_unknown';

    private const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /**
     * @param  string|null  $fecha  YYYY-MM-DD, en el calendario del gimnasio
     * @param  string|null  $hora  HH:MM, hora de Neiva
     * @param  array<string,array{0:string,1:string}>|string  $ventanas  del horario aprobado, o SOURCE_NOT_AVAILABLE
     * @return array{ok:bool, reason:string, scheduled_at:?string, closes_at:?string, weekday:?string}
     */
    public static function decide(?string $fecha, ?string $hora, array|string $ventanas, ?Carbon $ahora = null): array
    {
        $no = fn (string $motivo, ?string $cierra = null, ?string $dia = null) => [
            'ok' => false, 'reason' => $motivo, 'scheduled_at' => null, 'closes_at' => $cierra, 'weekday' => $dia,
        ];

        $ahora = ($ahora ?? Carbon::now())->copy()->setTimezone(BusinessClock::TZ);

        $dia = self::fechaDe($fecha, $ahora);
        if ($dia === null) {
            return $no(self::FECHA_INVALIDA);
        }
        if ($dia->isBefore($ahora->copy()->startOfDay())) {
            return $no(self::FECHA_PASADA);
        }
        // `abs`: según la versión de Carbon la diferencia viene con signo, y sin
        // esto una fecha a cien días salía negativa y pasaba el límite.
        if (abs($ahora->copy()->startOfDay()->diffInDays($dia)) > self::DIAS_MAXIMOS) {
            return $no(self::FECHA_LEJANA);
        }

        $minutos = self::minutosDe($hora);
        if ($minutos === null) {
            return $no(self::HORA_INVALIDA);
        }

        $nombreDia = self::DIAS[$dia->dayOfWeek];

        // Sin horario no se decide: se pregunta.
        if (! is_array($ventanas) || $ventanas === []) {
            return $no(self::SIN_HORARIO, null, $nombreDia);
        }

        $ventana = $ventanas[$nombreDia] ?? null;
        if (! is_array($ventana) || count($ventana) < 2) {
            return $no(self::DIA_CERRADO, null, $nombreDia);
        }

        /*
         * SE LEE POR POSICIÓN, NUNCA POR ÍNDICE LITERAL.
         *
         * `count() >= 2` lo cumple igual `{"open":"05:00","close":"22:00"}`,
         * que es como cualquiera escribiría esto a mano: la `metadata` del
         * panel se valida como «array» y nada más, así que la forma la acierta
         * quien la teclea. Con `$ventana[0]` eso no era un dato raro: era un
         * warning, y Laravel los convierte en excepción. Esto corre DENTRO de
         * las herramientas, después de persistir la acción, así que el turno
         * moría mudo con la fila ya escrita —el incidente de los turnos sin
         * desenlace, otra vez— y no moría uno: el horario es global, así que
         * se caían todas las conversaciones que llegaran a pedir cortesía.
         */
        [$desde, $hasta] = array_values($ventana);

        $abre = self::minutosDe(is_scalar($desde) ? (string) $desde : null);
        $cierra = self::minutosDe(is_scalar($hasta) ? (string) $hasta : null);
        if ($abre === null || $cierra === null) {
            return $no(self::SIN_HORARIO, null, $nombreDia);
        }

        // Una ventana al revés no es una ventana. No se adivina cuál de los
        // dos era la apertura: sin horario fiable se pregunta, no se decide.
        if ($abre >= $cierra) {
            return $no(self::SIN_HORARIO, null, $nombreDia);
        }

        if ($minutos < $abre || $minutos > $cierra) {
            return $no(self::FUERA_DE_HORARIO, (string) $hasta, $nombreDia);
        }

        $cuando = $dia->copy()->setTime(intdiv($minutos, 60), $minutos % 60);

        // Una hora de hoy que ya pasó es pasado igual que una fecha de ayer.
        if ($cuando->isBefore($ahora)) {
            return $no(self::FECHA_PASADA, (string) $hasta, $nombreDia);
        }

        return [
            'ok' => true,
            'reason' => self::OK,
            'scheduled_at' => $cuando->toIso8601String(),
            'closes_at' => (string) $hasta,
            'weekday' => $nombreDia,
        ];
    }

    /** Sólo se acepta una fecha ya resuelta: «mañana» lo traduce quien habla con la persona. */
    private static function fechaDe(?string $fecha, Carbon $ahora): ?Carbon
    {
        if ($fecha === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fecha)) !== 1) {
            return null;
        }

        try {
            $d = Carbon::createFromFormat('Y-m-d', trim($fecha), BusinessClock::TZ);
        } catch (\Throwable) {
            return null;
        }

        // `createFromFormat` acepta el 31 de febrero y lo desplaza a marzo; si
        // el texto no vuelve igual, la fecha no existía.
        return $d !== false && $d->format('Y-m-d') === trim($fecha) ? $d->startOfDay() : null;
    }

    /**
     * La fecha que la persona dijo con sus palabras, resuelta contra el reloj
     * del gimnasio: «hoy», «mañana», «pasado mañana», «esta tarde», un día de
     * la semana (el próximo que caiga, contando hoy si aún no pasó; «el
     * próximo lunes» nunca es hoy), «el 25», «el 25 de septiembre», «el
     * martes 29» o «29/09». Null si no dijo ninguna, si la que dijo no existe
     * o si dudó entre dos.
     *
     * Existe porque el estratega pidió la cortesía con fecha y hora en null
     * tras un «mañana miércoles a 2 pm» y la visita nunca se registró. Y lee
     * CLÁUSULA a cláusula ({@see Clausulas}): «el viernes no, mejor el
     * miércoles» es miércoles, «hoy en día me sirve el sábado» es sábado, y
     * «el sábado o el domingo» no es ninguno de los dos hasta que lo diga.
     */
    public static function fechaDesdeTexto(string $texto, ?Carbon $ahora = null): ?string
    {
        $hoy = ($ahora ?? Carbon::now())->copy()->setTimezone(BusinessClock::TZ)->startOfDay();

        $candidatos = [];
        // «La semana que viene, el jueves»: la semana se dice en una cláusula y el día en la siguiente.
        $arrastre = false;
        foreach (Clausulas::de(SalesAgentDecisionSchema::normalize($texto)) as $clausula) {
            // «¿Abren el domingo?» habla del gimnasio, no de cuándo viene la persona.
            if (preg_match(self::DEL_GIMNASIO_O_DEL_TRABAJO, $clausula['texto']) === 1) {
                continue;
            }
            $aqui = preg_match(self::SEMANA_SIGUIENTE, $clausula['texto']) === 1 && ! $clausula['niega'];
            $fechas = self::fechasEn($clausula['texto'], $hoy, $aqui || $arrastre);
            foreach ($fechas as $fecha) {
                $candidatos[] = [$fecha, $clausula];
            }
            $arrastre = $fechas === [] ? ($arrastre || $aqui) : false;
        }

        return Clausulas::eleccion($candidatos);
    }

    /** «La próxima semana», «la semana que viene», «de la otra semana». */
    private const SEMANA_SIGUIENTE = '/\b(la|de la|para la) (proxima|otra) semana\b|\bla semana (que viene|entrante)\b/u';

    /**
     * Una cláusula sobre el horario del gimnasio o sobre el trabajo de la
     * persona: sus días y horas no son los de la visita. «Quiero ir el sábado,
     * ¿hasta qué hora abren? ¿las 8?» no pide la visita a las 8, y «salgo del
     * trabajo a las 5» no es la hora de la cita.
     */
    private const DEL_GIMNASIO_O_DEL_TRABAJO = '/\b(abren|abre|abrimos|cierran|cierra|cerramos|atienden|atiende|horario|horarios|salgo|trabajo|trabajando|estudio|estudiando|turno|hasta que hora)\b/u';

    /**
     * Las fechas que nombra UNA cláusula, en orden; null por cada una que no
     * existe («el 31 de septiembre»).
     *
     * @return array<int,?string>
     */
    private static function fechasEn(string $c, Carbon $hoy, bool $semanaSiguiente = false): array
    {
        $fechas = [];
        // «Hoy en día» es «hoy en día», no hoy.
        $c = (string) preg_replace('/\bhoy en dia\b/u', ' ', $c);

        // «Pasado mañana», dicho también «pasao mañana», y el «o pasado» elíptico.
        for ($n = preg_match_all('/\bpasad?o\s+manana\b|\bo\s+pasad?o\b/u', $c); $n > 0; $n--) {
            $fechas[] = $hoy->copy()->addDays(2)->toDateString();
        }
        $c = (string) preg_replace('/\bpasad?o\s+manana\b|\bo\s+pasad?o\b/u', ' ', $c);

        // «Mañana» es mañana salvo cuando es la franja: «en la mañana», «esta mañana», «las mañanas».
        for ($n = preg_match_all('/(?<!la )(?<!esta )\bmanana\b/u', $c); $n > 0; $n--) {
            $fechas[] = $hoy->copy()->addDay()->toDateString();
        }
        for ($n = preg_match_all('/\b(hoy|esta (manana|tarde|noche))\b/u', $c); $n > 0; $n--) {
            $fechas[] = $hoy->toDateString();
        }

        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        $mes = '(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)';
        // Un número que es una HORA o una cuenta, no un día: «el 2 de la tarde», «el 1 a 1», «el 80%».
        // No capturadora: con un grupo aquí, el mes del «jueves 1 de octubre» caía en otro índice.
        $noEsDia = '(?!\s*(?::|am\b|pm\b|de la\b|en la\b|por la\b|en punto|hrs?\b|horas?\b|h\b|y media|y cuarto|a \d|y \d|o \d|u \d|%))';

        /*
         * «La próxima semana», «la semana que viene», «de la otra semana»:
         * el día de la semana cae en la SEMANA SIGUIENTE, no en el próximo que
         * aparezca. Leído a secas, «el miércoles de la próxima semana» dicho un
         * martes era mañana. «El otro martes» es ambiguo (¿el siguiente o el
         * de después?) y no se adivina.
         */
        $semanaSiguiente = $semanaSiguiente || preg_match(self::SEMANA_SIGUIENTE, $c) === 1
            // «el jueves de la próxima», «la otra el martes»: la semana se da por dicha junto a un día.
            || preg_match('/\b(de la|para la|la) (proxima|otra)\b(?! (vez|semana))/u', $c) === 1;

        // Día de la semana, solo o con su número («el martes 29», «el jueves 1 de octubre»). «Los lunes» es costumbre, no fecha.
        $dias = ['domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];
        preg_match_all('/(?<!los )\b(proximo |este |el |para el |el otro |otro )?(domingo|lunes|martes|miercoles|jueves|viernes|sabado)\b( que viene)?(?: (\d{1,2})\b'.$noEsDia.'(?:\s+de\s+'.$mes.')?)?/u', $c, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $objetivo = $dias[$m[2]];
            $prefijo = trim($m[1] ?? '');
            $conMes = isset($m[5]) && $m[5] !== '';
            if (($m[4] ?? '') !== '' && ((int) $m[4] > 12 || $conMes)) {
                // Con número de día (o con su mes), manda el número y el día de la semana lo confirma.
                $d = self::diaDelMes((int) $m[4], $conMes ? $meses[$m[5]] : null, $hoy);
                $fechas[] = $d !== null && $d->dayOfWeek === $objetivo ? $d->toDateString() : null;

                continue;
            }
            if (in_array($prefijo, ['el otro', 'otro'], true)) {
                $fechas[] = null;

                continue;
            }
            if ($semanaSiguiente) {
                // Lunes de la semana siguiente + el día pedido.
                $d = $hoy->copy()->startOfWeek(Carbon::MONDAY)->addWeek()->addDays(($objetivo + 6) % 7);
            } else {
                $d = $hoy->copy();
                while ($d->dayOfWeek !== $objetivo) {
                    $d->addDay();
                }
                if ($d->equalTo($hoy) && ($prefijo === 'proximo' || ($m[3] ?? '') !== '')) {
                    $d->addWeek();
                }
            }
            /*
             * «el sábado 10» sin mes: ese 10 puede ser la hora o el día. Si el
             * 10 que viene cae en sábado y no es el sábado más próximo, las dos
             * lecturas dan días distintos y no se adivina: tomar el más próximo
             * registraba el 3 de octubre a quien pidió el 10.
             */
            if (($m[4] ?? '') !== '') {
                $delNumero = self::diaDelMes((int) $m[4], null, $hoy);
                if ($delNumero !== null && $delNumero->dayOfWeek === $objetivo && $delNumero->toDateString() !== $d->toDateString()) {
                    $fechas[] = null;

                    continue;
                }
            }
            $fechas[] = $d->toDateString();
        }
        // El número que iba con el día de la semana ya se leyó (o se descartó por ser quizá la hora): no es otro «el N».
        $c = (string) preg_replace('/(?<!los )\b(domingo|lunes|martes|miercoles|jueves|viernes|sabado) \d{1,2}\b(\s+de\s+'.$mes.')?/u', ' ', $c);

        // «el 25», «para el 3», «el 25 de septiembre».
        preg_match_all('/\b(?:el|para el|este)\s+(\d{1,2})(?:\s+de\s+'.$mes.')?\b'.$noEsDia.'/u', $c, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $d = self::diaDelMes((int) $m[1], isset($m[2]) && $m[2] !== '' ? $meses[$m[2]] : null, $hoy);
            $fechas[] = $d?->toDateString();
        }

        // «29/09».
        preg_match_all('/\b(\d{1,2})\/(\d{1,2})\b/u', $c, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $mesNum = (int) $m[2];
            $d = $mesNum >= 1 && $mesNum <= 12 ? self::diaDelMes((int) $m[1], $mesNum, $hoy) : null;
            $fechas[] = $d?->toDateString();
        }

        return $fechas;
    }

    /**
     * Un día del mes resuelto hacia delante: sin mes, el próximo que caiga
     * (este mes si aún no pasó, si no el siguiente); con mes, este año o el
     * que viene si ya pasó. Null si ese día no existe en ese mes.
     */
    private static function diaDelMes(int $dia, ?int $mes, Carbon $hoy): ?Carbon
    {
        if ($dia < 1 || $dia > 31) {
            return null;
        }
        $anio = (int) $hoy->year;
        $mesFinal = $mes ?? (int) $hoy->month;
        // «el 31» en un mes de 30 días no existe; adivinar otro mes no es una lectura segura.
        if (! checkdate($mesFinal, $dia, $anio)) {
            return null;
        }
        $d = Carbon::create($anio, $mesFinal, $dia, 0, 0, 0, BusinessClock::TZ);
        if ($d->isBefore($hoy)) {
            if ($mes === null) {
                $d = $d->copy()->addMonthNoOverflow();
                if ((int) $d->day !== $dia) {
                    return null;
                }
            } else {
                if (! checkdate($mesFinal, $dia, $anio + 1)) {
                    return null;
                }
                $d = Carbon::create($anio + 1, $mesFinal, $dia, 0, 0, 0, BusinessClock::TZ);
            }
        }

        return $d;
    }

    /**
     * La hora que la persona dijo con sus palabras, en HH:MM: «2 pm», «a las
     * 2», «14:00», «2 de la tarde», «las 7 de la noche», «7 y media». Null si
     * no dijo ninguna o si dudó entre dos. Sin a. m./p. m. ni franja, una hora
     * de 1 a 6 se lee de la tarde: nadie viene a un gimnasio a las 2 de la
     * madrugada.
     *
     * Recorre TODAS las cifras, no sólo la primera: en «el 25 de septiembre a
     * las 2 pm» la primera es el día, y quedarse con ella dejaba la visita sin
     * hora. Y lee cláusula a cláusula, como la fecha: «a las 2 no puedo,
     * mejor a las 4» son las 16:00.
     */
    public static function horaDesdeTexto(string $texto): ?string
    {
        $candidatos = [];
        foreach (Clausulas::de(SalesAgentDecisionSchema::normalize($texto)) as $clausula) {
            if (preg_match(self::DEL_GIMNASIO_O_DEL_TRABAJO, $clausula['texto']) === 1) {
                continue;
            }
            foreach (self::horasEn($clausula['texto']) as $hora) {
                $candidatos[] = [$hora, $clausula];
            }
        }

        return Clausulas::eleccion($candidatos);
    }

    /**
     * Las horas que nombra UNA cláusula. Una cifra suelta —sin «a las», sin
     * a. m./p. m., sin franja y sin minutos— no es una hora («tengo 2 hijos»,
     * «el 25»), ni lo es la cifra que cuenta algo («tipo 2 meses», «3 veces
     * por semana»), ni la de un rango o una duda («de 5 a 6», «a las 5 o 6»):
     * en esos casos la cláusula no da hora. La franja puede ir antes («en la
     * noche a las 7») o después («a las 7 por la noche»).
     *
     * Con `$alternativas`, cada hora sale con sus lecturas posibles: una hora
     * sin marca («a las 8») vale por las dos mitades del día. Es lo que
     * necesita quien contrasta un TEXTO DEL MODELO con una cita: «te esperamos
     * a las 7» es verdad con una cita a las 19:00.
     *
     * @return array<int,string>|array<int,array<int,string>>
     */
    private static function horasEn(string $c, bool $alternativas = false): array
    {
        // Los números en letra, tras «a las», se leen como cifras: «a las dos de la tarde».
        $letras = ['una' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12];
        $c = (string) preg_replace_callback(
            '/\b(a las?|las|tipo|como a las?|sobre las)\s+(una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)\b/u',
            fn (array $m) => $m[1].' '.$letras[$m[2]],
            $c,
        );

        // Un rango o una duda: ninguna de sus horas es LA hora.
        if (preg_match('/\b\d{1,2}(:\d{2})?\s*(o|u)\s+(a\s+las?\s+)?\d{1,2}\b|\b(de|entre)\s+(las?\s+)?\d{1,2}(:\d{2})?\s*(a|y|hasta)\s+(las?\s+)?\d{1,2}\b/u', $c) === 1) {
            return [];
        }

        // La franja dicha en la cláusula («en la noche a las 7»), si es una sola.
        $franjas = [];
        // «Temprano» y «de la madrugada» también son la mañana: «mañana temprano a las 6» no son las 18:00.
        if (preg_match_all('/\b(?:en|por) la (manana|tarde|noche)\b|\besta (manana|tarde|noche)\b|\ben las (manana|tarde|noche)s\b|\b(temprano|tempranito|madrugada)\b/u', $c, $fs, PREG_SET_ORDER) > 0) {
            foreach ($fs as $f) {
                $franjas[] = ($f[4] ?? '') !== '' ? 'manana' : (($f[1] ?? '') !== '' ? $f[1] : ((($f[2] ?? '') !== '') ? $f[2] : $f[3]));
            }
        }
        $franjaDeLaClausula = count(array_unique($franjas)) === 1 ? $franjas[0] : null;

        $horas = [];
        // «Al mediodía» es una hora.
        for ($n = preg_match_all('/\b(al|a|el) mediodia\b/u', $c); $n > 0; $n--) {
            $horas[] = $alternativas ? ['12:00'] : '12:00';
        }
        preg_match_all(
            '/\b(?<pre>a\s+las?\s+|las\s+|tipo\s+las\s+|tipo\s+|como\s+a\s+las?\s+|sobre\s+las\s+)?(?<h>\d{1,2})'
            .'(?::(?<mm>\d{2})|\s+y\s+(?<ym>media|cuarto|[0-5]\d)|\s+menos\s+(?<menos>cuarto|cinco|diez|veinte|veinticinco))?'
            .'\s*(?<suf>am|pm|(?:de|en|por)\s+la\s+(?<fr>manana|tarde|noche|madrugada)|del\s+mediodia)?\b'
            .'(?!\s*(?:meses|mes|veces|vez|dias|dia|semanas|semana|personas|persona|horas|hora|anos|ano|kilos|kg|minutos|min|libras|cuadras)\b)/u',
            $c,
            $ms,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );
        foreach ($ms as $m) {
            $prefijo = trim((string) ($m['pre'][0] ?? ''));
            $crudo = (string) $m['h'][0];
            $h = (int) $crudo;
            $min = 0;
            $conMinutos = true;
            if (($m['mm'][0] ?? null) !== null) {
                $min = (int) $m['mm'][0];
            } elseif (($m['ym'][0] ?? null) !== null) {
                $min = match ($m['ym'][0]) {
                    'media' => 30,
                    'cuarto' => 15,
                    default => (int) $m['ym'][0],
                };
            } elseif (($m['menos'][0] ?? null) !== null) {
                // «las 5 menos cuarto» son las 4 y 45.
                $h -= 1;
                $min = 60 - ['cuarto' => 15, 'cinco' => 5, 'diez' => 10, 'veinte' => 20, 'veinticinco' => 25][$m['menos'][0]];
            } else {
                $conMinutos = false;
            }
            $sufijo = (string) ($m['suf'][0] ?? '');
            $marcada = $sufijo !== '' || $conMinutos;

            // Una cifra suelta no es una hora, y «las 2» a secas tampoco («vamos las 2 con mi hermana»).
            if (! $marcada && ($prefijo === '' || $prefijo === 'las')) {
                continue;
            }
            // Un rango abierto no es una hora: «después de las 5», «antes de las 8», «a partir de las 6».
            $antes = substr($c, 0, (int) $m[0][1]);
            if (preg_match('/\b(despues de|antes de|a partir de|desde|hasta|entre)\s*$/u', $antes) === 1
                || preg_match('/\bde\s+\d{1,2}\s*$/u', $antes) === 1) {
                continue;
            }
            if ($h < 0 || $h > 23 || $min > 59) {
                continue;
            }
            $franja = ($m['fr'][0] ?? null) ?? ($sufijo === '' ? $franjaDeLaClausula : null);
            $pm = $sufijo === 'pm' || in_array($franja, ['tarde', 'noche'], true);
            $am = $sufijo === 'am' || in_array($franja, ['manana', 'madrugada'], true);
            $ceroDelante = str_starts_with($crudo, '0');

            $hh = $h;
            if ($pm && $hh < 12) {
                $hh += 12;
            } elseif (! $am && ! $pm && $hh >= 1 && $hh <= 6 && ! $ceroDelante) {
                // «3:30» a secas, de la tarde; «03:30» con su cero es una hora de 24.
                $hh += 12;
            }
            if (($am || $franja === 'noche') && $hh === 12) {
                $hh = 0;
            }
            $principal = sprintf('%02d:%02d', $hh, $min);

            if (! $alternativas) {
                $horas[] = $principal;

                continue;
            }
            $lecturas = [$principal];
            if (! $am && ! $pm && $h >= 1 && $h <= 11 && ! $ceroDelante) {
                // Sin marca, la otra mitad del día también es una lectura.
                $lecturas[] = sprintf('%02d:%02d', ($hh + 12) % 24, $min);
            }
            $horas[] = array_values(array_unique($lecturas));
        }

        return $horas;
    }

    /**
     * Lo que un TEXTO DEL MODELO dice de fecha y hora, sin elegir: todas las
     * fechas que nombra (null = una que no se puede resolver) y todas las
     * horas, cada una con sus lecturas posibles. El lector de la persona
     * ({@see self::fechaDesdeTexto()}) elige y ante la duda calla; para
     * contrastar un borrador con una cita hace falta lo contrario, que nada de
     * lo que afirma se quede sin mirar: una fecha de más, una cláusula sobre el
     * horario o una negación no pueden convertir el contraste en un comodín.
     *
     * @return array{fechas: array<int,?string>, horas: array<int,array<int,string>>}
     */
    public static function mencionesEn(string $texto, ?Carbon $ahora = null): array
    {
        $hoy = ($ahora ?? Carbon::now())->copy()->setTimezone(BusinessClock::TZ)->startOfDay();
        $t = Clausulas::canonica(SalesAgentDecisionSchema::normalize($texto));
        $fechas = [];
        $horas = [];
        foreach (preg_split('/[,;!?¡¿()\n]+|\.(?=\s|$)/u', $t) ?: [] as $trozo) {
            foreach (self::fechasEn($trozo, $hoy, preg_match(self::SEMANA_SIGUIENTE, $trozo) === 1) as $f) {
                $fechas[] = $f;
            }
            foreach (self::horasEn($trozo, true) as $lecturas) {
                $horas[] = $lecturas;
            }
        }

        return ['fechas' => $fechas, 'horas' => $horas];
    }

    /**
     * ¿El mensaje de la persona se puede leer SIN DUDAS? Sólo entonces se
     * registra la visita con lo que dice el texto cuando el modelo no trajo la
     * fecha o la hora. Una negación, una alternativa, una corrección, un
     * rango, una aproximación, una pregunta o una cláusula sobre el gimnasio o
     * el trabajo son dudas: registrar el día equivocado manda al equipo a
     * preparar una visita que nadie pidió, y no registrar sólo cuesta una
     * pregunta más. Ante la duda, se pregunta.
     */
    public static function textoSinDudas(string $texto): bool
    {
        $t = Clausulas::canonica(SalesAgentDecisionSchema::normalize($texto));

        return preg_match('/\?|\b(no|nunca|ni|tampoco|mejor|prefiero|preferiria|sino|pero|aunque|o|u|menos|excepto|salvo|proxima|otra|otro|que viene|entrante|despues de|antes de|a partir|desde|hasta|entre|abren|abre|cierran|cierra|horario|trabajo|estudio|salgo|tipo|como a|aprox\w*|mas o menos|quizas?|tal vez|puede ser|creo)\b/u', $t) !== 1;
    }

    /** HH:MM en 24 horas, a minutos desde medianoche. */
    public static function minutosDe(?string $hora): ?int
    {
        if ($hora === null || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hora), $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
