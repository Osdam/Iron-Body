<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionFavorite;
use App\Models\NutritionFood;
use App\Services\Nutrition\Providers\NutritionixProvider;
use App\Services\Nutrition\Providers\OpenFoodFactsNutritionProvider;
use App\Services\Nutrition\Providers\UsdaFoodDataProvider;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda de alimentos: BD local primero (alimentos del usuario, públicos
 * Iron Body, caché externo; favoritos/recientes con prioridad). Si hay pocos
 * resultados y la búsqueda externa está habilitada, consulta proveedores,
 * normaliza, cachea y combina. Devuelve SIEMPRE el formato unificado.
 */
class NutritionFoodSearchService
{
    private const LOCAL_ENOUGH = 6;

    public function __construct(
        private NutritionFoodNormalizer $normalizer,
        private OpenFoodFactsNutritionProvider $openFoodFacts,
        private UsdaFoodDataProvider $usda,
        private NutritionixProvider $nutritionix,
    ) {
    }

    /** @return array<int, array<string,mixed>> formato unificado + is_favorite */
    public function search(string $query, Member $member, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        Log::info('nutrition.search', ['member_id' => $member->id, 'len' => mb_strlen($query)]);

        $local = $this->searchLocal($query, $member, $limit);

        // Pocos resultados locales → completar con proveedores externos.
        if (count($local) < self::LOCAL_ENOUGH && config('nutrition.external_search_enabled')) {
            $external = $this->searchExternal($query, $limit);
            $local = $this->merge($local, $external, $limit);
        }

        $favoriteIds = $this->favoriteIds($member);
        return array_map(function (NutritionFood $f) use ($favoriteIds) {
            $row = $f->toApiArray();
            $row['is_favorite'] = in_array($f->id, $favoriteIds, true);
            return $row;
        }, $local);
    }

    /** @return NutritionFood[] */
    private function searchLocal(string $query, Member $member, int $limit): array
    {
        $norm = NutritionFood::normalize($query);
        $like = '%' . str_replace(' ', '%', $norm) . '%';

        return NutritionFood::query()
            ->where(function ($q) use ($member) {
                $q->where('created_by_member_id', $member->id)
                    ->orWhere('is_public', true);
            })
            ->where(function ($q) use ($like, $query) {
                $q->where('normalized_name', 'like', $like)
                    ->orWhere('name', 'like', '%' . $query . '%')
                    ->orWhere('brand', 'like', '%' . $query . '%');
            })
            // Prioridad: alimentos del usuario, verificados, con calorías.
            ->orderByRaw('case when created_by_member_id = ? then 0 else 1 end', [$member->id])
            ->orderByDesc('verified')
            ->orderByRaw('case when calories_per_100g is null then 1 else 0 end')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** @return NutritionFood[] alimentos externos normalizados y cacheados */
    private function searchExternal(string $query, int $limit): array
    {
        $normalized = [];
        foreach ([$this->openFoodFacts, $this->usda, $this->nutritionix] as $provider) {
            if (! $provider->isEnabled()) {
                continue;
            }
            foreach ($provider->searchByName($query, $limit) as $n) {
                $normalized[] = $n;
            }
            if (count($normalized) >= $limit) {
                break; // suficiente; no martillar proveedores
            }
        }

        $foods = [];
        foreach ($normalized as $n) {
            try {
                $foods[] = $this->normalizer->cache($n);
            } catch (\Throwable $e) {
                Log::warning('nutrition.search.cache_failed');
            }
        }
        return $foods;
    }

    /** Combina local + externo evitando duplicados (por id/barcode/external_id). */
    private function merge(array $local, array $external, int $limit): array
    {
        $seen = [];
        $out = [];
        foreach ([...$local, ...$external] as $food) {
            $key = $food->id ?? ($food->barcode ?: $food->source . ':' . $food->external_id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $food;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function favoriteIds(Member $member): array
    {
        return NutritionFavorite::where('member_id', $member->id)
            ->pluck('food_id')->all();
    }
}
