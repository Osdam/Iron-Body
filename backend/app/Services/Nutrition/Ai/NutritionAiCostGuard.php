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
    /** `nutrition_ai_runs.mode` del plan integral del entrenador. */
    public const INTEGRAL_PLAN = 'integral_plan';

    /**
     * Presupuesto PROPIO del plan integral (A1), separado del de nutrición de
     * los socios y ponderado por TOKENS: una generación de nivel 5 hace unas
     * 20 llamadas y vale lo que gasta, no «una llamada».
     *
     *  - `daily_tokens_budget`: tokens de todas las generaciones del día;
     *  - `member_daily_generations`: generaciones por socio y día.
     *
     * El día es el de Bogotá, convertido a UTC para comparar con `created_at`.
     * Las corridas `running` cuentan como generación (y sus tokens, al cerrar).
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function checkIntegralPlan(Member $member): array
    {
        $desde = Carbon::now('America/Bogota')->startOfDay()->utc();
        $base = fn () => NutritionAiRun::query()->where('mode', self::INTEGRAL_PLAN)->where('created_at', '>=', $desde);

        $presupuesto = (int) config('iron_ai.plan_powers.daily_tokens_budget', 3_000_000);
        if ($presupuesto > 0 && (int) $base()->sum('total_tokens') >= $presupuesto) {
            return ['allowed' => false, 'reason' => 'integral_plan_budget'];
        }

        $porSocio = (int) config('iron_ai.plan_powers.member_daily_generations', 10);
        if ($porSocio > 0 && $base()->where('member_id', $member->getKey())->count() >= $porSocio) {
            return ['allowed' => false, 'reason' => 'integral_plan_member_limit'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed:bool, reason:?string} */
    public function check(?Member $member): array
    {
        $todayStart = Carbon::now('America/Bogota')->startOfDay();

        $globalCap = (int) config('nutrition.ai.daily_cost_guard', 1000);
        if ($globalCap > 0) {
            // El plan integral del entrenador tiene su propio presupuesto
            // ({@see checkIntegralPlan()}): no gasta la cuota de nutrición de
            // los socios ni la del día.
            $globalCount = NutritionAiRun::where('created_at', '>=', $todayStart)
                ->where('mode', '!=', self::INTEGRAL_PLAN)->count();
            if ($globalCount >= $globalCap) {
                return ['allowed' => false, 'reason' => 'daily_cost_guard'];
            }
        }

        $userCap = (int) config('nutrition.ai.rate_limit_per_user', 20);
        if ($member && $userCap > 0) {
            $userCount = NutritionAiRun::where('member_id', $member->id)
                ->where('mode', '!=', self::INTEGRAL_PLAN)
                ->where('created_at', '>=', $todayStart)->count();
            if ($userCount >= $userCap) {
                return ['allowed' => false, 'reason' => 'rate_limit_per_user'];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }
}
