<?php

namespace App\Console\Commands;

use App\Models\NutritionEntry;
use App\Models\NutritionFood;
use App\Services\Nutrition\NutritionEntryService;
use App\Services\Nutrition\NutritionMacroCalculator;
use Illuminate\Console\Command;

/**
 * Recalcula los macros de entradas que quedaron en 0 (creadas antes del fix o
 * cuyo alimento se completó después) y reconstruye los resúmenes diarios.
 *
 * Por defecto solo toca entradas con macros en 0 (calorías=0); con --force
 * recalcula TODAS las entradas del filtro contra los macros actuales del
 * alimento. No duplica ni borra entradas.
 */
class NutritionRecalculateSummaries extends Command
{
    protected $signature = 'nutrition:recalculate-summaries
        {--member= : Limitar a un member_id}
        {--date= : Limitar a una fecha YYYY-MM-DD}
        {--food-barcode= : Limitar a entradas de un alimento por código de barras}
        {--force : Recalcular todas las entradas del filtro (no solo las de macros 0)}';

    protected $description = 'Recalcula macros de entradas en 0 y reconstruye resúmenes diarios.';

    public function handle(
        NutritionMacroCalculator $calculator,
        NutritionEntryService $entries
    ): int {
        $force = (bool) $this->option('force');

        $query = NutritionEntry::query()->with('food');
        if ($m = $this->option('member')) {
            $query->where('member_id', (int) $m);
        }
        if ($d = $this->option('date')) {
            $query->whereDate('entry_date', $d);
        }
        if ($bc = $this->option('food-barcode')) {
            $foodIds = NutritionFood::where('barcode', preg_replace('/\D/', '', (string) $bc))
                ->pluck('id');
            $query->whereIn('food_id', $foodIds);
        }
        if (! $force) {
            $query->where('calories', 0);
        }

        $updated = 0;
        $skipped = 0;
        $touched = []; // member_id|date → recalcular summary una vez

        $query->orderBy('id')->chunkById(500, function ($chunk) use (&$updated, &$skipped, &$touched, $calculator) {
            foreach ($chunk as $entry) {
                $food = $entry->food;
                if (! $food || ! $food->isMacroComplete()) {
                    $skipped++;
                    continue; // sin alimento o aún incompleto → no se inventa
                }
                $macros = $calculator->calculateForQuantity($food, (float) $entry->quantity, $entry->unit);
                $entry->forceFill([
                    'serving_multiplier' => $macros['serving_multiplier'],
                    'calories' => $macros['calories'], 'protein' => $macros['protein'],
                    'carbs' => $macros['carbs'], 'fat' => $macros['fat'],
                    'sugar' => $macros['sugar'], 'fiber' => $macros['fiber'],
                    'sodium' => $macros['sodium'], 'saturated_fat' => $macros['saturated_fat'],
                ])->save();
                $updated++;
                $date = $entry->entry_date instanceof \Carbon\Carbon
                    ? $entry->entry_date->toDateString() : (string) $entry->entry_date;
                $touched["{$entry->member_id}|{$date}"] = [$entry->member_id, $date];
            }
        });

        // Reconstruir resúmenes de los días afectados.
        foreach ($touched as [$memberId, $date]) {
            $member = \App\Models\Member::find($memberId);
            if ($member) {
                $entries->recalculateDailySummary($member, $date);
            }
        }

        $this->info("Entradas recalculadas: {$updated} · omitidas (sin macros): {$skipped} · resúmenes reconstruidos: " . count($touched));
        return self::SUCCESS;
    }
}
