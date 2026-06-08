<?php

namespace App\Console\Commands;

use App\Models\NutritionFood;
use App\Services\Nutrition\NutritionFoodNormalizer;
use App\Services\Nutrition\Providers\OpenFoodFactsNutritionProvider;
use Illuminate\Console\Command;

/**
 * Diagnostica/repara alimentos externos cacheados con macros en 0 por errores de
 * normalización previos. Re-consulta Open Food Facts por código de barras y los
 * re-normaliza con el normalizador corregido (kJ→kcal, campos _es, etc.).
 *
 * NUNCA borra entradas del usuario ni inventa macros: si el producto no trae
 * datos, lo deja marcado como incompleto (sin cambios). Sin --fix-zero-macros es
 * solo lectura (dry-run).
 */
class NutritionDoctor extends Command
{
    protected $signature = 'nutrition:doctor
        {--barcode= : Reparar solo este código de barras}
        {--fix-zero-macros : Aplicar cambios (sin esta bandera es dry-run)}';

    protected $description = 'Detecta/repara alimentos con macros en 0 (re-consulta Open Food Facts).';

    public function handle(
        OpenFoodFactsNutritionProvider $off,
        NutritionFoodNormalizer $normalizer
    ): int {
        $apply = (bool) $this->option('fix-zero-macros');
        $barcode = $this->option('barcode');

        $query = NutritionFood::query()
            ->whereIn('source', ['open_food_facts', 'usda', 'nutritionix'])
            ->whereNotNull('barcode');
        if ($barcode) {
            $query->where('barcode', preg_replace('/\D/', '', (string) $barcode));
        }

        $candidates = $query->get()->filter(fn (NutritionFood $f) => ! $f->isMacroComplete());

        $this->info(($apply ? '[FIX] ' : '[DRY-RUN] ')
            . "Alimentos externos con macros incompletos: {$candidates->count()}");

        $fixed = 0;
        $stillIncomplete = 0;
        $skipped = 0;

        foreach ($candidates as $food) {
            if (! $off->isEnabled()) {
                $skipped++;
                continue;
            }
            $normalized = $off->lookupByBarcode((string) $food->barcode);
            if (! $normalized) {
                $stillIncomplete++;
                $this->line("  · {$food->barcode}: sin datos en Open Food Facts → queda incompleto");
                continue;
            }
            // ¿La re-normalización trae los 4 macros base?
            $p = $normalized['per_100g'] ?? [];
            $complete = ($p['calories'] ?? null) !== null
                && ($p['protein'] ?? null) !== null
                && ($p['carbs'] ?? null) !== null
                && ($p['fat'] ?? null) !== null;

            if (! $complete) {
                $stillIncomplete++;
                $this->line("  · {$food->barcode}: el producto sigue sin macros completos");
                continue;
            }
            if ($apply) {
                $normalized['barcode'] = $normalized['barcode'] ?: $food->barcode;
                $normalizer->cache($normalized);
            }
            $fixed++;
            $this->line("  ✓ {$food->barcode}: "
                . ($apply ? 'reparado' : 'reparable')
                . " (kcal=" . ($p['calories'] ?? '?') . ")");
        }

        $this->info("Resumen → reparables/reparados: {$fixed} · siguen incompletos: {$stillIncomplete} · omitidos: {$skipped}");
        if (! $apply && $fixed > 0) {
            $this->warn('Ejecuta con --fix-zero-macros para aplicar los cambios.');
        }
        return self::SUCCESS;
    }
}
