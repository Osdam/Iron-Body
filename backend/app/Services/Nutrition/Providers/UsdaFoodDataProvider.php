<?php

namespace App\Services\Nutrition\Providers;

use App\Services\Nutrition\Contracts\NutritionProviderContract;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * USDA FoodData Central — alimentos genéricos (requiere API key, SOLO backend).
 * Si NUTRITION_USDA_ENABLED=false o falta la key, queda inerte (no llama) y la
 * búsqueda continúa con local/Open Food Facts. La key NUNCA se expone al cliente.
 */
class UsdaFoodDataProvider implements NutritionProviderContract
{
    public function __construct(private NutritionFoodNormalizer $normalizer)
    {
    }

    public function source(): string
    {
        return 'usda';
    }

    public function isEnabled(): bool
    {
        return (bool) config('nutrition.usda.enabled')
            && ! empty(config('nutrition.usda.api_key'));
    }

    public function lookupByBarcode(string $barcode): ?array
    {
        // USDA no resuelve por código de barras de consumo; Open Food Facts sí.
        return null;
    }

    public function searchByName(string $query, int $limit = 15): array
    {
        if (! $this->isEnabled()) {
            return [];
        }
        $base = rtrim((string) config('nutrition.usda.base_url'), '/');
        $timeout = (int) config('nutrition.search_timeout_seconds', 8);
        try {
            $resp = Http::timeout($timeout)->get("{$base}/foods/search", [
                'api_key'  => config('nutrition.usda.api_key'), // SOLO backend
                'query'    => $query,
                'pageSize' => $limit,
                'dataType' => 'Foundation,SR Legacy,Branded',
            ]);
            if (! $resp->successful()) {
                return [];
            }
            $foods = $resp->json('foods');
            if (! is_array($foods)) {
                return [];
            }
            $out = [];
            foreach ($foods as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $n = $this->normalizer->fromUsda($item);
                if ($n) {
                    $out[] = $n;
                }
            }
            return $out;
        } catch (Throwable $e) {
            Log::warning('nutrition.search.provider_failed', ['provider' => $this->source()]);
            return [];
        }
    }
}
