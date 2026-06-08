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

        // Ranking Colombia: usuario → propios completos → Colombia → cadenas/
        // marcas → OFF cacheado → USDA → incompletos al final.
        $local = array_slice($this->rank($local, $member), 0, $limit);

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
        // Tokens: cada palabra debe aparecer en algún campo (name/marca/cadena/
        // categoría/barcode). Permite "arroz d1", "leche alpina", "arroz éxito".
        $tokens = array_values(array_filter(preg_split('/\s+/', $norm) ?: []));
        if ($tokens === []) {
            $tokens = [$norm];
        }

        return NutritionFood::query()
            ->where(function ($q) use ($member) {
                $q->where('created_by_member_id', $member->id)
                    ->orWhere('is_public', true);
            })
            ->where(function ($outer) use ($tokens) {
                foreach ($tokens as $tok) {
                    $like = '%' . $tok . '%';
                    $outer->where(function ($q) use ($like) {
                        $q->where('normalized_name', 'like', $like)
                            ->orWhere('name', 'like', $like)
                            ->orWhere('brand', 'like', $like)
                            ->orWhere('normalized_brand', 'like', $like)
                            ->orWhere('normalized_store', 'like', $like)
                            ->orWhere('stores', 'like', $like)
                            ->orWhere('category', 'like', $like)
                            ->orWhere('barcode', 'like', $like);
                    });
                }
            })
            // Pre-orden DB: propios, completos y con prioridad Colombia primero.
            ->orderByRaw('case when created_by_member_id = ? then 0 else 1 end', [$member->id])
            ->orderByRaw('case when calories_per_100g is null then 1 else 0 end')
            ->orderByDesc('imported_priority_score')
            ->orderByDesc('verified')
            ->limit($limit * 2) // margen para el re-ranking en PHP
            ->get()
            ->all();
    }

    /**
     * Re-ordena por relevancia Colombia (estable). Buckets: 0 usuario completo,
     * 1 Iron Body completo, 2 Colombia completo (cadenas/marcas con score),
     * 3 Open Food Facts cacheado completo, 4 USDA completo, 5 otros completos,
     * 9 incompletos (siempre al final). Empate: mayor score Colombia, verificado.
     *
     * @param NutritionFood[] $foods
     * @return NutritionFood[]
     */
    private function rank(array $foods, Member $member): array
    {
        usort($foods, function (NutritionFood $a, NutritionFood $b) use ($member) {
            return $this->rankKey($a, $member) <=> $this->rankKey($b, $member);
        });
        return $foods;
    }

    private function rankKey(NutritionFood $f, Member $member): array
    {
        $complete = $f->isMacroComplete();
        $score = (int) ($f->imported_priority_score ?? 0);
        $isColombia = $f->country === 'colombia' || $score > 0;

        if (! $complete) {
            $bucket = 9; // incompletos al final, sin importar la fuente
        } elseif ($f->created_by_member_id === $member->id) {
            $bucket = 0;
        } elseif ($f->source === 'iron_body') {
            $bucket = 1;
        } elseif ($isColombia) {
            $bucket = 2;
        } elseif ($f->source === 'open_food_facts') {
            $bucket = 3;
        } elseif ($f->source === 'usda') {
            $bucket = 4;
        } else {
            $bucket = 5;
        }

        return [$bucket, -$score, $f->verified ? 0 : 1, $f->calories_per_100g === null ? 1 : 0];
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
