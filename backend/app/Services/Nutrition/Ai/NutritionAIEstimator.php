<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;

/**
 * FLUJO 3 — Estimación controlada de un alimento SIN etiqueta (platos típicos
 * colombianos). El resultado SIEMPRE es `ai_estimated`, no verificado, privado y
 * editable. Nunca compite por ranking ni inventa barcode.
 */
class NutritionAIEstimator
{
    public function __construct(
        private NutritionAIEnrichmentService $engine,
        private NutritionAiResponseValidator $validator,
        private NutritionDataConfidenceService $confidence,
    ) {
    }

    public function estimate(Member $member, string $description, ?float $quantity = null, ?string $unit = null, ?string $context = null): array
    {
        $model = config('nutrition.ai.model_estimator') ?: config('services.openai.model');

        $qty = $quantity && $quantity > 0
            ? "Cantidad aproximada: {$quantity} " . ($unit ?: 'g') . '. '
            : '';
        $messages = [
            ['role' => 'system', 'content' => NutritionAiPrompts::estimatorSystem()],
            ['role' => 'user', 'content' => "Estima los macros de: \"{$description}\". {$qty}"
                . ($context ? "Contexto: {$context}. " : '')
                . 'Recuerda: es una ESTIMACIÓN, no un dato de etiqueta.'],
        ];

        $result = (new NutritionAiExtractionFinisher($this->engine, $this->validator, $this->confidence))
            ->run(NutritionAiRun::MODE_ESTIMATE, $member, $model, $messages,
                'est:' . hash('sha256', mb_strtolower(trim($description)) . "|{$quantity}|{$unit}"),
                'ai_estimated', []);

        // Refuerza marca de estimación + bloquea por confianza mínima.
        if ($result['ok'] ?? false) {
            $conf = (float) ($result['data']['confidence_score'] ?? 0);
            $result['data']['source'] = 'ai_estimated';
            $result['data']['verification_status'] = 'unverified';
            $result['data']['visibility'] = 'private';
            $result['data']['barcode'] = null; // jamás inventa barcode
            $result['data']['is_estimate'] = true;
            $result['data']['meets_min_confidence'] = $this->confidence->reachesEstimate($conf);
            $result['data']['explanation'] ??= 'Valores estimados por IA; confírmalos o ajústalos.';
        }
        return $result;
    }
}
