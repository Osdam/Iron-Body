<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nutrition\NutritionGoalRequest;
use App\Models\Member;
use App\Services\Nutrition\NutritionGoalService;
use App\Services\RealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meta nutricional personalizada (estilo Fitia). El BACKEND es la única
 * autoridad del cálculo (BMR/TDEE/macros vía NutritionGoalCalculatorService);
 * la app captura datos, muestra preview y pinta el resultado, pero nunca calcula
 * ni hardcodea la meta.
 *
 * member_id SIEMPRE desde auth.member.
 */
class NutritionGoalController extends Controller
{
    public function __construct(private readonly NutritionGoalService $service)
    {
    }

    /** GET /api/nutrition/goal — meta actual o estado setup_required. */
    public function show(Request $request): JsonResponse
    {
        $member = $this->member($request);
        return response()->json(array_merge(['ok' => true], $this->service->state($member)));
    }

    /** POST /api/nutrition/goal/calculate — preview sin guardar. */
    public function calculate(NutritionGoalRequest $request): JsonResponse
    {
        $member = $this->member($request);
        $result = $this->service->preview($member, $request->overrides());
        return response()->json(array_merge(['ok' => true], $result));
    }

    /** POST /api/nutrition/goal — calcula y guarda la meta. */
    public function store(NutritionGoalRequest $request): JsonResponse
    {
        $member = $this->member($request);
        $result = $this->service->saveCalculated($member, $request->overrides());

        if (($result['status'] ?? null) === 'setup_required') {
            return response()->json(array_merge(['ok' => false], $result), 422);
        }

        RealtimeEvents::nutrition($member->id);
        return response()->json(array_merge(['ok' => true], $result), 201);
    }

    /** POST /api/nutrition/goal/recalculate — recalcula con datos actuales. */
    public function recalculate(NutritionGoalRequest $request): JsonResponse
    {
        $member = $this->member($request);
        $force = $request->boolean('force');
        $result = $this->service->recalculate($member, $request->overrides(), $force);

        $status = $result['status'] ?? null;
        if ($status === 'setup_required') {
            return response()->json(array_merge(['ok' => false], $result), 422);
        }
        if ($status === 'manual_locked') {
            return response()->json(array_merge(['ok' => false], $result), 409);
        }

        RealtimeEvents::nutrition($member->id);
        return response()->json(array_merge(['ok' => true], $result));
    }

    private function member(Request $request): Member
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        return $member;
    }
}
