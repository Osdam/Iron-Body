<?php

namespace App\Services\Nutrition\Ai;

/**
 * Valida y NORMALIZA la respuesta JSON de la IA contra el schema nutricional.
 * Anti-corrupción: faltantes → null (NUNCA 0); negativos o valores físicamente
 * imposibles → validation_failed; incoherencia calórica → warning (no falla).
 */
class NutritionAiResponseValidator
{
    private const MACROS = ['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium', 'saturated_fat', 'trans_fat', 'added_sugar'];

    /** @return array{ok:bool, errors:array, warnings:array, data:?array} */
    public function validateExtraction(?array $raw, string $source): array
    {
        if (! is_array($raw)) {
            return ['ok' => false, 'errors' => ['invalid_json'], 'warnings' => [], 'data' => null];
        }

        $errors = [];
        $warnings = is_array($raw['warnings'] ?? null) ? array_values(array_filter($raw['warnings'], 'is_string')) : [];

        $per100 = $this->macroSet($raw, '_per_100g', $errors);
        $perServing = $this->macroSet($raw, '_per_serving', $errors);

        // También aceptamos objetos anidados per_100g/per_serving.
        if (is_array($raw['per_100g'] ?? null)) {
            $per100 = $this->mergeNested($per100, $raw['per_100g'], $errors);
        }
        if (is_array($raw['per_serving'] ?? null)) {
            $perServing = $this->mergeNested($perServing, $raw['per_serving'], $errors);
        }

        $servingSize = $this->num($raw['serving_size_g'] ?? null, $errors, 'serving_size_g', 5000);

        // Rellena la base faltante SOLO si el tamaño de porción es confiable.
        if ($servingSize && $servingSize > 0) {
            $f = $servingSize / 100.0;
            foreach (self::MACROS as $k) {
                if (($perServing[$k] ?? null) === null && ($per100[$k] ?? null) !== null) {
                    $perServing[$k] = round($per100[$k] * $f, 2);
                }
                if (($per100[$k] ?? null) === null && ($perServing[$k] ?? null) !== null && $f > 0) {
                    $per100[$k] = round($perServing[$k] / $f, 2);
                }
            }
        }

        // Límites físicos por 100 g (valores imposibles → falla).
        $this->assertPhysical($per100, $errors);

        // Coherencia calórica (per 100g) → warning, no falla.
        if ($this->allPresent($per100, ['calories', 'protein', 'carbs', 'fat'])) {
            $calc = 4 * $per100['protein'] + 4 * $per100['carbs'] + 9 * $per100['fat'];
            $cal = max($per100['calories'], 1);
            if (abs($calc - $cal) / $cal > 0.35) {
                $warnings[] = 'Las calorías no cuadran con los macros; revisa los valores.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'data' => null];
        }

        $core = array_filter(
            [$per100['calories'] ?? null, $per100['protein'] ?? null, $per100['carbs'] ?? null, $per100['fat'] ?? null],
            fn ($v) => $v !== null
        );
        $confidence = $this->clampConfidence($raw['confidence_score'] ?? null, count($core) / 4);
        $missing = [];
        foreach (['calories', 'protein', 'carbs', 'fat'] as $k) {
            if (($per100[$k] ?? null) === null && ($perServing[$k] ?? null) === null) {
                $missing[] = $k;
            }
        }

        return [
            'ok' => true, 'errors' => [], 'warnings' => $warnings,
            'data' => [
                'source'                 => $source,
                'product_name'           => $this->str($raw['product_name_detected'] ?? $raw['product_name'] ?? null),
                'brand'                  => $this->str($raw['brand_detected'] ?? $raw['brand'] ?? null),
                'serving_size_g'         => $servingSize,
                'serving_unit'           => in_array(($raw['serving_unit'] ?? 'g'), ['g', 'ml'], true) ? ($raw['serving_unit'] ?? 'g') : 'g',
                'servings_per_container' => $this->intOrNull($raw['servings_per_container'] ?? null),
                'basis_detected'         => in_array(($raw['basis_detected'] ?? 'unknown'), ['per_100g', 'per_serving', 'both', 'unknown'], true) ? ($raw['basis_detected'] ?? 'unknown') : 'unknown',
                'per_100g'               => $per100,
                'per_serving'            => $perServing,
                'confidence_score'       => $confidence,
                'missing_fields'         => $missing,
                'warnings'               => $warnings,
                'is_complete'            => $missing === [],
            ],
        ];
    }

    private function macroSet(array $raw, string $suffix, array &$errors): array
    {
        $out = [];
        foreach (self::MACROS as $k) {
            $out[$k] = $this->num($raw[$k . $suffix] ?? null, $errors, $k . $suffix, $this->maxFor($k));
        }
        return $out;
    }

    private function mergeNested(array $base, array $nested, array &$errors): array
    {
        foreach (self::MACROS as $k) {
            if (array_key_exists($k, $nested) && $base[$k] === null) {
                $base[$k] = $this->num($nested[$k], $errors, $k, $this->maxFor($k));
            }
        }
        return $base;
    }

    private function assertPhysical(array $per100, array &$errors): void
    {
        $limits = ['calories' => 900, 'protein' => 100, 'carbs' => 100, 'fat' => 100,
            'sugar' => 100, 'fiber' => 100, 'saturated_fat' => 100, 'trans_fat' => 100,
            'added_sugar' => 100, 'sodium' => 100000];
        foreach ($limits as $k => $max) {
            $v = $per100[$k] ?? null;
            if ($v !== null && $v > $max) {
                $errors[] = "impossible_{$k}";
            }
        }
    }

    private function maxFor(string $k): float
    {
        return $k === 'sodium' ? 200000 : ($k === 'calories' ? 2000 : 1000);
    }

    /** Convierte a float; null si ausente; falla si negativo o > hardMax. */
    private function num($v, array &$errors, string $field, float $hardMax): ?float
    {
        if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', $v));
        }
        if (! is_numeric($v)) {
            return null; // dato no numérico → faltante, NO cero
        }
        $f = (float) $v;
        if ($f < 0) {
            $errors[] = "negative_{$field}";
            return null;
        }
        if ($f > $hardMax) {
            $errors[] = "out_of_range_{$field}";
            return null;
        }
        return round($f, 2);
    }

    private function allPresent(array $set, array $keys): bool
    {
        foreach ($keys as $k) {
            if (($set[$k] ?? null) === null) {
                return false;
            }
        }
        return true;
    }

    private function clampConfidence($raw, float $fallback): float
    {
        $c = is_numeric($raw) ? (float) $raw : $fallback;
        return round(max(0.0, min(1.0, $c)), 3);
    }

    private function str($v): ?string
    {
        $s = is_string($v) ? trim($v) : null;
        return ($s === null || $s === '') ? null : mb_substr($s, 0, 160);
    }

    private function intOrNull($v): ?int
    {
        return is_numeric($v) && $v > 0 ? (int) round($v) : null;
    }
}
