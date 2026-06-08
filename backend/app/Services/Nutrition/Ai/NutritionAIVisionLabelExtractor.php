<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;

/**
 * FLUJO 1 — Extracción de macros desde imagen de etiqueta (OpenAI Vision).
 * Devuelve JSON nutricional estructurado y validado. Nunca verifica ni guarda;
 * el usuario confirma/corrige en la app antes de crear el alimento.
 */
class NutritionAIVisionLabelExtractor
{
    public function __construct(
        private NutritionAIEnrichmentService $engine,
        private NutritionAiClient $client,
        private NutritionAiResponseValidator $validator,
        private NutritionDataConfidenceService $confidence,
        private NutritionAiHashCache $cache,
    ) {
    }

    public function extract(Member $member, string $imageDataUrl, ?string $barcode = null): array
    {
        $model = config('nutrition.ai.model_label_image')
            ?: (config('services.openai.vision_model') ?: config('services.openai.model'));

        $messages = [
            ['role' => 'system', 'content' => NutritionAiPrompts::labelImageSystem()],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Extrae la tabla nutricional de esta etiqueta de alimento.'
                    . ($barcode ? " Código de barras del producto: {$barcode}." : '')],
                $this->client->imageContent($imageDataUrl),
            ]],
        ];

        return (new NutritionAiExtractionFinisher($this->engine, $this->validator, $this->confidence))
            ->run(NutritionAiRun::MODE_LABEL_IMAGE, $member, $model, $messages,
                'img:' . hash('sha256', $imageDataUrl) . ($barcode ? ":{$barcode}" : ''),
                'ai_label_extraction', ['barcode' => $barcode]);
    }
}
