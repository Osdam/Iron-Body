<?php

namespace App\Console\Commands;

use App\Models\NutritionFood;
use App\Services\Nutrition\NutritionColombiaClassifier;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Importador masivo OPCIONAL de Open Food Facts desde un dump LOCAL (CSV o
 * JSONL) hacia la base propia, con PRIORIZACIÓN COLOMBIA. Evita abusar de la API
 * en vivo: el admin descarga el dump y lo carga por lotes. Deshabilitado por
 * defecto (requiere --file o NUTRITION_OFF_IMPORT_ENABLED=true).
 *
 *   php artisan nutrition:off-import --file=/path/products.csv --country=colombia --limit=50000
 *   php artisan nutrition:off-import --file=/path/products.jsonl --country=colombia --stores="D1,Éxito,Olímpica,Ara" --limit=50000
 *   php artisan nutrition:off-import --file=/path/products.csv --brand-seeds --country=colombia --limit=50000
 *   php artisan nutrition:off-import --stats --country=colombia
 *
 * Reglas: salta productos sin barcode; normaliza con el mismo normalizer;
 * incompletos se guardan como incompletos (NUNCA macros 0 falsos); upsert por
 * barcode/external_id; procesa por lotes; reanudable; sin cargar todo en memoria;
 * NO hace llamadas API masivas (trabaja con el dump local).
 */
class NutritionOffImport extends Command
{
    protected $signature = 'nutrition:off-import
        {--file= : Ruta del dump local (.jsonl o .csv)}
        {--country= : Filtra por país (countries_tags contiene este valor)}
        {--stores= : Solo productos cuyo campo stores contenga alguna de estas cadenas (CSV)}
        {--brand-seeds : Solo productos cuya marca coincida con las marcas Colombia configuradas}
        {--limit= : Máximo de productos a procesar}
        {--resume : Reanuda desde el último cursor guardado}
        {--stats : Solo muestra estadísticas, no importa}';

    protected $description = 'Importa productos de Open Food Facts desde un dump local, priorizando Colombia (opcional).';

    private const CURSOR_KEY = 'nutrition_off_import_cursor';

    public function handle(NutritionFoodNormalizer $normalizer, NutritionColombiaClassifier $colombia): int
    {
        if ($this->option('stats')) {
            return $this->stats($colombia);
        }

        $file = $this->option('file') ?: config('nutrition.openfoodfacts.import.path');
        $enabled = (bool) config('nutrition.openfoodfacts.import.enabled') || $this->option('file');
        if (! $enabled) {
            $this->warn('Importador deshabilitado. Usa --file=... o NUTRITION_OFF_IMPORT_ENABLED=true.');
            return self::SUCCESS;
        }
        if (! $file || ! is_readable($file)) {
            $this->error("Archivo no legible: {$file}");
            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : config('nutrition.openfoodfacts.import.limit');
        $country = $this->option('country') ?: config('nutrition.openfoodfacts.country');
        $storesFilter = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('stores')))));
        $brandSeedsOnly = (bool) $this->option('brand-seeds');
        $resumeFrom = $this->option('resume') ? (int) Cache::get(self::CURSOR_KEY, 0) : 0;
        $isCsv = str_ends_with(strtolower($file), '.csv');

        $this->info('Importando ' . ($isCsv ? 'CSV' : 'JSONL') . " desde {$file}"
            . ($country ? " · país={$country}" : '')
            . ($storesFilter ? ' · stores=' . implode('/', $storesFilter) : '')
            . ($brandSeedsOnly ? ' · solo marcas Colombia' : '')
            . ($resumeFrom ? " (reanudando en línea {$resumeFrom})" : ''));

        $stats = [
            'processed' => 0, 'created' => 0, 'updated' => 0, 'incomplete' => 0, 'complete' => 0,
            'skipped' => 0, 'errors' => 0,
            'colombia' => 0, 'colombian_brands' => 0,
            'D1' => 0, 'Éxito' => 0, 'Olímpica' => 0, 'Ara' => 0,
        ];
        $line = 0;
        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error('No se pudo abrir el archivo.');
            return self::FAILURE;
        }
        $header = $isCsv ? $this->csvHeader($handle, $line) : null;

        while (($row = $isCsv ? fgetcsv($handle) : fgets($handle)) !== false) {
            $line++;
            if ($line <= $resumeFrom) {
                continue;
            }
            if ($limit !== null && $stats['processed'] >= $limit) {
                break;
            }

            $product = $isCsv ? $this->csvRowToProduct($header, $row) : $this->jsonLineToProduct($row);
            if ($product === null) {
                $stats['skipped']++;
                continue;
            }

            $countriesRaw = (string) ($product['countries_tags'] ?? $product['countries'] ?? '');
            $storesRaw    = (string) ($product['stores_tags'] ?? $product['stores'] ?? '');
            $brandsRaw    = (string) ($product['brands'] ?? '');

            // Filtro de país (countries_tags contiene el valor). Los importados
            // vendidos en Colombia traen colombia en countries_tags → NO se excluyen.
            if ($country && ! str_contains($colombia->normalize($countriesRaw), $colombia->normalize($country))) {
                $stats['skipped']++;
                continue;
            }
            // Filtro opcional por cadenas (--stores).
            if ($storesFilter !== [] && ! $this->storesMatch($colombia, $storesRaw, $storesFilter)) {
                $stats['skipped']++;
                continue;
            }
            // Filtro opcional por marcas Colombia (--brand-seeds).
            if ($brandSeedsOnly && $colombia->matchedBrandSeed($brandsRaw) === null) {
                $stats['skipped']++;
                continue;
            }
            if (empty($product['code'])) {
                $stats['skipped']++;
                continue; // sin barcode → se salta
            }

            try {
                $normalized = $normalizer->fromOpenFoodFacts($product);
                if ($normalized === null) {
                    $stats['skipped']++;
                    continue;
                }
                $exists = NutritionFood::where('barcode', $normalized['barcode'])->exists();
                $food = $normalizer->cache($normalized);
                $stats['processed']++;
                $exists ? $stats['updated']++ : $stats['created']++;
                $food->isMacroComplete() ? $stats['complete']++ : $stats['incomplete']++;

                // Conteos de cobertura Colombia (señales del producto).
                $c = $colombia->classify([
                    'countries' => $countriesRaw, 'stores' => $storesRaw,
                    'brand' => $brandsRaw, 'barcode' => $product['code'],
                ]);
                if ($c['is_colombia']) {
                    $stats['colombia']++;
                }
                if ($colombia->matchedBrandSeed($brandsRaw) !== null) {
                    $stats['colombian_brands']++;
                }
                foreach (['D1', 'Éxito', 'Olímpica', 'Ara'] as $chain) {
                    if (in_array($chain, $c['retailers'], true)) {
                        $stats[$chain]++;
                    }
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
            }

            if ($stats['processed'] % (int) config('nutrition.openfoodfacts.import.batch_size', 500) === 0) {
                Cache::put(self::CURSOR_KEY, $line, now()->addDays(7));
                $this->line("  … {$stats['processed']} procesados (línea {$line})");
            }
        }
        fclose($handle);
        Cache::put(self::CURSOR_KEY, $line, now()->addDays(7));

        $this->info('Resumen → procesados: ' . $stats['processed']
            . ' · creados: ' . $stats['created']
            . ' · actualizados: ' . $stats['updated']
            . ' · completos: ' . $stats['complete']
            . ' · incompletos: ' . $stats['incomplete']
            . ' · omitidos: ' . $stats['skipped']
            . ' · errores: ' . $stats['errors']);
        $this->info('Colombia → detectados: ' . $stats['colombia']
            . ' · marcas Colombia: ' . $stats['colombian_brands']
            . ' · D1: ' . $stats['D1']
            . ' · Éxito: ' . $stats['Éxito']
            . ' · Olímpica: ' . $stats['Olímpica']
            . ' · Ara: ' . $stats['Ara']);
        return self::SUCCESS;
    }

    private function stats(NutritionColombiaClassifier $colombia): int
    {
        $total = NutritionFood::where('source', 'open_food_facts')->count();
        $complete = NutritionFood::where('source', 'open_food_facts')
            ->whereNotNull('calories_per_100g')->where('calories_per_100g', '>', 0)->count();
        $this->info("Open Food Facts en BD → total: {$total} · con calorías: {$complete} · cursor: "
            . Cache::get(self::CURSOR_KEY, 0));

        if ($this->option('country')) {
            $colombiaCount = NutritionFood::where('country', 'colombia')
                ->orWhere('imported_priority_score', '>', 0)->count();
            $byChain = [];
            foreach (['D1', 'Éxito', 'Olímpica', 'Ara'] as $chain) {
                $byChain[] = "{$chain}: " . NutritionFood::where('stores', 'like', '%' . $chain . '%')->count();
            }
            $this->info("Colombia en BD → {$colombiaCount} · " . implode(' · ', $byChain));
        }
        return self::SUCCESS;
    }

    /** ¿El campo stores del producto contiene alguna de las cadenas pedidas? */
    private function storesMatch(NutritionColombiaClassifier $colombia, string $storesRaw, array $filter): bool
    {
        $s = $colombia->normalize($storesRaw);
        foreach ($filter as $needle) {
            if ($needle !== '' && str_contains($s, $colombia->normalize($needle))) {
                return true;
            }
        }
        return false;
    }

    /** Lee la cabecera CSV y avanza el contador de línea. */
    private function csvHeader($handle, int &$line): array
    {
        $header = fgetcsv($handle) ?: [];
        $line++;
        return array_map(fn ($h) => strtolower(trim((string) $h)), $header);
    }

    /** Una línea JSONL → producto OFF (array) o null. */
    private function jsonLineToProduct(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $p = json_decode($line, true);
        return is_array($p) ? $p : null;
    }

    /** Fila CSV → estructura tipo producto OFF que entiende el normalizer. */
    private function csvRowToProduct(array $header, array $row): ?array
    {
        if (! $header || count($row) === 0) {
            return null;
        }
        $r = [];
        foreach ($header as $i => $col) {
            $r[$col] = $row[$i] ?? null;
        }
        $nutr = [];
        foreach ([
            'energy-kcal_100g', 'energy_100g', 'proteins_100g', 'carbohydrates_100g',
            'fat_100g', 'sugars_100g', 'fiber_100g', 'sodium_100g', 'salt_100g', 'saturated-fat_100g',
        ] as $k) {
            if (isset($r[$k]) && $r[$k] !== '') {
                $nutr[$k] = $r[$k];
            }
        }
        return [
            'code'           => $r['code'] ?? null,
            'product_name'   => $r['product_name'] ?? null,
            'product_name_es' => $r['product_name_es'] ?? null,
            'generic_name'   => $r['generic_name'] ?? null,
            'brands'         => $r['brands'] ?? null,
            'categories'     => $r['categories'] ?? null,
            'stores'         => $r['stores'] ?? $r['stores_tags'] ?? null,
            'image_url'      => $r['image_url'] ?? null,
            'serving_size'   => $r['serving_size'] ?? null,
            'countries_tags' => $r['countries_tags'] ?? $r['countries'] ?? null,
            'nutriments'     => $nutr,
        ];
    }
}
