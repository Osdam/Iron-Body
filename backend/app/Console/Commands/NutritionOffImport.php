<?php

namespace App\Console\Commands;

use App\Models\NutritionFood;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Importador masivo OPCIONAL de Open Food Facts desde un dump LOCAL (CSV o
 * JSONL) hacia la base propia. Evita abusar de la API en vivo: el admin descarga
 * el dump y lo carga por lotes. Deshabilitado por defecto (requiere --file o
 * NUTRITION_OFF_IMPORT_ENABLED=true). No se ejecuta en migraciones.
 *
 *   php artisan nutrition:off-import --file=/path/products.jsonl --limit=10000
 *   php artisan nutrition:off-import --file=/path/products.csv --country=colombia
 *   php artisan nutrition:off-import --resume
 *   php artisan nutrition:off-import --stats
 *
 * Reglas: salta productos sin barcode; normaliza con el mismo normalizer;
 * incompletos se guardan como incompletos (no 0 falso); upsert por barcode/
 * external_id; procesa por lotes; reanudable; logs/resumen sin datos sensibles.
 */
class NutritionOffImport extends Command
{
    protected $signature = 'nutrition:off-import
        {--file= : Ruta del dump local (.jsonl o .csv)}
        {--country= : Filtra por país (countries_tags contiene este valor)}
        {--limit= : Máximo de productos a procesar}
        {--resume : Reanuda desde el último cursor guardado}
        {--stats : Solo muestra estadísticas, no importa}';

    protected $description = 'Importa productos de Open Food Facts desde un dump local (opcional).';

    private const CURSOR_KEY = 'nutrition_off_import_cursor';

    public function handle(NutritionFoodNormalizer $normalizer): int
    {
        if ($this->option('stats')) {
            return $this->stats();
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
        $resumeFrom = $this->option('resume') ? (int) Cache::get(self::CURSOR_KEY, 0) : 0;
        $isCsv = str_ends_with(strtolower($file), '.csv');

        $this->info('Importando ' . ($isCsv ? 'CSV' : 'JSONL') . " desde {$file}"
            . ($resumeFrom ? " (reanudando en línea {$resumeFrom})" : ''));

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'incomplete' => 0, 'skipped' => 0, 'errors' => 0];
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
            // Filtro de país opcional.
            if ($country && ! str_contains(strtolower((string) ($product['countries_tags'] ?? $product['countries'] ?? '')), strtolower($country))) {
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
                if (! $food->isMacroComplete()) {
                    $stats['incomplete']++;
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
            . ' · incompletos: ' . $stats['incomplete']
            . ' · omitidos: ' . $stats['skipped']
            . ' · errores: ' . $stats['errors']);
        return self::SUCCESS;
    }

    private function stats(): int
    {
        $total = NutritionFood::where('source', 'open_food_facts')->count();
        $complete = NutritionFood::where('source', 'open_food_facts')
            ->whereNotNull('calories_per_100g')->where('calories_per_100g', '>', 0)->count();
        $this->info("Open Food Facts en BD → total: {$total} · con calorías: {$complete} · cursor: "
            . Cache::get(self::CURSOR_KEY, 0));
        return self::SUCCESS;
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
            'image_url'      => $r['image_url'] ?? null,
            'serving_size'   => $r['serving_size'] ?? null,
            'countries_tags' => $r['countries_tags'] ?? $r['countries'] ?? null,
            'nutriments'     => $nutr,
        ];
    }
}
