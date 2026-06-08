<?php

namespace App\Services\Nutrition;

/**
 * Capa de priorización Colombia. Detecta productos vendidos en Colombia a partir
 * de señales del catálogo (país, cadenas/stores, marca local, prefijo de código
 * de barras) y calcula un score de prioridad para el ranking de búsqueda y el
 * importador. NO excluye importados: solo prioriza.
 */
class NutritionColombiaClassifier
{
    public function isEnabled(): bool
    {
        return (bool) config('nutrition.openfoodfacts.colombia_enabled', true);
    }

    /** @return string[] cadenas colombianas configuradas (D1, Éxito, …). */
    public function retailers(): array
    {
        return (array) config('nutrition.openfoodfacts.colombia_retailers', []);
    }

    /** @return string[] marcas colombianas/populares configuradas. */
    public function brandSeeds(): array
    {
        return (array) config('nutrition.openfoodfacts.colombia_brand_seeds', []);
    }

    /** @return string[] prefijos GS1 (p.ej. "770"). */
    public function barcodePrefixes(): array
    {
        return (array) config('nutrition.openfoodfacts.colombia_barcode_prefixes', ['770']);
    }

    /** Normaliza texto (minúsculas, sin acentos) para comparar de forma robusta. */
    public function normalize(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));
        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }

    /** ¿El país (countries_tags o countries) corresponde a Colombia? */
    public function isColombiaCountry(?string $countries): bool
    {
        $c = $this->normalize($countries);
        return $c !== '' && (str_contains($c, 'colombia') || str_contains($c, 'colombie'));
    }

    /** @return string[] cadenas colombianas encontradas en el campo stores. */
    public function matchedRetailers(?string $stores): array
    {
        $s = $this->normalize($stores);
        if ($s === '') {
            return [];
        }
        $hits = [];
        foreach ($this->retailers() as $retailer) {
            if (str_contains($s, $this->normalize($retailer))) {
                $hits[$this->canonicalRetailer($retailer)] = true;
            }
        }
        return array_keys($hits);
    }

    /** Devuelve la marca semilla colombiana que coincide con $brand (o null). */
    public function matchedBrandSeed(?string $brand): ?string
    {
        $b = $this->normalize($brand);
        if ($b === '') {
            return null;
        }
        foreach ($this->brandSeeds() as $seed) {
            $ns = $this->normalize($seed);
            if ($ns !== '' && str_contains($b, $ns)) {
                return $seed;
            }
        }
        return null;
    }

    /** ¿El código de barras empieza por un prefijo colombiano configurado? */
    public function barcodePrefixMatch(?string $barcode): bool
    {
        $code = preg_replace('/\D/', '', (string) $barcode) ?? '';
        if ($code === '') {
            return false;
        }
        foreach ($this->barcodePrefixes() as $prefix) {
            $p = trim((string) $prefix);
            if ($p !== '' && str_starts_with($code, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clasifica un alimento (array con keys countries, stores, brand, barcode).
     * Devuelve las columnas Colombia + flags útiles para ranking.
     *
     * @return array{country:?string, stores:?string, normalized_brand:?string,
     *   normalized_store:?string, imported_region:?string, imported_priority_score:int,
     *   is_colombia:bool, retailers:string[]}
     */
    public function classify(array $food): array
    {
        $countries = $food['countries'] ?? null;
        $stores    = $food['stores'] ?? null;
        $brand     = $food['brand'] ?? null;
        $barcode   = $food['barcode'] ?? null;

        $isCountry  = $this->isColombiaCountry($countries);
        $retailers  = $this->matchedRetailers($stores);
        $brandSeed  = $this->matchedBrandSeed($brand);
        $prefix     = $this->barcodePrefixMatch($barcode);

        $score = 0;
        $score += $isCountry ? 50 : 0;
        $score += $retailers !== [] ? 30 : 0;
        $score += $brandSeed !== null ? 20 : 0;
        $score += $prefix ? 10 : 0;

        $isColombia = $isCountry || $retailers !== [] || $brandSeed !== null || $prefix;

        return [
            'country'                  => $isCountry ? 'colombia' : ($countries ? $this->normalize($countries) : null),
            'stores'                   => $stores ? mb_substr((string) $stores, 0, 255) : null,
            'normalized_brand'         => $brand ? $this->normalize($brand) : null,
            'normalized_store'         => $retailers[0] ?? ($stores ? $this->normalize($stores) : null),
            'imported_region'          => $isColombia ? 'colombia' : null,
            'imported_priority_score'  => $score,
            'is_colombia'              => $isColombia,
            'retailers'                => $retailers,
        ];
    }

    /** Nombre canónico de cadena para badges (Éxito, D1, Olímpica, Ara, …). */
    private function canonicalRetailer(string $retailer): string
    {
        $n = $this->normalize($retailer);
        return match (true) {
            str_contains($n, 'd1')       => 'D1',
            str_contains($n, 'exito')    => 'Éxito',
            str_contains($n, 'olimpica') => 'Olímpica',
            str_contains($n, 'ara')      => 'Ara',
            str_contains($n, 'carulla')  => 'Carulla',
            str_contains($n, 'jumbo')    => 'Jumbo',
            str_contains($n, 'alkosto')  => 'Alkosto',
            default                       => $retailer,
        };
    }
}
