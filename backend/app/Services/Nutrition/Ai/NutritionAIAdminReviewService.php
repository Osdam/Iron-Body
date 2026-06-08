<?php

namespace App\Services\Nutrition\Ai;

use App\Models\NutritionAiRun;
use App\Models\NutritionFood;

/**
 * FLUJO 6 — Asistente IA de moderación para staff. SOLO sugiere (duplicados,
 * macros sospechosos, categoría/marca, calidad, estado recomendado). El staff
 * decide; la IA nunca verifica ni hace merge automático.
 */
class NutritionAIAdminReviewService
{
    public function __construct(private NutritionAIEnrichmentService $engine)
    {
    }

    public function review(NutritionFood $food): array
    {
        // Pista determinista de duplicado (no depende de IA): mismo barcode o nombre.
        $dupHint = $this->duplicateHint($food);

        $model = config('nutrition.ai.model_admin_review') ?: config('services.openai.model');
        $summary = [
            'name' => $food->name, 'brand' => $food->brand, 'category' => $food->category,
            'country' => $food->country, 'stores' => $food->stores, 'barcode' => $food->barcode,
            'source' => $food->source, 'verification_status' => $food->verification_status,
            'reports_count' => (int) $food->reports_count,
            'per_100g' => [
                'calories' => $food->calories_per_100g, 'protein' => $food->protein_per_100g,
                'carbs' => $food->carbs_per_100g, 'fat' => $food->fat_per_100g,
                'sodium' => $food->sodium_per_100g,
            ],
        ];

        $messages = [
            ['role' => 'system', 'content' => NutritionAiPrompts::adminReviewSystem()],
            ['role' => 'user', 'content' => 'Analiza este alimento del catálogo y sugiere:\n'
                . json_encode($summary, JSON_UNESCAPED_UNICODE)],
        ];

        $prep = $this->engine->prepare(NutritionAiRun::MODE_ADMIN_REVIEW, null, $model, $messages,
            'rev:' . $food->uuid . ':' . optional($food->updated_at)->timestamp,
            ['food_id' => $food->id, 'barcode' => $food->barcode]);

        if (in_array($prep['outcome'], ['disabled', 'guard'], true)) {
            return ['ok' => false, 'error_code' => $prep['error_code'],
                'duplicate_hint' => $dupHint, 'suggestions' => null];
        }
        if ($prep['outcome'] === 'provider' && $prep['raw'] === null) {
            return ['ok' => false, 'error_code' => $prep['error_code'],
                'duplicate_hint' => $dupHint, 'suggestions' => null];
        }

        $suggestions = $this->clean($prep['raw'], $dupHint);
        if ($prep['outcome'] === 'provider') {
            $this->engine->record(NutritionAiRun::MODE_ADMIN_REVIEW, null,
                ['food_id' => $food->id, 'barcode' => $food->barcode], $prep['hash'], $prep['model'],
                NutritionAiRun::STATUS_SUCCESS, $suggestions['confidence_score'] ?? null, $suggestions);
        }

        return ['ok' => true, 'cached' => $prep['outcome'] === 'cache',
            'food_uuid' => $food->uuid, 'suggestions' => $suggestions];
    }

    private function duplicateHint(NutritionFood $food): ?array
    {
        $q = NutritionFood::where('id', '!=', $food->id)->whereNull('canonical_food_id');
        if ($food->barcode) {
            $q->where('barcode', $food->barcode);
        } else {
            $q->where('normalized_name', $food->normalized_name);
        }
        $match = $q->first();
        return $match ? ['uuid' => $match->uuid, 'name' => $match->name, 'by' => $food->barcode ? 'barcode' : 'name'] : null;
    }

    private function clean(?array $raw, ?array $dupHint): array
    {
        $raw ??= [];
        $status = in_array(($raw['suggested_status'] ?? ''), ['community', 'private', 'rejected', 'pending_review'], true)
            ? $raw['suggested_status'] : 'pending_review';
        return [
            'suggested_status'     => $status,
            'is_probable_duplicate' => (bool) ($raw['is_probable_duplicate'] ?? ($dupHint !== null)),
            'duplicate_hint'       => $dupHint ?? ($raw['duplicate_hint'] ?? null),
            'suspicious_fields'    => is_array($raw['suspicious_fields'] ?? null) ? $raw['suspicious_fields'] : [],
            'suggested_category'   => $raw['suggested_category'] ?? null,
            'suggested_brand'      => $raw['suggested_brand'] ?? null,
            'looks_colombian'      => (bool) ($raw['looks_colombian'] ?? false),
            'looks_imported'       => (bool) ($raw['looks_imported'] ?? false),
            'data_quality'         => in_array(($raw['data_quality'] ?? ''), ['high', 'medium', 'low'], true) ? $raw['data_quality'] : 'medium',
            'notes'                => mb_substr((string) ($raw['notes'] ?? ''), 0, 400),
            'confidence_score'     => is_numeric($raw['confidence_score'] ?? null) ? round((float) $raw['confidence_score'], 3) : null,
            'disclaimer'           => 'Sugerencia de IA — requiere revisión del staff. No verifica automáticamente.',
        ];
    }
}
