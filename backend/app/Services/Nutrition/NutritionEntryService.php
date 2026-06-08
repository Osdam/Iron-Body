<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionDailySummary;
use App\Models\NutritionEntry;
use App\Models\NutritionFood;
use App\Models\NutritionRecentFood;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tracking diario: agrega/elimina entradas y mantiene el resumen del día. El
 * backend SIEMPRE calcula los macros finales (vía NutritionMacroCalculator);
 * nunca confía en los macros que envíe Flutter.
 */
class NutritionEntryService
{
    public const TZ = 'America/Bogota';

    public function __construct(private NutritionMacroCalculator $calculator)
    {
    }

    public function today(): string
    {
        return Carbon::now(self::TZ)->toDateString();
    }

    /** Agrega un alimento a una comida. Recalcula resumen y recientes. */
    public function addEntry(
        Member $member,
        NutritionFood $food,
        string $mealType,
        ?string $date,
        float $quantity,
        string $unit
    ): NutritionEntry {
        $mealType = in_array($mealType, NutritionEntry::MEAL_TYPES, true) ? $mealType : 'snack';
        $date = $this->normalizeDate($date);
        $macros = $this->calculator->calculateForQuantity($food, $quantity, $unit);

        $entry = DB::transaction(function () use ($member, $food, $mealType, $date, $quantity, $unit, $macros) {
            $entry = NutritionEntry::create([
                'member_id'          => $member->id,
                'food_id'            => $food->id,
                'meal_type'          => $mealType,
                'entry_date'         => $date,
                'quantity'           => max(0, $quantity),
                'unit'               => $unit,
                'serving_multiplier' => $macros['serving_multiplier'],
                'calories'           => $macros['calories'],
                'protein'            => $macros['protein'],
                'carbs'              => $macros['carbs'],
                'fat'                => $macros['fat'],
                'sugar'              => $macros['sugar'],
                'fiber'              => $macros['fiber'],
                'sodium'             => $macros['sodium'],
                'saturated_fat'      => $macros['saturated_fat'],
            ]);
            $this->updateRecentFood($member, $food);
            $this->recalculateDailySummary($member, $date);
            return $entry;
        });

        Log::info('nutrition.entry.created', [
            'member_id' => $member->id, 'meal' => $mealType, 'date' => $date,
        ]);
        return $entry;
    }

    /** Elimina una entrada del miembro y recalcula el resumen. */
    public function deleteEntry(Member $member, NutritionEntry $entry): void
    {
        if ($entry->member_id !== $member->id) {
            return; // no es del miembro: no se toca
        }
        $date = $entry->entry_date instanceof Carbon
            ? $entry->entry_date->toDateString()
            : (string) $entry->entry_date;
        DB::transaction(function () use ($entry, $member, $date) {
            $entry->delete();
            $this->recalculateDailySummary($member, $date);
        });
    }

    /** Recalcula (upsert) el resumen diario sumando las entradas del día. */
    public function recalculateDailySummary(Member $member, string $date): NutritionDailySummary
    {
        $agg = NutritionEntry::where('member_id', $member->id)
            ->whereDate('entry_date', $date)
            ->selectRaw('count(*) as c, '
                . 'coalesce(sum(calories),0) cal, coalesce(sum(protein),0) pro, '
                . 'coalesce(sum(carbs),0) car, coalesce(sum(fat),0) fat, '
                . 'coalesce(sum(sugar),0) sug, coalesce(sum(fiber),0) fib, '
                . 'coalesce(sum(sodium),0) sod')
            ->first();

        return NutritionDailySummary::updateOrCreate(
            ['member_id' => $member->id, 'summary_date' => $date],
            [
                'calories'    => round((float) $agg->cal, 1),
                'protein'     => round((float) $agg->pro, 1),
                'carbs'       => round((float) $agg->car, 1),
                'fat'         => round((float) $agg->fat, 1),
                'sugar'       => round((float) $agg->sug, 1),
                'fiber'       => round((float) $agg->fib, 1),
                'sodium'      => round((float) $agg->sod, 1),
                'entry_count' => (int) $agg->c,
            ]
        );
    }

    /**
     * Resumen del día en el formato unificado: totales + entradas por comida.
     * Reutilizado por el endpoint /summary y por la respuesta de agregar entrada.
     */
    public function summaryPayload(Member $member, string $date): array
    {
        $entries = NutritionEntry::where('member_id', $member->id)
            ->whereDate('entry_date', $date)
            ->with('food')->orderBy('id')->get();

        $meals = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => []];
        $totals = ['calories' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        foreach ($entries as $e) {
            $meals[$e->meal_type][] = \App\Http\Controllers\Api\Nutrition\NutritionEntryController::present($e);
            $totals['calories'] += (float) $e->calories;
            $totals['protein'] += (float) $e->protein;
            $totals['carbs'] += (float) $e->carbs;
            $totals['fat'] += (float) $e->fat;
        }
        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 1);
        }

        return ['date' => $date, 'totals' => $totals, 'meals' => $meals];
    }

    /** Marca el alimento como reciente (incrementa uso). */
    public function updateRecentFood(Member $member, NutritionFood $food): void
    {
        $recent = NutritionRecentFood::firstOrNew([
            'member_id' => $member->id,
            'food_id'   => $food->id,
        ]);
        $recent->last_used_at = Carbon::now(self::TZ);
        $recent->use_count = ($recent->exists ? (int) $recent->use_count : 0) + 1;
        $recent->save();
    }

    private function normalizeDate(?string $date): string
    {
        if (! $date) {
            return $this->today();
        }
        try {
            return Carbon::parse($date, self::TZ)->toDateString();
        } catch (\Throwable $e) {
            return $this->today();
        }
    }
}
