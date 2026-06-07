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
                    'fields' => 'code,product_name,product_name_es,generic_name,brands,categories,image_front_url,image_url,serving_size,nutriments',
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
                return null; // producto no encontrado
            }
            $product = $json['product'];
            $product['code'] = $product['code'] ?? $barcode;
            return $this->normalizer->fromOpenFoodFacts($product);
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
                    'fields'         => 'code,product_name,product_name_es,generic_name,brands,categories,image_front_url,image_url,serving_size,nutriments',
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
