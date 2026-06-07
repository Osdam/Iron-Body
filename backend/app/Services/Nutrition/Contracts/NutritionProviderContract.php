<?php

namespace App\Services\Nutrition\Contracts;

/**
 * Contrato de un proveedor externo de alimentos. Cada proveedor devuelve
 * alimentos en el FORMATO NORMALIZADO (ver NutritionFoodNormalizer): un array
 * con per_100g/per_serving y metadatos. NUNCA expone llaves al cliente.
 */
interface NutritionProviderContract
{
    /** Identificador de la fuente (open_food_facts|usda|nutritionix). */
    public function source(): string;

    /** ¿Habilitado por config (y con credenciales si aplica)? */
    public function isEnabled(): bool;

    /**
     * Busca alimentos por nombre. Devuelve arrays normalizados (puede ser vacío).
     * Errores/timeouts/rate-limits se manejan internamente → [] controlado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 15): array;

    /**
     * Busca un producto por código de barras. Devuelve array normalizado o null
     * si no existe / no aplica al proveedor. Errores → null controlado.
     *
     * @return array<string, mixed>|null
     */
    public function lookupByBarcode(string $barcode): ?array;
}
