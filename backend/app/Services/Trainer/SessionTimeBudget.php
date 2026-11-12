<?php

namespace App\Services\Trainer;

/**
 * Cuánto dura una sesión de la rutina, ESTIMADO a partir de lo que trae: series,
 * repeticiones, descansos y cambios de ejercicio. Y cómo ajustarla, sin pedir
 * otra respuesta al modelo, cuando se pasa del tiempo que el entrenador indicó.
 *
 * ES UNA ESTIMACIÓN, NO UN CRONÓMETRO. Existe para que una rutina pedida «para
 * 45 minutos» no traiga 75 minutos de trabajo con «45 minutos» en el título. No
 * mide la técnica de nadie ni sustituye el criterio del entrenador, que revisa
 * el borrador antes de publicarlo.
 *
 * SUPUESTOS (docs/iron-ia/CONTEXTO-DEL-ENTRENADOR.md §5):
 *  - calentamiento: 5 min en sesiones de 30 min o menos; 8 min en las demás
 *    (el nivel 5 ya pide «calentamiento general de 8-10 minutos»);
 *  - trabajo de una serie: 3 s por repetición (tempo controlado), entre 20 y
 *    75 s. «8-10» cuenta la media; «por lado» cuenta el doble; «30 s» o «1 min»
 *    cuentan su tiempo; sin número («al fallo») cuenta 45 s;
 *  - descanso entre series (n series, n-1 descansos): el que el socio hace DE
 *    VERDAD. La app arranca SIEMPRE un temporizador de 90 s al completar una
 *    serie (active_workout_screen.dart, `_startRest(90)`), así que un descanso
 *    menor no acorta la sesión real; uno mayor, escrito en la nota, sí la
 *    alarga. Se cuenta máx(90, rest_seconds);
 *  - cambio de ejercicio (preparar, cargar, desplazarse): 60 s;
 *  - tolerancia: hasta un 10 % por encima de lo disponible.
 */
final class SessionTimeBudget
{
    /** El temporizador de descanso que la app del socio usa entre series. */
    public const APP_REST_SECONDS = 90;

    public const TRANSITION_SECONDS = 60;

    public const SECONDS_PER_REP = 3;

    public const MIN_WORK_SECONDS = 20;

    public const MAX_WORK_SECONDS = 75;

    public const UNKNOWN_WORK_SECONDS = 45;

    public const TOLERANCE = 1.10;

    /** El mínimo de series al que se baja al ajustar: nunca a 1. */
    public const MIN_SETS_WHEN_FITTING = 2;

    /** La duración mínima que se acepta por sesión: por debajo no cabe ni el calentamiento con 4 ejercicios. */
    public const MIN_SESSION_MINUTES = 20;

    public const MAX_SESSION_MINUTES = 180;

    public static function warmupMinutes(int $sessionMinutes): int
    {
        return $sessionMinutes <= 30 ? 5 : 8;
    }

    /** Segundos de trabajo de UNA serie según cómo se escriben sus repeticiones. */
    public static function workSeconds(string $reps): int
    {
        $t = mb_strtolower(trim($reps));
        $porLado = (bool) preg_match('/(por|cada)\s+(lado|pierna|brazo)|c\/l|\bpl\b/u', $t);

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(min|minuto|minutos)\b/u', $t, $m)) {
            $s = (int) round((float) str_replace(',', '.', $m[1]) * 60);
        } elseif (preg_match('/(\d+)\s*(s|seg|segs|segundo|segundos)\b/u', $t, $m)) {
            $s = (int) $m[1];
        } elseif (preg_match_all('/\d+/', $t, $m) && $m[0] !== []) {
            $numeros = array_map('intval', $m[0]);
            $s = (int) round(array_sum($numeros) / count($numeros) * self::SECONDS_PER_REP);
        } else {
            $s = self::UNKNOWN_WORK_SECONDS;
        }

        if ($porLado) {
            $s *= 2;
        }

        return max(self::MIN_WORK_SECONDS, min(self::MAX_WORK_SECONDS, $s));
    }

    /** El descanso que el socio hace de verdad entre dos series de este ejercicio. */
    public static function effectiveRestSeconds(mixed $restSeconds): int
    {
        return is_numeric($restSeconds) ? max(self::APP_REST_SECONDS, (int) $restSeconds) : self::APP_REST_SECONDS;
    }

    /**
     * Minutos estimados de una sesión con estos ejercicios.
     *
     * @param  list<array<string,mixed>>  $exercises  con `sets`, `reps` y `rest_seconds`
     */
    public static function estimateMinutes(array $exercises, int $sessionMinutes): float
    {
        $segundos = self::warmupMinutes($sessionMinutes) * 60;

        foreach ($exercises as $e) {
            $series = max(1, (int) ($e['sets'] ?? 3));
            $segundos += $series * self::workSeconds((string) ($e['reps'] ?? '10'))
                + max(0, $series - 1) * self::effectiveRestSeconds($e['rest_seconds'] ?? null)
                + self::TRANSITION_SECONDS;
        }

        return round($segundos / 60, 1);
    }

    /**
     * Cuántos ejercicios caben en la sesión con una prescripción típica (3
     * series de 10 con el descanso de la app). Orienta al modelo; quien llama
     * le pone el techo de su nivel o de su prompt. El ajuste fino lo hace
     * {@see fit()} con lo que de verdad devuelva el modelo.
     */
    public static function exerciseCap(int $sessionMinutes): int
    {
        $porEjercicio = 3 * self::workSeconds('10') + 2 * self::APP_REST_SECONDS + self::TRANSITION_SECONDS;
        $disponible = ($sessionMinutes - self::warmupMinutes($sessionMinutes)) * 60;

        return max(1, intdiv(max(0, $disponible), $porEjercicio));
    }

    /**
     * Ajusta una sesión que no cabe, de lo más suave a lo más visible, y se
     * para EN CUANTO cabe:
     *  1. descansos de más de 90 s → 90 s (el de la app); su nota se actualiza;
     *  2. más de 3 series → 3, empezando por el último ejercicio;
     *  3. 3 series → 2, igual;
     *  4. quitar ejercicios del final, sin bajar de `$minExercises`.
     * Si ni así cabe, se devuelve lo mejor posible y `fits` = false: quien
     * llama lo dice, no lo esconde.
     *
     * @param  list<array<string,mixed>>  $exercises
     * @return array{exercises: list<array<string,mixed>>, before: float, after: float, fits: bool, changes: array<string,int>}
     */
    public static function fit(array $exercises, int $sessionMinutes, int $minExercises): array
    {
        $exercises = array_values($exercises);
        $limite = $sessionMinutes * self::TOLERANCE;
        $antes = self::estimateMinutes($exercises, $sessionMinutes);
        $cambios = ['rest' => 0, 'sets' => 0, 'removed' => 0];

        if ($antes <= $limite) {
            return ['exercises' => $exercises, 'before' => $antes, 'after' => $antes, 'fits' => true, 'changes' => $cambios];
        }

        // POR REFERENCIA: una arrow function (`fn () =>`) capturaría la lista
        // por valor al crearse y la comprobación no vería ningún ajuste.
        $cabe = function () use (&$exercises, $sessionMinutes, $limite): bool {
            return self::estimateMinutes($exercises, $sessionMinutes) <= $limite;
        };
        $orden = array_reverse(array_keys($exercises));

        foreach ($orden as $i) {
            if ($cabe()) {
                break;
            }
            $previo = $exercises[$i]['rest_seconds'] ?? null;
            if (is_numeric($previo) && (int) $previo > self::APP_REST_SECONDS) {
                $exercises[$i]['rest_seconds'] = self::APP_REST_SECONDS;
                // Las notas de los niveles 2-5 llevan el descanso escrito («Descanso 120 s»):
                // la app lo enseña, así que tiene que decir lo mismo que el dato.
                if (is_string($exercises[$i]['notes'] ?? null)) {
                    $exercises[$i]['notes'] = str_replace('Descanso '.(int) $previo.' s', 'Descanso '.self::APP_REST_SECONDS.' s', $exercises[$i]['notes']);
                }
                $cambios['rest']++;
            }
        }

        foreach ([3, self::MIN_SETS_WHEN_FITTING] as $tope) {
            foreach ($orden as $i) {
                if ($cabe()) {
                    break 2;
                }
                if ((int) ($exercises[$i]['sets'] ?? 3) > $tope) {
                    $exercises[$i]['sets'] = $tope;
                    $cambios['sets']++;
                }
            }
        }

        while (! $cabe() && count($exercises) > max(1, $minExercises)) {
            array_pop($exercises);
            $cambios['removed']++;
        }

        $despues = self::estimateMinutes($exercises, $sessionMinutes);

        return [
            'exercises' => array_values($exercises),
            'before' => $antes,
            'after' => $despues,
            'fits' => $despues <= $limite,
            'changes' => $cambios,
        ];
    }
}
