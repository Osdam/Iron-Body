<?php

namespace App\Services\Nutrition\Ai;

use App\Models\NutritionAiRun;
use Carbon\Carbon;

/**
 * Caché por hash de entrada para no re-llamar a la IA con la misma imagen/texto.
 * Se apoya en `nutrition_ai_runs` (solo resultados `success`). Reduce costo y
 * latencia de forma segura (mismo input → mismo output estructurado).
 */
class NutritionAiHashCache
{
    private const TTL_DAYS = 30;

    public function hash(string $mode, string $promptVersion, string $input): string
    {
        return hash('sha256', $mode . '|' . $promptVersion . '|' . $input);
    }

    /** Devuelve el JSON estructurado cacheado o null. */
    public function get(string $hash): ?array
    {
        if (! config('nutrition.ai.cache_enabled', true)) {
            return null;
        }
        $run = NutritionAiRun::where('input_hash', $hash)
            ->where('status', NutritionAiRun::STATUS_SUCCESS)
            ->where('created_at', '>=', Carbon::now()->subDays(self::TTL_DAYS))
            ->latest('id')->first();

        return $run?->response_json;
    }
}
