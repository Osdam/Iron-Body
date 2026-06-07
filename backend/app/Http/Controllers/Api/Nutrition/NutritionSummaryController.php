<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionEntry;
use App\Services\Nutrition\NutritionEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resumen diario: totales + entradas agrupadas por comida. */
class NutritionSummaryController extends Controller
{
    public function __construct(private NutritionEntryService $entries)
    {
    }

    /** GET /api/nutrition/summary?date=YYYY-MM-DD */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date']);
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $date = $request->query('date') ?: $this->entries->today();

        $entries = NutritionEntry::where('member_id', $member->id)
            ->whereDate('entry_date', $date)
            ->with('food')->orderBy('id')->get();

        $meals = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => []];
        $totals = ['calories' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        foreach ($entries as $e) {
            $meals[$e->meal_type][] = NutritionEntryController::present($e);
            $totals['calories'] += (float) $e->calories;
            $totals['protein'] += (float) $e->protein;
            $totals['carbs'] += (float) $e->carbs;
            $totals['fat'] += (float) $e->fat;
        }
        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 1);
        }

        return response()->json([
            'ok'     => true,
            'date'   => $date,
            'totals' => $totals,
            'meals'  => $meals,
        ]);
    }
}
