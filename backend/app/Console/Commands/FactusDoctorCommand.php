<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Product;
use App\Services\Billing\Factus\FactusConfigValidator;
use Illuminate\Console\Command;

/**
 * Doctor de PRODUCCIÓN de facturación electrónica (READ-ONLY).
 *
 * Valida la configuración necesaria para activar Factus en producción SIN
 * emitir nada y SIN llamar a Factus (validación de config local + estado de
 * los catálogos tributarios en BD).
 *
 * Bloquea (exit 1) si falta: credenciales, base_url productiva, rango de
 * factura, rango de nota crédito, municipio por defecto, datos del emisor,
 * la CONFIRMACIÓN TRIBUTARIA del contador, o si hay planes/productos activos
 * sin tax_rate_id (tratamiento de IVA sin decidir). No se asume IVA.
 *
 * Uso:  php artisan billing:factus-doctor
 */
class FactusDoctorCommand extends Command
{
    protected $signature = 'billing:factus-doctor';
    protected $description = 'Valida la configuración productiva de Factus sin emitir ni llamar a Factus (read-only).';

    public function handle(): int
    {
        $validator = FactusConfigValidator::fromConfig();
        $issues = $validator->productionReadiness();

        // Resumen de configuración (sin secretos: solo presente/ausente).
        $creds = (array) config('billing.credentials');
        $this->line('Ambiente y configuración:');
        $this->table(['Parámetro', 'Valor / Estado'], [
            ['APP_ENV', $this->laravel->environment()],
            ['FACTUS_ENABLED', config('billing.enabled') ? 'true' : 'false'],
            ['FACTUS_ENV', (string) config('billing.env')],
            ['base_url', (string) config('billing.base_url')],
            ['credenciales', $this->credsState($creds)],
            ['rango factura', config('billing.numbering.range_id') ?: '— FALTA —'],
            ['rango nota crédito', config('billing.numbering.credit_range_id') ?: '— FALTA —'],
            ['municipio por defecto', config('billing.defaults.municipality_code') ?: '— FALTA —'],
            ['emisor NIT', config('billing.company.nit') ?: '— FALTA —'],
            ['emisor DV', config('billing.company.dv') ?: '— FALTA —'],
            ['emisor nombre', config('billing.company.name') ?: '— FALTA —'],
            ['decisión tributaria', config('billing.tax_decision_confirmed') ? 'CONFIRMADA' : '🔒 PENDIENTE'],
        ]);

        // Catálogo tributario en BD: planes/productos activos sin tax_rate_id.
        $plansMissing = Plan::query()->where('active', true)->whereNull('tax_rate_id')->get();
        $productsMissing = Product::query()->where('active', true)->whereNull('tax_rate_id')->get();

        if ($plansMissing->isNotEmpty()) {
            $issues[] = "Hay {$plansMissing->count()} plan(es) activo(s) sin tax_rate_id: "
                . $plansMissing->take(10)->pluck('name')->implode(', ');
        }
        if ($productsMissing->isNotEmpty()) {
            $issues[] = "Hay {$productsMissing->count()} producto(s) activo(s) sin tax_rate_id: "
                . $productsMissing->take(10)->pluck('name')->implode(', ');
        }

        $this->line('');
        if ($issues === []) {
            $this->info('✔ LISTO PARA PRODUCCIÓN. Configuración y decisión tributaria completas.');
            $this->line('  Siguiente paso: smoke productivo (1 factura real) y luego FACTUS_ENABLED=true.');
            return self::SUCCESS;
        }

        $this->error('✖ BLOQUEADO. No actives FACTUS_ENABLED=true. Falta:');
        foreach ($issues as $i) {
            $this->line('   - ' . $i);
        }

        return self::FAILURE;
    }

    private function credsState(array $creds): string
    {
        foreach (['username', 'password', 'client_id', 'client_secret'] as $k) {
            if (empty($creds[$k])) {
                return '— INCOMPLETAS —';
            }
        }

        return 'configuradas';
    }
}
