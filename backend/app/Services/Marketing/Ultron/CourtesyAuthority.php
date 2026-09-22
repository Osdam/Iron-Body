<?php

namespace App\Services\Marketing\Ultron;

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

    /** HH:MM en 24 horas, a minutos desde medianoche. */
    public static function minutosDe(?string $hora): ?int
    {
        if ($hora === null || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hora), $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
