<?php

namespace App\Services;

use App\Models\Member;
use App\Models\PhysicalEvaluation;
use App\Models\RoutineCompletion;
use Carbon\CarbonImmutable;

/**
 * Arma el resumen de "Progreso" desde fuentes REALES en PostgreSQL:
 *  - peso / IMC / composición → última evaluación física (physical_evaluations)
 *  - historial de peso → evaluaciones físicas en el tiempo
 *  - entrenamientos del mes → routine_completions
 *  - volumen semanal → routine_completions por día (lun-dom)
 *  - racha → reutiliza WeeklyStreakService (member_app_activity_days)
 *
 * Regla de oro: si no hay dato real, se devuelve null + estado vacío. NUNCA
 * se inventa 0, ni se calcula IMC sin peso/estatura (no NaN).
 */
class ProgressSummaryService
{
    public const TZ = 'America/Bogota';

    public function __construct(private readonly WeeklyStreakService $streak)
    {
    }

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
        $workoutsMonth = $this->countCompletions($member, $monthStart, $today->endOfDay());
        $workoutsPrevMonth = $this->countCompletions($member, $prevMonthStart, $monthStart->subDay()->endOfDay());
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

    private function countCompletions(Member $member, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return RoutineCompletion::query()
            ->where('member_id', $member->id)
            ->whereBetween('completed_at', [$from, $to])
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
     * Volumen semanal = entrenamientos completados por día (lun-dom) de la
     * semana actual. Si no hay ninguno, la app muestra empty state honesto.
     */
    private function weeklyVolume(Member $member, CarbonImmutable $today): array
    {
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6)->endOfDay();

        $rows = RoutineCompletion::query()
            ->where('member_id', $member->id)
            ->whereBetween('completed_at', [$weekStart, $weekEnd])
            ->get();

        // Cuenta por día de la semana (0=lunes).
        $counts = array_fill(0, 7, 0);
        foreach ($rows as $row) {
            $d = CarbonImmutable::parse($row->completed_at, self::TZ);
            $idx = (int) $weekStart->diffInDays($d->startOfDay());
            if ($idx >= 0 && $idx < 7) {
                $counts[$idx]++;
            }
        }

        $labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
        $todayIdx = (int) $weekStart->diffInDays($today);
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = [
                'label' => $labels[$i],
                'value' => $counts[$i],
                'highlight' => $i === $todayIdx,
            ];
        }
        return $out;
    }

    /**
     * Records personales reales. Hoy no existe una fuente de cargas por
     * ejercicio (peso levantado) en PostgreSQL, así que devolvemos lista vacía
     * y la app muestra empty state honesto ("Aún no tienes récords registrados")
     * en lugar de inventar valores. Cuando exista esa tabla, se conecta aquí.
     */
    private function personalRecords(Member $member): array
    {
        return [];
    }
}
