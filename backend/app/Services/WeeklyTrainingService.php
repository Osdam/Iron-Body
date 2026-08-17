<?php

namespace App\Services;

use App\Models\Member;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El entrenamiento de una semana, y el historial de semanas anteriores.
 *
 * POR QUÉ NO HAY TABLA DE VOLUMEN SEMANAL
 * ---------------------------------------
 * El histórico se deriva de `workout_sessions` + `workout_session_sets`, que ya
 * son la sesión ejecutada real. Una tabla `weekly_volumes` sería una copia que
 * puede desincronizarse: bastaría corregir una serie mal registrada —o reparar
 * un timestamp, como ya hubo que hacer— para que el agregado mintiera sin que
 * nadie se entere. Una semana son 7 días de un socio: el volumen de datos no
 * justifica materializar nada. Si algún día se mide un problema real de
 * rendimiento, la cache va delante de esta clase sin cambiar el contrato.
 *
 * VOLUMEN
 * -------
 * Sale de las SERIES, no del contador denormalizado de la sesión:
 * `SUM(weight_kg × reps)` sobre las series COMPLETADAS con carga y repeticiones
 * válidas. `workout_sessions.total_volume_kg` se calcula con la misma regla al
 * guardar, pero derivarlo aquí hace que la estadística no dependa de que esa
 * columna se haya mantenido al día.
 *
 * CALENDARIO
 * ----------
 * La semana va de LUNES a DOMINGO en hora del gimnasio (America/Bogota). El
 * backend almacena en UTC, así que los límites se construyen en hora local y se
 * convierten a UTC ANTES de consultar, y el día al que pertenece cada sesión se
 * decide también en hora local. Un entrenamiento del domingo a las 23:30 es del
 * domingo, aunque en UTC ya sea lunes.
 */
class WeeklyTrainingService
{
    public const TZ = 'America/Bogota';

    private const LABELS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    private const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /**
     * Resumen de la semana que contiene `$weekStart`, con la anterior para
     * comparar. Sin `$weekStart` devuelve la semana en curso.
     *
     * Una fecha inválida o futura NO es un error: se degrada a la semana actual.
     * Navegar al futuro no aporta nada y la pantalla no tiene que defenderse de
     * ello.
     */
    public function forMember(Member $member, ?string $weekStart = null): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $currentWeek = $today->startOfWeek(CarbonImmutable::MONDAY);

        $week = $this->resolveWeek($weekStart, $currentWeek);
        $previous = $week->subWeek();

        // UNA sola consulta para las dos semanas: la actual y la de comparación.
        // Partirlas en dos viajes a la base solo para restar dos totales sería
        // gratuito y peor.
        $rows = $this->sessionsBetween($member, $previous, $week->addDays(6)->endOfDay());

        [$thisWeek, $prevWeek] = $rows->partition(
            fn (array $r) => $r['local']->gte($week),
        );

        $days = $this->days($thisWeek->all(), $week, $today);

        $isCurrent = $week->equalTo($currentWeek);
        $volume = $this->sumVolume($thisWeek->all());
        $prevVolume = $this->sumVolume($prevWeek->all());

        return [
            'week_start' => $week->toDateString(),
            'week_end' => $week->addDays(6)->toDateString(),
            'timezone' => self::TZ,
            'is_current_week' => $isCurrent,

            // La app decide el estado vacío SOLO con esto: una sesión de peso
            // corporal levanta 0 kg y sigue siendo un entrenamiento.
            'has_sessions' => $thisWeek->isNotEmpty(),
            'total_sessions' => $thisWeek->count(),
            'total_sets' => (int) $thisWeek->sum('sets'),
            'total_volume_kg' => round($volume, 2),

            // Contexto histórico para que la pantalla no mienta: a quien lleva
            // cinco entrenamientos no se le puede sugerir que empiece.
            'has_previous_sessions' => $this->hasAnySession($member),
            'last_session_at' => $this->lastSessionAt($member),

            // Navegación. Al futuro no se va; hacia atrás, solo mientras haya
            // algo que ver.
            'can_go_next' => $week->lt($currentWeek),
            'can_go_previous' => $this->hasSessionsBefore($member, $week),

            'previous_week' => [
                'week_start' => $previous->toDateString(),
                'week_end' => $previous->addDays(6)->toDateString(),
                'has_sessions' => $prevWeek->isNotEmpty(),
                'total_sessions' => $prevWeek->count(),
                'total_sets' => (int) $prevWeek->sum('sets'),
                'total_volume_kg' => round($prevVolume, 2),
            ],

            // null cuando no hay con qué comparar. Dividir por cero para
            // enseñar un "+∞ %" sería peor que no enseñar nada: la app dice
            // que no hay comparación posible.
            'volume_change_pct' => $prevVolume > 0
                ? round((($volume - $prevVolume) / $prevVolume) * 100, 1)
                : null,

            'days' => $days,
        ];
    }

    /**
     * Semana pedida. Solo se acepta una fecha entendible y no futura; cualquier
     * otra cosa cae en la semana en curso.
     */
    private function resolveWeek(?string $weekStart, CarbonImmutable $currentWeek): CarbonImmutable
    {
        if (blank($weekStart)) {
            return $currentWeek;
        }

        try {
            $asked = CarbonImmutable::parse($weekStart, self::TZ)
                ->startOfDay()
                ->startOfWeek(CarbonImmutable::MONDAY);
        } catch (\Throwable) {
            return $currentWeek;
        }

        return $asked->gt($currentWeek) ? $currentWeek : $asked;
    }

    /**
     * Sesiones del rango con su volumen y sus series REALES, agregadas en la
     * base de datos.
     *
     * Un `leftJoin` con `GROUP BY` resuelve todo en una consulta: cargar las
     * sesiones y luego pedir las series de cada una sería el N+1 clásico. El
     * `left` importa —una sesión sin series sigue siendo un entrenamiento— y el
     * índice `(member_id, completed_at)` cubre el filtro.
     *
     * @return \Illuminate\Support\Collection<int, array{local: CarbonImmutable, volume: float, sets: int}>
     */
    private function sessionsBetween(Member $member, CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        return WorkoutSession::query()
            ->leftJoin('workout_session_sets as s', 's.workout_session_id', '=', 'workout_sessions.id')
            ->where('workout_sessions.member_id', $member->id)
            ->whereBetween('workout_sessions.completed_at', [$this->utc($from), $this->utc($to)])
            ->groupBy('workout_sessions.id', 'workout_sessions.completed_at')
            ->select([
                'workout_sessions.id',
                'workout_sessions.completed_at',
                // Solo cuenta lo que el socio marcó como hecho y con carga real.
                DB::raw('COALESCE(SUM(CASE WHEN s.completed = true AND s.weight_kg > 0 AND s.reps > 0'
                    .' THEN s.weight_kg * s.reps ELSE 0 END), 0) as volume_kg'),
                DB::raw('COUNT(CASE WHEN s.completed = true THEN 1 END) as sets_done'),
            ])
            ->get()
            ->map(fn ($row) => [
                // El día se decide en hora del gimnasio, no en la UTC del
                // almacenamiento.
                'local' => CarbonImmutable::parse($row->completed_at)->setTimezone(self::TZ),
                'volume' => (float) $row->volume_kg,
                'sets' => (int) $row->sets_done,
            ]);
    }

    /**
     * Los 7 días, siempre. Un día sin entrenar viaja en 0 para que la gráfica
     * tenga la misma forma toda la semana.
     */
    private function days(array $rows, CarbonImmutable $weekStart, CarbonImmutable $today): array
    {
        $volume = array_fill(0, 7, 0.0);
        $sessions = array_fill(0, 7, 0);
        $sets = array_fill(0, 7, 0);

        foreach ($rows as $row) {
            $idx = (int) $weekStart->diffInDays($row['local']->startOfDay());
            if ($idx < 0 || $idx >= 7) {
                continue;
            }
            // Varias sesiones el mismo día se suman en la barra de ese día: no
            // se dibuja una barra por sesión.
            $volume[$idx] += $row['volume'];
            $sessions[$idx]++;
            $sets[$idx] += $row['sets'];
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => self::WEEKDAYS[$i],
                'label' => self::LABELS[$i],
                'sessions' => $sessions[$i],
                'sets' => $sets[$i],
                'volume_kg' => round($volume[$i], 2),
                'is_today' => $date->equalTo($today),
            ];
        }

        return $days;
    }

    private function sumVolume(array $rows): float
    {
        return array_sum(array_column($rows, 'volume'));
    }

    private function hasAnySession(Member $member): bool
    {
        return WorkoutSession::query()->where('member_id', $member->id)->exists();
    }

    private function hasSessionsBefore(Member $member, CarbonImmutable $week): bool
    {
        return WorkoutSession::query()
            ->where('member_id', $member->id)
            ->where('completed_at', '<', $this->utc($week))
            ->exists();
    }

    private function lastSessionAt(Member $member): ?string
    {
        $last = WorkoutSession::query()
            ->where('member_id', $member->id)
            ->orderByDesc('completed_at')
            ->value('completed_at');

        return $last !== null
            ? CarbonImmutable::parse($last)->setTimezone(self::TZ)->toIso8601String()
            : null;
    }

    /**
     * Convierte un instante calculado en hora del gimnasio a UTC, que es como
     * están guardados los timestamps. Laravel serializa un Carbon en SU propia
     * zona: pasar un límite en Bogotá contra una columna UTC descartaría lo
     * ocurrido entre las 19:00 y la medianoche.
     */
    private function utc(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone('UTC');
    }
}
