<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionDailySummary;
use App\Models\NutritionEntry;
use Carbon\Carbon;

/**
 * Estadísticas de CONSTANCIA nutricional (server-side, datos reales). Calcula
 * adherencia, cumplimiento de metas, racha actual/mejor y una tabla por día con
 * estado (perfecto/en rango/bajo/alto/incompleto/sin registro). TZ Bogotá.
 *
 * No inventa datos: si no hay registros, devuelve estados vacíos correctos. Las
 * metas salen de config (no hay meta por usuario aún) — claramente etiquetadas.
 */
class NutritionStatsService
{
    private const TZ = 'America/Bogota';

    /** @return array estructura de constancia para el rango pedido (week|month). */
    public function constancy(Member $member, string $range = 'week'): array
    {
        $range = $range === 'month' ? 'month' : 'week';
        $days = $range === 'month' ? 30 : 7;
        $goals = $this->goals();

        $today = Carbon::now(self::TZ)->startOfDay();
        $from = $today->copy()->subDays($days - 1);

        $summaries = NutritionDailySummary::where('member_id', $member->id)
            ->whereDate('summary_date', '>=', $from->toDateString())
            ->get()
            ->keyBy(fn ($s) => (string) Carbon::parse($s->summary_date)->toDateString());

        // Tabla por día (del más antiguo al más reciente).
        $table = [];
        $registered = 0;
        $sum = ['calories' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        $daysInRange = $daysBelow = $daysAbove = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $key = $d->toDateString();
            $s = $summaries->get($key);
            $cal = $s ? (float) $s->calories : 0.0;
            $entryCount = $s ? (int) $s->entry_count : 0;

            $state = $this->dayState($entryCount, $cal, $goals['calories'], $goals['tolerance']);
            if ($entryCount > 0) {
                $registered++;
                $sum['calories'] += $cal;
                $sum['protein'] += $s ? (float) $s->protein : 0.0;
                $sum['carbs'] += $s ? (float) $s->carbs : 0.0;
                $sum['fat'] += $s ? (float) $s->fat : 0.0;
                match ($state) {
                    'perfect', 'in_range' => $daysInRange++,
                    'high' => $daysAbove++,
                    'low', 'incomplete' => $daysBelow++,
                    default => null,
                };
            }

            $table[] = [
                'date'        => $key,
                'weekday'     => $d->isoWeekday(),
                'calories'    => round($cal, 1),
                'protein'     => $s ? round((float) $s->protein, 1) : 0.0,
                'carbs'       => $s ? round((float) $s->carbs, 1) : 0.0,
                'fat'         => $s ? round((float) $s->fat, 1) : 0.0,
                'entry_count' => $entryCount,
                'state'       => $state,
                'compliance'  => $this->compliance($cal, $goals['calories'], $entryCount),
                'comment'     => $this->comment($state),
            ];
        }

        $avgDivisor = max(1, $registered);
        $averages = [
            'calories' => round($sum['calories'] / $avgDivisor, 1),
            'protein'  => round($sum['protein'] / $avgDivisor, 1),
            'carbs'    => round($sum['carbs'] / $avgDivisor, 1),
            'fat'      => round($sum['fat'] / $avgDivisor, 1),
        ];

        return [
            'range'   => $range,
            'days'    => $days,
            'goals'   => $goals,
            'summary' => [
                'days_registered'      => $registered,
                'days_total'           => $days,
                'current_streak'       => $this->currentStreak($member, $today),
                'best_streak'          => $this->bestStreak($member, $today),
                'adherence_percent'    => $days > 0 ? (int) round(100 * $daysInRange / $days) : 0,
                'days_in_range'        => $daysInRange,
                'days_below'           => $daysBelow,
                'days_above'           => $daysAbove,
            ],
            'averages'   => $averages,
            'compliance' => [
                'calories' => $this->macroCompliance($averages['calories'], $goals['calories']),
                'protein'  => $this->macroCompliance($averages['protein'], $goals['protein']),
                'carbs'    => $this->macroCompliance($averages['carbs'], $goals['carbs']),
                'fat'      => $this->macroCompliance($averages['fat'], $goals['fat']),
            ],
            'table'   => $table,
            'has_data' => $registered > 0,
        ];
    }

    /** Estado de un día según calorías vs meta. */
    private function dayState(int $entryCount, float $cal, float $goal, float $tol): string
    {
        if ($entryCount === 0) {
            return 'no_record';
        }
        if ($goal <= 0) {
            return 'in_range';
        }
        $ratio = $cal / $goal;
        if ($ratio < 0.25) {
            return 'incomplete'; // registro claramente parcial del día
        }
        if (abs($ratio - 1) <= 0.05) {
            return 'perfect';
        }
        if (abs($ratio - 1) <= $tol) {
            return 'in_range';
        }
        return $ratio < 1 ? 'low' : 'high';
    }

    private function comment(string $state): string
    {
        return match ($state) {
            'perfect'    => '¡Perfecto! Justo en tu meta.',
            'in_range'   => 'En rango, buen día.',
            'low'        => 'Por debajo de tu meta.',
            'high'       => 'Por encima de tu meta.',
            'incomplete' => 'Registro incompleto.',
            default      => 'Sin registro.',
        };
    }

    private function compliance(float $cal, float $goal, int $entryCount): ?int
    {
        if ($entryCount === 0 || $goal <= 0) {
            return null; // sin registro → no se inventa 0
        }
        return (int) round(100 * min($cal / $goal, 1.5));
    }

    private function macroCompliance(float $avg, float $goal): array
    {
        return [
            'average' => $avg,
            'goal'    => $goal,
            'percent' => $goal > 0 ? (int) round(100 * min($avg / $goal, 1.5)) : null,
        ];
    }

    /** Racha actual: días consecutivos hasta hoy con al menos una entrada. */
    private function currentStreak(Member $member, Carbon $today): int
    {
        $dates = $this->registeredDates($member, $today->copy()->subDays(120));
        $streak = 0;
        $cursor = $today->copy();
        while ($dates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }
        return $streak;
    }

    /** Mejor racha en los últimos 120 días (corrida consecutiva más larga). */
    private function bestStreak(Member $member, Carbon $today): int
    {
        $dates = $this->registeredDates($member, $today->copy()->subDays(120));
        $best = $current = 0;
        $cursor = $today->copy()->subDays(120);
        for ($i = 0; $i <= 120; $i++) {
            if ($dates->has($cursor->toDateString())) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 0;
            }
            $cursor->addDay();
        }
        return $best;
    }

    /** @return \Illuminate\Support\Collection fechas (Y-m-d) con registros. */
    private function registeredDates(Member $member, Carbon $from)
    {
        return NutritionEntry::where('member_id', $member->id)
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->distinct()->pluck('entry_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();
    }

    private function goals(): array
    {
        return [
            'calories'  => (float) config('nutrition.goals.calories', 2200),
            'protein'   => (float) config('nutrition.goals.protein', 150),
            'carbs'     => (float) config('nutrition.goals.carbs', 250),
            'fat'       => (float) config('nutrition.goals.fat', 70),
            'tolerance' => (float) config('nutrition.goals.tolerance', 0.10),
            'source'    => 'default', // aún no hay meta por usuario
        ];
    }
}
