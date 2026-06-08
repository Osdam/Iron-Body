<?php

namespace App\Services\Nutrition\Providers;

use App\Services\Nutrition\Contracts\NutritionProviderContract;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open Food Facts — productos con código de barras (sin API key). Maneja
 * not-found, datos incompletos, timeouts y 429/rate-limits sin romper: ante
 * cualquier fallo devuelve null/[] y la búsqueda continúa con otras fuentes.
 */
class OpenFoodFactsNutritionProvider implements NutritionProviderContract
{
    public function __construct(private NutritionFoodNormalizer $normalizer)
    {
    }

    public function source(): string
    {
        return 'open_food_facts';
    }

    public function isEnabled(): bool
    {
        return (bool) config('nutrition.openfoodfacts.enabled');
    }

    public function lookupByBarcode(string $barcode): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }
        $base = rtrim((string) config('nutrition.openfoodfacts.base_url'), '/');
        $timeout = (int) config('nutrition.barcode_timeout_seconds', 8);
        try {
            $resp = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'IronBodyApp/1.0 (nutrition)'])
                ->get("{$base}/api/v2/product/{$barcode}.json", [
                    'fields' => 'code,product_name,product_name_es,generic_name,generic_name_es,'
                        . 'brands,categories,image_front_url,image_url,selected_images,'
                        . 'serving_size,serving_quantity,product_quantity,nutriments',
                ]);
            if ($resp->status() === 429) {
                Log::warning('nutrition.barcode.rate_limited', ['provider' => $this->source()]);
                return null;
            }
            if (! $resp->successful()) {
                return null;
            }
            $json = $resp->json();
            if (! is_array($json) || (int) ($json['status'] ?? 0) !== 1 || ! is_array($json['product'] ?? null)) {
                Log::info('nutrition.barcode.raw_summary', [
                    'barcode' => $barcode, 'provider' => $this->source(), 'found' => false,
                ]);
                return null; // producto no encontrado
            }
            $product = $json['product'];
            $product['code'] = $product['code'] ?? $barcode;
            $normalized = $this->normalizer->fromOpenFoodFacts($product);

            // Diagnóstico seguro (sin payload gigante ni datos sensibles).
            $nutr = is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [];
            Log::info('nutrition.barcode.raw_summary', [
                'barcode'            => $barcode,
                'provider'           => $this->source(),
                'found'              => true,
                'has_nutriments'     => $nutr !== [],
                'nutriment_keys'     => array_slice(array_keys($nutr), 0, 12),
                'energy_kcal_100g'   => $nutr['energy-kcal_100g'] ?? null,
                'energy_100g'        => $nutr['energy_100g'] ?? null,
                'proteins_100g'      => $nutr['proteins_100g'] ?? null,
                'carbohydrates_100g' => $nutr['carbohydrates_100g'] ?? null,
                'fat_100g'           => $nutr['fat_100g'] ?? null,
                'serving_size'       => $product['serving_size'] ?? null,
                'product_name'       => mb_substr((string) ($product['product_name'] ?? ''), 0, 60),
                'brand'              => mb_substr((string) ($product['brands'] ?? ''), 0, 40),
                'is_complete'        => $normalized !== null
                    && ($normalized['per_100g']['calories'] ?? null) !== null
                    && ($normalized['per_100g']['protein'] ?? null) !== null
                    && ($normalized['per_100g']['carbs'] ?? null) !== null
                    && ($normalized['per_100g']['fat'] ?? null) !== null,
            ]);

            return $normalized;
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
        $base = rtrim((string) config('nutrition.openfoodfacts.base_url'), '/');
        $timeout = (int) config('nutrition.search_timeout_seconds', 8);
        try {
            $resp = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'IronBodyApp/1.0 (nutrition)'])
                ->get("{$base}/cgi/search.pl", [
                    'search_terms'   => $query,
                    'search_simple'  => 1,
                    'action'         => 'process',
                    'json'           => 1,
                    'page_size'      => $limit,
                    'lc'             => 'es',
                    'countries_tags' => 'colombia',
                    'fields'         => 'code,product_name,product_name_es,generic_name,generic_name_es,'
                        . 'brands,categories,image_front_url,image_url,selected_images,'
                        . 'serving_size,serving_quantity,nutriments',
                ]);
            if (! $resp->successful()) {
                return [];
            }
            $products = $resp->json('products');
            if (! is_array($products)) {
                return [];
            }
            $out = [];
            foreach ($products as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $n = $this->normalizer->fromOpenFoodFacts($p);
                // Solo resultados con al menos calorías (evita ruido sin macros).
                if ($n && ($n['per_100g']['calories'] ?? null) !== null) {
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
