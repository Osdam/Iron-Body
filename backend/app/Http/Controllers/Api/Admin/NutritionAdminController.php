<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionAiRecommendation;
use App\Models\NutritionGoal;
use App\Services\NutritionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración de Nutrición desde el CRM (Angular SPA). Sin auth.member (el
 * panel tiene su propia auth de admin). Permite a entrenadores/admins ver la
 * meta, el día y el historial de un miembro, ajustar su meta y revisar las
 * recomendaciones IA generadas. La app del miembro consume los mismos datos.
 */
class NutritionAdminController extends Controller
{
    public function __construct(private readonly NutritionService $service)
    {
    }

    /** GET /api/admin/members/{member}/nutrition — meta + día actual + historial. */
    public function show(Member $member): JsonResponse
    {
        $day = $this->service->dayPayload($member);

        return response()->json([
            'ok' => true,
            'member' => ['id' => $member->id, 'full_name' => $member->full_name],
            'goal' => $day['goal'],
            'today' => [
                'consumed' => $day['consumed'],
                'remaining' => $day['remaining'],
                'meals' => $day['meals'],
                'has_data' => $day['has_data'],
            ],
            'streak' => $day['streak'],
            'weekly_history' => $day['weekly_history'],
        ]);
    }

    /** POST /api/admin/members/{member}/nutrition/goals — ajusta la meta. */
    public function saveGoals(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'daily_calories' => 'required|integer|min:500|max:10000',
            'protein_g' => 'required|integer|min:0|max:1000',
            'carbs_g' => 'required|integer|min:0|max:2000',
            'fat_g' => 'required|integer|min:0|max:1000',
            'goal_type' => 'nullable|string|in:lose_fat,maintain,gain_muscle',
        ]);

        $goal = $this->service->saveGoal($member, $data);

        return response()->json(['ok' => true, 'data' => $goal]);
    }

    /** GET /api/admin/members/{member}/nutrition/recommendations — historial IA. */
    public function recommendations(Member $member): JsonResponse
    {
        $items = NutritionAiRecommendation::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(fn (NutritionAiRecommendation $r) => [
                'id' => $r->id,
                'date' => $r->recommendation_date?->toDateString(),
                'summary' => $r->summary,
                'recommendation' => $r->response_json,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'data' => $items]);
    }
}
