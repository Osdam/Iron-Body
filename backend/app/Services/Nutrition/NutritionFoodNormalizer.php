<?php

namespace App\Services\Nutrition;

use App\Models\NutritionFood;
use Carbon\Carbon;

/**
 * Normaliza respuestas de proveedores externos a un formato único y las cachea
 * en `nutrition_foods`. Limpia nombres, convierte unidades y calcula per_serving
 * a partir de per_100g + serving_size. Marca un confidence_score por completitud.
 */
class NutritionFoodNormalizer
{
    /** Open Food Facts product → array normalizado (o null si inservible). */
    public function fromOpenFoodFacts(array $p): ?array
    {
        // Nombre: prioriza español (Colombia) y cae a genérico.
        $name = trim((string) (
            $p['product_name_es']
            ?? $p['generic_name_es']
            ?? $p['product_name']
            ?? $p['generic_name']
            ?? ''
        ));
        if ($name === '') {
            return null;
        }
        $nutr = is_array($p['nutriments'] ?? null) ? $p['nutriments'] : [];
        $per100 = [
            // Calorías: kcal dedicadas primero; si solo hay energía en kJ, convertir.
            'calories'      => $this->energyKcal($nutr),
            'protein'       => $this->num($nutr['proteins_100g'] ?? $nutr['proteins_value'] ?? null),
            'carbs'         => $this->num($nutr['carbohydrates_100g'] ?? $nutr['carbohydrates_value'] ?? null),
            'fat'           => $this->num($nutr['fat_100g'] ?? $nutr['fat_value'] ?? null),
            'sugar'         => $this->num($nutr['sugars_100g'] ?? null),
            'fiber'         => $this->num($nutr['fiber_100g'] ?? null),
            'sodium'        => $this->sodium($nutr['sodium_100g'] ?? null, $nutr['salt_100g'] ?? null),
            'saturated_fat' => $this->num($nutr['saturated-fat_100g'] ?? null),
        ];
        [$servingSize, $servingUnit] = $this->parseServing(
            (string) ($p['serving_size'] ?? ''),
            $p['serving_quantity'] ?? null,
        );

        return $this->assemble([
            'source'       => 'open_food_facts',
            'external_id'  => (string) ($p['code'] ?? $p['_id'] ?? ($p['barcode'] ?? '')),
            'barcode'      => (string) ($p['code'] ?? $p['barcode'] ?? '') ?: null,
            'name'         => $this->cleanName($name),
            'brand'        => $this->firstCsv((string) ($p['brands'] ?? '')),
            'category'     => $this->firstCsv((string) ($p['categories'] ?? '')),
            'image_url'    => $this->offImage($p),
            'serving_size' => $servingSize,
            'serving_unit' => $servingUnit,
            'per_100g'     => $per100,
            'raw'          => ['code' => $p['code'] ?? null, 'serving_size' => $p['serving_size'] ?? null],
        ]);
    }

    /**
     * Extrae kcal/100g de OFF. Prioriza los campos en kcal; si solo hay energía
     * en kJ (energy_100g/energy-kj_100g) la convierte (kcal = kJ / 4.184).
     */
    private function energyKcal(array $nutr): ?float
    {
        foreach (['energy-kcal_100g', 'energy-kcal_value', 'energy-kcal'] as $k) {
            $v = $this->num($nutr[$k] ?? null);
            if ($v !== null) {
                return $v;
            }
        }
        foreach (['energy-kj_100g', 'energy_100g', 'energy-kj', 'energy'] as $k) {
            $v = $this->num($nutr[$k] ?? null);
            if ($v !== null && $v > 0) {
                return round($v / 4.184, 2); // kJ → kcal
            }
        }
        return null;
    }

    /** Mejor URL de imagen disponible en OFF (incluye selected_images anidado). */
    private function offImage(array $p): ?string
    {
        foreach (['image_front_url', 'image_url'] as $k) {
            if (! empty($p[$k])) {
                return (string) $p[$k];
            }
        }
        $sel = $p['selected_images']['front']['display'] ?? null;
        if (is_array($sel)) {
            return $sel['es'] ?? $sel['en'] ?? (reset($sel) ?: null);
        }
        return null;
    }

    /** USDA FoodData Central item → array normalizado. */
    public function fromUsda(array $item): ?array
    {
        $name = trim((string) ($item['description'] ?? ''));
        if ($name === '') {
            return null;
        }
        $by = [];
        foreach (($item['foodNutrients'] ?? []) as $n) {
            $id = $n['nutrientId'] ?? ($n['nutrient']['id'] ?? null);
            $val = $n['value'] ?? ($n['amount'] ?? null);
            if ($id !== null) {
                $by[(int) $id] = $this->num($val);
            }
        }
        // IDs USDA: 1008 kcal, 1003 protein, 1005 carbs, 1004 fat, 2000 sugars,
        // 1079 fiber, 1093 sodium(mg), 1258 saturated fat.
        $per100 = [
            'calories'      => $by[1008] ?? null,
            'protein'       => $by[1003] ?? null,
            'carbs'         => $by[1005] ?? null,
            'fat'           => $by[1004] ?? null,
            'sugar'         => $by[2000] ?? null,
            'fiber'         => $by[1079] ?? null,
            'sodium'        => $by[1093] ?? null, // ya en mg/100g
            'saturated_fat' => $by[1258] ?? null,
        ];

        return $this->assemble([
            'source'      => 'usda',
            'external_id' => (string) ($item['fdcId'] ?? ''),
            'barcode'     => $item['gtinUpc'] ?? null,
            'name'        => $this->cleanName($name),
            'brand'       => $item['brandOwner'] ?? $item['brandName'] ?? null,
            'category'    => $item['foodCategory'] ?? null,
            'per_100g'    => $per100,
            'raw'         => ['fdcId' => $item['fdcId'] ?? null],
        ]);
    }

    /**
     * Completa per_serving y confidence_score. Espera:
     *   source, external_id, barcode, name, brand?, category?, image_url?,
     *   serving_size?, serving_unit?, per_100g[], raw[]
     */
    public function assemble(array $d): array
    {
        $per100 = $d['per_100g'] ?? [];
        $size = $d['serving_size'] ?? null;
        $perServing = [];
        if ($size && $size > 0) {
            $factor = $size / 100.0;
            foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium'] as $k) {
                $perServing[$k] = isset($per100[$k]) && $per100[$k] !== null
                    ? round($per100[$k] * $factor, 1)
                    : null;
            }
        }
        // Confidence por completitud de los 4 macros base.
        $core = array_filter(
            [$per100['calories'] ?? null, $per100['protein'] ?? null, $per100['carbs'] ?? null, $per100['fat'] ?? null],
            fn ($v) => $v !== null
        );
        $confidence = round(count($core) / 4, 3);

        return [
            'source'           => $d['source'],
            'external_id'      => $d['external_id'] ?: null,
            'barcode'          => $d['barcode'] ?? null,
            'name'             => $d['name'],
            'brand'            => $d['brand'] ?? null,
            'category'         => $d['category'] ?? null,
            'image_url'        => $d['image_url'] ?? null,
            'serving_size'     => $size,
            'serving_unit'     => $d['serving_unit'] ?? ($size ? 'g' : null),
            'per_100g'         => $per100,
            'per_serving'      => $perServing,
            'confidence_score' => $confidence,
            'raw'              => $d['raw'] ?? [],
            'incomplete'       => count($core) < 4,
        ];
    }

    /**
     * Cachea (crea o actualiza) un alimento normalizado en BD. Idempotente por
     * (source, external_id) o por barcode. Devuelve el modelo persistido.
     */
    public function cache(array $n): NutritionFood
    {
        $query = NutritionFood::query();
        if (! empty($n['external_id'])) {
            $query->where('source', $n['source'])->where('external_id', $n['external_id']);
        } elseif (! empty($n['barcode'])) {
            $query->where('barcode', $n['barcode']);
        } else {
            $query->whereRaw('1 = 0'); // sin clave estable → siempre crea
        }
        $food = $query->first() ?? new NutritionFood();

        $food->fill($this->toModelAttributes($n));
        $food->last_synced_at = Carbon::now();
        $food->save();

        return $food;
    }

    /** Mapa normalizado → columnas planas del modelo. */
    public function toModelAttributes(array $n): array
    {
        $attrs = [
            'source'           => $n['source'],
            'external_id'      => $n['external_id'] ?? null,
            'barcode'          => $n['barcode'] ?? null,
            'name'             => $n['name'],
            'brand'            => $n['brand'] ?? null,
            'category'         => $n['category'] ?? null,
            'image_url'        => $n['image_url'] ?? null,
            'serving_size'     => $n['serving_size'] ?? null,
            'serving_unit'     => $n['serving_unit'] ?? null,
            'confidence_score' => $n['confidence_score'] ?? null,
            'is_public'        => in_array($n['source'], ['open_food_facts', 'usda', 'nutritionix', 'iron_body'], true),
            'raw_payload'      => $n['raw'] ?? null,
        ];
        foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium', 'saturated_fat'] as $k) {
            $attrs[$k . '_per_100g'] = $n['per_100g'][$k] ?? null;
        }
        foreach (['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium'] as $k) {
            $attrs[$k . '_per_serving'] = $n['per_serving'][$k] ?? null;
        }
        return $attrs;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function num($v): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return $f < 0 ? null : round($f, 2);
    }

    /** sodium_100g viene en gramos en OFF; convertir a mg. Fallback desde sal. */
    private function sodium($sodiumG, $saltG): ?float
    {
        if ($sodiumG !== null && is_numeric($sodiumG)) {
            return round((float) $sodiumG * 1000, 2); // g → mg
        }
        if ($saltG !== null && is_numeric($saltG)) {
            return round((float) $saltG * 1000 / 2.5, 2); // sal → sodio (≈/2.5) en mg
        }
        return null;
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function firstCsv(string $csv): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $csv)));
        return $parts[0] ?? null;
    }

    /** "30 g" / "30g" / "1 unidad" → [30.0, 'g']. */
    private function parseServing(string $s, $servingQuantity = null): array
    {
        if ($s !== '' && preg_match('/([\d.,]+)\s*([a-zA-Z]+)?/', $s, $m)) {
            $size = (float) str_replace(',', '.', $m[1]);
            $unit = strtolower($m[2] ?? 'g');
            if ($size > 0) {
                return [round($size, 2), $unit];
            }
        }
        // Fallback: serving_quantity (número, en gramos por convención de OFF).
        if ($servingQuantity !== null && is_numeric($servingQuantity) && (float) $servingQuantity > 0) {
            return [round((float) $servingQuantity, 2), 'g'];
        }
        return [null, null];
    }
}
