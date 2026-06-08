<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;
use Carbon\Carbon;

/**
 * Cost guard de la IA de Nutrición: limita el gasto (proxy = nº de llamadas).
 *  - Tope GLOBAL diario (`daily_cost_guard`).
 *  - Tope por USUARIO diario (`rate_limit_per_user`).
 * Cuenta sobre `nutrition_ai_runs` (solo ejecuciones reales que llamaron al
 * proveedor: success/timeout/rate_limited/validation_failed; no las de caché).
 */
class NutritionAiCostGuard
{
    /** @return array{allowed:bool, reason:?string} */
    public function check(?Member $member): array
    {
        $todayStart = Carbon::now('America/Bogota')->startOfDay();

        $globalCap = (int) config('nutrition.ai.daily_cost_guard', 1000);
        if ($globalCap > 0) {
            $globalCount = NutritionAiRun::where('created_at', '>=', $todayStart)->count();
            if ($globalCount >= $globalCap) {
                return ['allowed' => false, 'reason' => 'daily_cost_guard'];
            }
        }

        $userCap = (int) config('nutrition.ai.rate_limit_per_user', 20);
        if ($member && $userCap > 0) {
            $userCount = NutritionAiRun::where('member_id', $member->id)
                ->where('created_at', '>=', $todayStart)->count();
            if ($userCount >= $userCap) {
                return ['allowed' => false, 'reason' => 'rate_limit_per_user'];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }
}
