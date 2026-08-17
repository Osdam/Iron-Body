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
        private readonly WeeklyTrainingService $weekly,
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

        // La semana en curso. El histórico navegable vive en su propio endpoint
        // (`/app/progress/weekly`) para no rehacer evaluaciones, récords y racha
        // cada vez que el socio pasa de una semana a otra.
        $weeklyTraining = $this->weekly->forMember($member);

        return [
            'current_weight_kg' => $currentWeight,
            'weight_delta_kg' => $weightDelta,
            'workouts_count_month' => $workoutsMonth,
            'workouts_delta_month' => $workoutsDelta,
            'current_streak_days' => $streakSummary['current_streak_days'] ?? 0,
            'bmi' => $latest?->bmi(),
            'bmi_label' => $latest?->bmiLabel(),
            'weight_history' => $this->weightHistory($member),
            'weekly_volume' => $this->weeklyVolume($weeklyTraining),
            'weekly_training' => $weeklyTraining,
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
     * Gráfico de volumen semanal en el formato ANTIGUO, derivado del mismo
     * cálculo que `weekly_training` para que no puedan discrepar.
     *
     * Se mantiene porque las versiones de la app ya instaladas lo leen; las
     * nuevas usan `weekly_training`, que además distingue entrenar de levantar
     * kilos. Cuando no queden clientes viejos, este campo se retira.
     */
    private function weeklyVolume(array $weeklyTraining): array
    {
        return array_map(fn (array $day) => [
            'label' => $day['label'],
            'value' => (int) round($day['volume_kg']),
            'sessions' => $day['sessions'],
            'highlight' => $day['is_today'],
        ], $weeklyTraining['days']);
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
