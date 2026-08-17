<?php

namespace App\Services;

use App\Models\Member;
use App\Models\PhysicalEvaluation;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;

/**
 * Arma el resumen de "Progreso" desde fuentes REALES en PostgreSQL:
 *  - peso / IMC / composición → última evaluación física (physical_evaluations)
 *  - historial de peso → evaluaciones físicas en el tiempo
 *  - entrenamientos y volumen → workout_sessions + workout_session_sets
 *  - récords personales → personal_records (derivados de las series)
 *  - racha → reutiliza WeeklyStreakService (member_app_activity_days)
 *
 * Regla de oro: si no hay dato real, se devuelve null + estado vacío. NUNCA
 * se inventa 0, ni se calcula IMC sin peso/estatura (no NaN).
 *
 * ZONA HORARIA: el backend corre en UTC y el gimnasio opera en America/Bogota.
 * Los límites de rango se construyen en hora local y se convierten a UTC ANTES
 * de consultar: Laravel serializa un Carbon en SU propia zona, así que pasar un
 * límite en Bogotá contra una columna UTC descartaba todo lo ocurrido entre las
 * 19:00 y la medianoche — por eso un entrenamiento de la noche no aparecía.
 */
class ProgressSummaryService
{
    public const TZ = 'America/Bogota';

    public function __construct(
        private readonly WeeklyStreakService $streak,
        private readonly PersonalRecordService $records,
    ) {}

    public function build(Member $member): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();

        $latest = $this->latestEvaluation($member);
        $previous = $this->previousEvaluation($member, $latest);

        $currentWeight = $latest?->weight_kg;
        $weightDelta = ($currentWeight !== null && $previous?->weight_kg !== null)
            ? round($currentWeight - $previous->weight_kg, 1)
            : null;

        // Entrenamientos: mes actual vs mes anterior (datos reales).
        $monthStart = $today->startOfMonth();
        $prevMonthStart = $monthStart->subMonth();
        $workoutsMonth = $this->countSessions($member, $monthStart, $today->endOfDay());
        $workoutsPrevMonth = $this->countSessions($member, $prevMonthStart, $monthStart->subDay()->endOfDay());
        $workoutsDelta = $workoutsMonth - $workoutsPrevMonth;

        // Racha: del módulo weekly-streak (fuente única de verdad).
        $streakSummary = $this->streak->summary($member);

        return [
            'current_weight_kg' => $currentWeight,
            'weight_delta_kg' => $weightDelta,
            'workouts_count_month' => $workoutsMonth,
            'workouts_delta_month' => $workoutsDelta,
            'current_streak_days' => $streakSummary['current_streak_days'] ?? 0,
            'bmi' => $latest?->bmi(),
            'bmi_label' => $latest?->bmiLabel(),
            'weight_history' => $this->weightHistory($member),
            'weekly_volume' => $this->weeklyVolume($member, $today),
            'weekly_training' => $this->weeklyTraining($member, $today),
            'personal_records' => $this->personalRecords($member),
            'last_evaluation' => $latest?->toPublicArray(),
            'has_evaluation' => $latest !== null,
        ];
    }

    private function latestEvaluation(Member $member): ?PhysicalEvaluation
    {
        return PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function previousEvaluation(Member $member, ?PhysicalEvaluation $latest): ?PhysicalEvaluation
    {
        if ($latest === null) {
            return null;
        }

        return PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->where('id', '!=', $latest->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Sesiones REALMENTE completadas en el rango. Se cuenta `workout_sessions`
     * y no `routine_completions` porque la sesión es idempotente por
     * `client_session_id`: un reintento o un doble toque no puede inflar el
     * contador.
     */
    private function countSessions(Member $member, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return WorkoutSession::query()
            ->where('member_id', $member->id)
            ->whereBetween('completed_at', [$this->utc($from), $this->utc($to)])
            ->count();
    }

    /**
     * Historial de peso (máx. 12 evaluaciones recientes con peso, cronológico).
     * Si hay menos de 2 puntos, la app muestra empty state honesto.
     */
    private function weightHistory(Member $member): array
    {
        $rows = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->whereNotNull('weight_kg')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        return $rows->map(fn (PhysicalEvaluation $e) => [
            'label' => $e->created_at?->locale('es')->isoFormat('D MMM') ?? '',
            'date' => $e->created_at?->toDateString(),
            'value' => (float) $e->weight_kg,
        ])->all();
    }

    /**
     * Volumen semanal REAL (kg levantados por día, lunes→domingo de la semana
     * en curso en hora de Bogotá).
     *
     * Cada barra trae también `sessions`, el número de entrenamientos de ese
     * día: una semana entrenada solo con peso corporal tiene volumen 0 pero NO
     * está vacía, y la app necesita distinguir ambos casos para no decir "aún
     * no registras entrenamientos" a quien sí entrenó.
     */
    private function weeklyVolume(Member $member, CarbonImmutable $today): array
    {
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $rows = WorkoutSession::query()
            ->where('member_id', $member->id)
            ->whereBetween('completed_at', [$this->utc($weekStart), $this->utc($weekEnd)])
            ->get(['completed_at', 'total_volume_kg']);

        $volume = array_fill(0, 7, 0.0);
        $sessions = array_fill(0, 7, 0);

        foreach ($rows as $row) {
            // El día se decide en hora local, no en la UTC del almacenamiento.
            $local = CarbonImmutable::parse($row->completed_at)->setTimezone(self::TZ);
            $idx = (int) $weekStart->diffInDays($local->startOfDay());
            if ($idx >= 0 && $idx < 7) {
                $volume[$idx] += (float) $row->total_volume_kg;
                $sessions[$idx]++;
            }
        }

        $labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
        $todayIdx = (int) $weekStart->diffInDays($today);
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = [
                'label' => $labels[$i],
                'value' => (int) round($volume[$i]),
                'sessions' => $sessions[$i],
                'highlight' => $i === $todayIdx,
            ];
        }

        return $out;
    }

    /**
     * Entrenamiento de la semana en curso, con el contrato EXPLÍCITO.
     *
     * La app no tiene que deducir si hubo entrenamiento mirando los kilos: una
     * sesión de peso corporal levanta 0 kg y aun así es un entrenamiento. Por
     * eso `has_sessions` y `total_sessions` viajan aparte del volumen, y cada
     * día trae su fecha, su número de sesiones y sus kilos.
     *
     * La semana va de lunes a domingo en hora del gimnasio. Un entrenamiento
     * del domingo por la noche pertenece a esa semana, no a la siguiente:
     * pasada la medianoche del lunes deja de contar aquí, que es el
     * comportamiento correcto de una vista "esta semana".
     */
    private function weeklyTraining(Member $member, CarbonImmutable $today): array
    {
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $rows = WorkoutSession::query()
            ->where('member_id', $member->id)
            ->whereBetween('completed_at', [$this->utc($weekStart), $this->utc($weekEnd)])
            ->get(['completed_at', 'total_volume_kg', 'total_sets']);

        $volume = array_fill(0, 7, 0.0);
        $sessions = array_fill(0, 7, 0);
        $sets = array_fill(0, 7, 0);

        foreach ($rows as $row) {
            $local = CarbonImmutable::parse($row->completed_at)->setTimezone(self::TZ);
            $idx = (int) $weekStart->diffInDays($local->startOfDay());
            if ($idx < 0 || $idx >= 7) {
                continue;
            }
            // Varias sesiones el mismo día se suman en la barra de ese día.
            $volume[$idx] += (float) $row->total_volume_kg;
            $sessions[$idx]++;
            $sets[$idx] += (int) $row->total_sets;
        }

        $labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
        $weekdays = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $todayIdx = (int) $weekStart->diffInDays($today);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $weekdays[$i],
                'label' => $labels[$i],
                'sessions' => $sessions[$i],
                'sets' => $sets[$i],
                'volume_kg' => round($volume[$i], 2),
                'is_today' => $i === $todayIdx,
            ];
        }

        // Contexto para que la pantalla no mienta cuando la semana está vacía
        // pero el socio SÍ ha entrenado antes: decirle "completa uno para
        // empezar" a quien lleva cinco entrenamientos es sencillamente falso.
        // Es dato real, no relleno.
        $lastEver = WorkoutSession::query()
            ->where('member_id', $member->id)
            ->orderByDesc('completed_at')
            ->first(['completed_at']);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->addDays(6)->toDateString(),
            'timezone' => self::TZ,
            // La app decide el estado vacío SOLO con esto.
            'has_sessions' => $rows->isNotEmpty(),
            'has_previous_sessions' => $lastEver !== null,
            'last_session_at' => $lastEver?->completed_at
                ? CarbonImmutable::parse($lastEver->completed_at)
                    ->setTimezone(self::TZ)->toIso8601String()
                : null,
            'total_sessions' => $rows->count(),
            'total_sets' => (int) $rows->sum('total_sets'),
            'total_volume_kg' => round((float) $rows->sum(fn ($r) => (float) $r->total_volume_kg), 2),
            'days' => $days,
        ];
    }

    /**
     * Récords personales reales, derivados de las series ejecutadas (y de los
     * que registre el entrenador, que viven en la misma tabla).
     */
    private function personalRecords(Member $member): array
    {
        return $this->records->forMember($member)
            ->map(fn ($r) => [
                'name' => $r->exercise_name,
                'value' => (float) $r->value,
                'unit' => $r->unit,
                'metric' => $r->metric,
                'metric_label' => $r->metricLabel(),
                'reps' => $r->reps,
                'achieved_at' => $r->achieved_at?->toIso8601String(),
                'source' => $r->source,
            ])
            ->all();
    }

    /**
     * Convierte un instante calculado en hora del gimnasio a UTC, que es como
     * están guardados los timestamps. Sin esto la comparación se hace contra
     * una fecha "ingenua" y se pierden 5 horas de cada día.
     */
    private function utc(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone('UTC');
    }
}
