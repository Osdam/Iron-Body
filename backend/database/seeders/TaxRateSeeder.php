<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Catálogo de tarifas tributarias para facturación electrónica (Factus).
 * Idempotente (updateOrCreate por code). Correr con:
 *   php artisan db:seed --class=Database\Seeders\TaxRateSeeder
 *
 * El "incluido / no incluido" vive en price_includes_tax de la tarifa; al
 * asignarla a un plan/producto se sincroniza su price_includes_tax.
 * El código Factus de items.taxes[].code va en factus_tribute_id (IVA = 01).
 */
class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido',    'rate' => 19.00, 'factus_tribute_id' => '01', 'price_includes_tax' => true],
            ['code' => 'IVA_19_EXCL', 'name' => 'IVA 19% no incluido', 'rate' => 19.00, 'factus_tribute_id' => '01', 'price_includes_tax' => false],
            ['code' => 'EXCLUDED',    'name' => 'Excluido',            'rate' => 0.00,  'factus_tribute_id' => null, 'price_includes_tax' => true],
            ['code' => 'EXEMPT',      'name' => 'Exento',              'rate' => 0.00,  'factus_tribute_id' => null, 'price_includes_tax' => true],
            ['code' => 'NO_GRAVADO',  'name' => 'No gravado',          'rate' => 0.00,  'factus_tribute_id' => null, 'price_includes_tax' => true],
        ];

        foreach ($rates as $r) {
            TaxRate::updateOrCreate(
                ['code' => $r['code']],
                array_merge($r, ['description' => $r['name'], 'active' => true]),
            );
        }
    }
}
