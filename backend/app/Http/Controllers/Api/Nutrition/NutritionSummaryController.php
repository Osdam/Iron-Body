<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
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

        return response()->json(array_merge(
            ['ok' => true],
            $this->entries->summaryPayload($member, $date),
        ));
    }
}
