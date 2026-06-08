<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Nutrition\NutritionEntryService;
use App\Services\Nutrition\NutritionStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resumen diario: totales + entradas agrupadas por comida + constancia. */
class NutritionSummaryController extends Controller
{
    public function __construct(
        private NutritionEntryService $entries,
        private NutritionStatsService $stats,
    ) {
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

    /** GET /api/nutrition/history?days=7 — historial diario + racha. */
    public function history(Request $request): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|min:1|max:31']);
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $days = (int) ($request->query('days') ?: 7);

        return response()->json([
            'ok'          => true,
            'days'        => $this->entries->historyPayload($member, $days),
            'streak_days' => $this->entries->streakDays($member),
        ]);
    }

    /** GET /api/nutrition/stats?range=week|month — constancia/adherencia real. */
    public function stats(Request $request): JsonResponse
    {
        $request->validate(['range' => 'nullable|in:week,month']);
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $range = (string) ($request->query('range') ?: 'week');

        return response()->json(array_merge(
            ['ok' => true],
            $this->stats->constancy($member, $range),
        ));
    }
}
