<?php

namespace App\Services\Nutrition\Ai;

/**
 * Traduce un confidence_score (0..1) a una etiqueta legible para la app y decide
 * si alcanza el umbral de autocompletado/estimación.
 */
class NutritionDataConfidenceService
{
    public function label(?float $score): string
    {
        $s = $score ?? 0;
        return match (true) {
            $s >= 0.80 => 'alta',
            $s >= 0.60 => 'media',
            default    => 'baja',
        };
    }

    public function reachesAutofill(?float $score): bool
    {
        return ($score ?? 0) >= (float) config('nutrition.ai.min_confidence_autofill', 0.70);
    }

    public function reachesEstimate(?float $score): bool
    {
        return ($score ?? 0) >= (float) config('nutrition.ai.min_confidence_estimate', 0.60);
    }
}
