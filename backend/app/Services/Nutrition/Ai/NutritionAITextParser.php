<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;

/**
 * FLUJO 2 — Convierte texto OCR de una tabla nutricional en JSON estructurado
 * con OpenAI. No inventa: faltante=null. Útil cuando el parser determinista
 * (NutritionLabelParser) no logra estructurar bien el texto.
 */
class NutritionAITextParser
{
    public function __construct(
        private NutritionAIEnrichmentService $engine,
        private NutritionAiResponseValidator $validator,
        private NutritionDataConfidenceService $confidence,
    ) {
    }

    public function parse(Member $member, string $rawText, array $meta = []): array
    {
        $model = config('nutrition.ai.model_text_parser') ?: config('services.openai.model');

        $ctx = '';
        if (! empty($meta['product_name'])) {
            $ctx .= "Producto: {$meta['product_name']}. ";
        }
        if (! empty($meta['brand'])) {
            $ctx .= "Marca: {$meta['brand']}. ";
        }
        if (! empty($meta['barcode'])) {
            $ctx .= "Barcode: {$meta['barcode']}. ";
        }

        $messages = [
            ['role' => 'system', 'content' => NutritionAiPrompts::textParserSystem()],
            ['role' => 'user', 'content' => $ctx . "Texto OCR a estructurar:\n\"\"\"\n"
                . mb_substr($rawText, 0, 4000) . "\n\"\"\""],
        ];

        return (new NutritionAiExtractionFinisher($this->engine, $this->validator, $this->confidence))
            ->run(NutritionAiRun::MODE_OCR_TEXT, $member, $model, $messages,
                'txt:' . hash('sha256', $rawText), 'ai_text_extraction',
                ['barcode' => $meta['barcode'] ?? null]);
    }
}
