<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Services\Nutrition\Providers\NutritionixProvider;
use App\Services\Nutrition\Providers\OpenFoodFactsNutritionProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resuelve un código de barras a un alimento real, de forma robusta:
 *   1) normaliza y genera variantes (UPC-A↔EAN-13, ceros, UPC-E…)
 *   2) busca exacto en BD local por cualquier variante (caché Colombia/comunidad)
 *   3) si no está, consulta proveedores (Open Food Facts → Nutritionix) por las
 *      variantes y cachea bajo la forma canónica
 *   4) si no aparece, devuelve un motivo DIFERENCIADO y controlado
 *
 * Motivos (`reason`) para que la app y los logs expliquen por qué no se resolvió:
 *   - bad_read           → código implausible/mal leído por la cámara
 *   - provider_disabled  → no hay proveedor externo habilitado
 *   - not_found_provider → el proveedor no tiene ese código
 *   - exists_by_name     → no por barcode, pero hay candidatos por nombre/marca
 */
class FoodBarcodeResolver
{
    public function __construct(
        private BarcodeNormalizer $normalizer,
        private NutritionFoodNormalizer $foodNormalizer,
        private OpenFoodFactsNutritionProvider $openFoodFacts,
        private NutritionixProvider $nutritionix,
    ) {
    }

    /** @return array{status:string,food?:array,reason?:string,barcode?:string,message?:string} */
    public function resolve(string $raw, Member $member): array
    {
        $code = $this->normalizer->clean($raw);
        Log::info('nutrition:barcode:scan', ['member_id' => $member->id, 'len' => strlen($code)]);

        if (! $this->normalizer->isPlausible($code)) {
            return [
                'status'  => 'invalid',
                'reason'  => 'bad_read',
                'barcode' => $code,
                'message' => 'No pudimos leer bien el código. Acerca la cámara e intenta de nuevo.',
            ];
        }

        $variants = $this->normalizer->variants($code);
        $canonical = $this->normalizer->canonical($code);

        // 1) BD local por cualquier variante (preferir completos y verificados).
        $local = NutritionFood::whereIn('barcode', $variants)
            ->orderByRaw('case when calories_per_100g is null then 1 else 0 end')
            ->orderByDesc('imported_priority_score')
            ->first();
        if ($local) {
            Log::info('nutrition:barcode:resolved', [
                'member_id' => $member->id, 'source' => 'local', 'type' => $this->normalizer->type($code),
            ]);
            return $this->result($local);
        }

        // 2) Proveedores externos por variantes (cachea bajo canónico).
        if (config('nutrition.external_search_enabled')) {
            $providerEnabled = $this->openFoodFacts->isEnabled() || $this->nutritionix->isEnabled();
            if (! $providerEnabled) {
                return $this->notFound($canonical, 'provider_disabled', $member, $code);
            }
            try {
                foreach ([$this->openFoodFacts, $this->nutritionix] as $provider) {
                    if (! $provider->isEnabled()) {
                        continue;
                    }
                    foreach ($this->lookupVariants($variants) as $variant) {
                        $normalized = $provider->lookupByBarcode($variant);
                        if ($normalized) {
                            // Cachea bajo el barcode canónico para futuras lecturas.
                            $normalized['barcode'] = $canonical;
                            $food = $this->foodNormalizer->cache($normalized);
                            Log::info('nutrition:barcode:resolved', [
                                'member_id' => $member->id, 'source' => $provider->source(),
                                'type' => $this->normalizer->type($code),
                            ]);
                            return $this->result($food);
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('nutrition:barcode:error', ['member_id' => $member->id]);
                return ['status' => 'error', 'message' => 'No pudimos consultar el producto. Intenta de nuevo.'];
            }
        }

        // 3) ¿Existe el producto por nombre/marca aunque no por barcode? (pista útil)
        $reason = $this->normalizer->hasValidCheckDigit($code) ? 'not_found_provider' : 'bad_read';
        return $this->notFound($canonical, $reason, $member, $code);
    }

    /** Limita las variantes a consultar en proveedores (evita martillar la API). */
    private function lookupVariants(array $variants): array
    {
        return array_slice(array_values(array_unique($variants)), 0, 4);
    }

    private function result(NutritionFood $food): array
    {
        if ($food->isMacroComplete()) {
            return ['status' => 'found', 'food' => $food->toApiArray()];
        }
        return [
            'status'          => 'incomplete',
            'reason'          => 'incomplete',
            'action_required' => 'complete_macros',
            'message'         => 'Encontramos el producto, pero faltan datos nutricionales.',
            'food'            => $food->toApiArray(),
        ];
    }

    private function notFound(string $canonical, string $reason, Member $member, string $original): array
    {
        Log::info('nutrition:barcode:not_found', [
            'member_id' => $member->id, 'reason' => $reason,
            'type' => $this->normalizer->type($original),
            'valid_check' => $this->normalizer->hasValidCheckDigit($original),
        ]);
        return [
            'status'          => 'not_found',
            'code'            => 'food_barcode_not_found',
            'reason'          => $reason,
            'barcode'         => $canonical,
            'action_required' => 'create_or_scan',
            'message'         => 'Este producto aún no está en nuestra base Colombia. '
                . 'Puedes crearlo en 30 segundos y quedará disponible para próximas búsquedas.',
            'actions'         => ['create_manual', 'scan_label', 'search_by_name', 'scan_another'],
            'options'         => ['scan_label', 'create_manual', 'search_by_name', 'scan_other'],
        ];
    }
}
