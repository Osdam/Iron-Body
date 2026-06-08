<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Services\Nutrition\Providers\NutritionixProvider;
use App\Services\Nutrition\Providers\OpenFoodFactsNutritionProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Búsqueda de alimentos por código de barras. Valida EAN/UPC (8-14 dígitos),
 * consulta primero la BD local (caché) y, si no existe, los proveedores. Cachea
 * el resultado. Devuelve estados controlados: found | not_found | error.
 */
class NutritionBarcodeService
{
    public function __construct(
        private NutritionFoodNormalizer $normalizer,
        private OpenFoodFactsNutritionProvider $openFoodFacts,
        private NutritionixProvider $nutritionix,
    ) {
    }

    /** @return array{status:string, food?:array, message?:string} */
    public function lookup(string $barcode, Member $member): array
    {
        $barcode = preg_replace('/\D/', '', $barcode) ?? '';
        if (! preg_match('/^\d{8,14}$/', $barcode)) {
            return ['status' => 'invalid', 'message' => 'Código de barras inválido.'];
        }

        Log::info('nutrition.barcode.lookup', ['member_id' => $member->id, 'len' => strlen($barcode)]);

        // 1) Caché local por barcode.
        $local = NutritionFood::where('barcode', $barcode)->latest('id')->first();
        if ($local) {
            return $this->result($local);
        }

        // 2) Proveedores externos (Open Food Facts primero; luego Nutritionix).
        if (! config('nutrition.external_search_enabled')) {
            return ['status' => 'not_found', 'message' => 'No encontramos este producto.'];
        }

        try {
            foreach ([$this->openFoodFacts, $this->nutritionix] as $provider) {
                if (! $provider->isEnabled()) {
                    continue;
                }
                $normalized = $provider->lookupByBarcode($barcode);
                if ($normalized) {
                    $normalized['barcode'] = $normalized['barcode'] ?: $barcode;
                    $food = $this->normalizer->cache($normalized);
                    return $this->result($food);
                }
            }
        } catch (Throwable $e) {
            Log::warning('nutrition.barcode.error');
            return ['status' => 'error', 'message' => 'No pudimos consultar el producto. Intenta de nuevo.'];
        }

        return ['status' => 'not_found', 'message' => 'No encontramos este producto.'];
    }

    /**
     * Producto encontrado: distingue completo vs incompleto. Si faltan macros,
     * NO se presentan ceros como válidos: status=incomplete + acción de completar.
     */
    private function result(NutritionFood $food): array
    {
        if ($food->isMacroComplete()) {
            return ['status' => 'found', 'food' => $food->toApiArray()];
        }
        return [
            'status'          => 'incomplete',
            'action_required' => 'complete_macros',
            'message'         => 'Encontramos el producto, pero faltan datos nutricionales.',
            'food'            => $food->toApiArray(),
        ];
    }
}
