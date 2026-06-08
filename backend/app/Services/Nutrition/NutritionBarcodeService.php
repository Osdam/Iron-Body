<?php

namespace App\Services\Nutrition;

use App\Models\Member;

/**
 * Búsqueda de alimentos por código de barras. Delega la resolución robusta
 * (normalización + variantes EAN/UPC/GTIN + proveedores + diagnóstico) en
 * FoodBarcodeResolver. Mantiene el contrato del controlador: found | incomplete
 * | not_found | invalid | error.
 */
class NutritionBarcodeService
{
    public function __construct(private FoodBarcodeResolver $resolver)
    {
    }

    /** @return array{status:string, food?:array, message?:string, reason?:string} */
    public function lookup(string $barcode, Member $member): array
    {
        return $this->resolver->resolve($barcode, $member);
    }
}
