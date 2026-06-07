<?php

namespace App\Services\Nutrition\Providers;

use App\Services\Nutrition\Contracts\NutritionProviderContract;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nutritionix — proveedor comercial (adapter PREPARADO). Deshabilitado por
 * defecto; sin app_id/app_key queda inerte. Las credenciales viven SOLO en el
 * backend. Estructura lista para FatSecret u otro comercial en el futuro.
 */
class NutritionixProvider implements NutritionProviderContract
{
    public function __construct(private NutritionFoodNormalizer $normalizer)
    {
    }

    public function source(): string
    {
        return 'nutritionix';
    }

    public function isEnabled(): bool
    {
        return (bool) config('nutrition.nutritionix.enabled')
            && ! empty(config('nutrition.nutritionix.app_id'))
            && ! empty(config('nutrition.nutritionix.app_key'));
    }

    public function lookupByBarcode(string $barcode): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }
        try {
            $resp = Http::timeout((int) config('nutrition.barcode_timeout_seconds', 8))
                ->withHeaders($this->authHeaders())
                ->get('https://trackapi.nutritionix.com/v2/search/item', ['upc' => $barcode]);
            if (! $resp->successful()) {
                return null;
            }
            $food = $resp->json('foods.0');
            return is_array($food) ? $this->fromNutritionix($food) : null;
        } catch (Throwable $e) {
            Log::warning('nutrition.barcode.lookup_failed', ['provider' => $this->source()]);
            return null;
        }
    }

    public function searchByName(string $query, int $limit = 15): array
    {
        if (! $this->isEnabled()) {
            return [];
        }
        // Adapter preparado: se completa al contratar el proveedor. Sin credenciales
        // reales no se llama (isEnabled=false), por eso aquí queda el esqueleto.
        return [];
    }

    private function authHeaders(): array
    {
        return [
            'x-app-id'  => (string) config('nutrition.nutritionix.app_id'),
            'x-app-key' => (string) config('nutrition.nutritionix.app_key'),
        ];
    }

    private function fromNutritionix(array $f): ?array
    {
        $name = trim((string) ($f['food_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        // Nutritionix entrega por porción; se normaliza a per_100g si hay gramos.
        $grams = (float) ($f['serving_weight_grams'] ?? 0);
        $factor = $grams > 0 ? 100 / $grams : null;
        $scale = fn ($v) => ($factor && is_numeric($v)) ? round((float) $v * $factor, 2) : null;

        return $this->normalizer->assemble([
            'source'       => 'nutritionix',
            'external_id'  => (string) ($f['nix_item_id'] ?? $f['tag_id'] ?? ''),
            'barcode'      => $f['upc'] ?? null,
            'name'         => $name,
            'brand'        => $f['brand_name'] ?? null,
            'serving_size' => $grams ?: null,
            'serving_unit' => $grams ? 'g' : null,
            'per_100g'     => [
                'calories' => $scale($f['nf_calories'] ?? null),
                'protein'  => $scale($f['nf_protein'] ?? null),
                'carbs'    => $scale($f['nf_total_carbohydrate'] ?? null),
                'fat'      => $scale($f['nf_total_fat'] ?? null),
                'sugar'    => $scale($f['nf_sugars'] ?? null),
                'fiber'    => $scale($f['nf_dietary_fiber'] ?? null),
                'sodium'   => $scale($f['nf_sodium'] ?? null),
            ],
            'raw'          => ['nix_item_id' => $f['nix_item_id'] ?? null],
        ]);
    }
}
