<?php

namespace App\Services\Nutrition;

use App\Models\NutritionFood;

/**
 * Calcula los macros FINALES de una cantidad de un alimento. El backend es la
 * ÚNICA autoridad: Flutter nunca envía macros calculados. Soporta g/ml, serving,
 * unit y medidas caseras (tbsp/tsp/cup) por equivalencia aproximada en gramos.
 */
class NutritionMacroCalculator
{
    /** Equivalencias caseras → gramos (aprox. estándar de cocina). */
    private const HOUSEHOLD_GRAMS = [
        'tbsp' => 15.0, 'cda' => 15.0, 'cucharada' => 15.0,
        'tsp' => 5.0,  'cdta' => 5.0, 'cucharadita' => 5.0,
        'cup' => 240.0, 'taza' => 240.0,
        'oz' => 28.35,
    ];

    private const KEYS = ['calories', 'protein', 'carbs', 'fat', 'sugar', 'fiber', 'sodium', 'saturated_fat'];

    /**
     * @return array{calories:float,protein:float,carbs:float,fat:float,
     *               sugar:?float,fiber:?float,sodium:?float,saturated_fat:?float,
     *               serving_multiplier:?float}
     */
    public function calculateForQuantity(NutritionFood $food, float $quantity, string $unit): array
    {
        $quantity = max(0.0, $quantity); // nunca negativos
        $unit = strtolower(trim($unit));

        $per100 = $this->per100($food);
        $servingMultiplier = null;

        // 1) Porción / unidad → usa per_serving si existe; si no, deriva de serving_size.
        if (in_array($unit, ['serving', 'porcion', 'porción', 'unit', 'unidad', 'unidades'], true)) {
            $perServing = $this->perServing($food);
            if ($perServing !== null) {
                $servingMultiplier = $quantity;
                return $this->scale($perServing, $quantity, $servingMultiplier);
            }
            // Sin per_serving pero con serving_size → gramos equivalentes.
            if ($food->serving_size && $food->serving_size > 0 && $per100 !== null) {
                $grams = $quantity * (float) $food->serving_size;
                $servingMultiplier = $quantity;
                return $this->scale($per100, $grams / 100.0, $servingMultiplier);
            }
            // Último recurso: tratar 1 unidad = 100 g.
            return $this->scale($per100 ?? [], $quantity, $quantity);
        }

        // 2) Gramos / mililitros → directo sobre per_100g.
        if (in_array($unit, ['g', 'gr', 'gramo', 'gramos', 'ml', 'mililitro', 'mililitros'], true)) {
            return $this->scale($per100 ?? [], $quantity / 100.0, $this->multFromGrams($food, $quantity));
        }

        // 3) Medidas caseras → gramos por equivalencia → per_100g.
        if (isset(self::HOUSEHOLD_GRAMS[$unit])) {
            $grams = $quantity * self::HOUSEHOLD_GRAMS[$unit];
            return $this->scale($per100 ?? [], $grams / 100.0, $this->multFromGrams($food, $grams));
        }

        // 4) Unidad desconocida → fallback a porción/serving razonable.
        $perServing = $this->perServing($food);
        if ($perServing !== null) {
            return $this->scale($perServing, $quantity, $quantity);
        }
        return $this->scale($per100 ?? [], $quantity / 100.0, $this->multFromGrams($food, $quantity));
    }

    private function per100(NutritionFood $f): ?array
    {
        $m = [
            'calories' => $f->calories_per_100g, 'protein' => $f->protein_per_100g,
            'carbs' => $f->carbs_per_100g, 'fat' => $f->fat_per_100g,
            'sugar' => $f->sugar_per_100g, 'fiber' => $f->fiber_per_100g,
            'sodium' => $f->sodium_per_100g, 'saturated_fat' => $f->saturated_fat_per_100g,
        ];
        return $this->hasAny($m) ? $m : null;
    }

    private function perServing(NutritionFood $f): ?array
    {
        $m = [
            'calories' => $f->calories_per_serving, 'protein' => $f->protein_per_serving,
            'carbs' => $f->carbs_per_serving, 'fat' => $f->fat_per_serving,
            'sugar' => $f->sugar_per_serving, 'fiber' => $f->fiber_per_serving,
            'sodium' => $f->sodium_per_serving, 'saturated_fat' => null,
        ];
        return $this->hasAny($m) ? $m : null;
    }

    private function hasAny(array $m): bool
    {
        foreach ($m as $v) {
            if ($v !== null) {
                return true;
            }
        }
        return false;
    }

    private function multFromGrams(NutritionFood $f, float $grams): ?float
    {
        return ($f->serving_size && $f->serving_size > 0)
            ? round($grams / (float) $f->serving_size, 3)
            : null;
    }

    /** Escala un set de macros por un factor y redondea a 1 decimal. */
    private function scale(array $macros, float $factor, ?float $servingMultiplier): array
    {
        $out = ['serving_multiplier' => $servingMultiplier];
        foreach (self::KEYS as $k) {
            $v = $macros[$k] ?? null;
            $out[$k] = $v === null ? ($k === 'calories' || in_array($k, ['protein', 'carbs', 'fat'], true) ? 0.0 : null)
                : round(max(0.0, (float) $v * $factor), 1);
        }
        return $out;
    }
}
