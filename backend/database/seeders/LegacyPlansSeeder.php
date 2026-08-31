<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planes que existían en el sistema anterior y NO tienen equivalente en el
 * catálogo actual (PlansSeeder). Hay que crearlos antes de importar los socios.
 *
 * Por qué son necesarios: `MemberPayload::featuresFor()` resuelve los módulos
 * de la app buscando `Plan::where('name', $user->plan)`. Si el plan del socio
 * migrado no existe como fila en `plans`, el socio entra con TODO bloqueado
 * aunque su membresía esté vigente. Estos 7 planes cubren a los ~40 socios
 * vigentes que vienen con planes promocionales o de cortesía.
 *
 * Se crean con `active = false`: son históricos, no deben aparecer en el
 * catálogo de compra de la app. Eso no afecta la resolución de features, que
 * busca por nombre sin filtrar por `active`.
 *
 * Igual que PlansSeeder, no definen `features`: heredan `Plan::defaultFeatures()`
 * vía `resolvedFeatures()`, el mismo trato que los planes del catálogo real.
 *
 * Idempotente:
 *   php artisan db:seed --class=Database\\Seeders\\LegacyPlansSeeder
 */
class LegacyPlansSeeder extends Seeder
{
    private const BENEFITS = [
        'Acceso al gimnasio durante la vigencia del plan',
        'Plan heredado del sistema anterior',
    ];

    public function run(): void
    {
        // Nombre EXACTO como viene en el export del sistema anterior: el
        // importador enlaza por nombre y cualquier diferencia deja al socio sin
        // plan resuelto.
        $plans = [
            ['name' => 'PROMO X4',                   'price' => 75000,  'duration_days' => 30],
            ['name' => 'TOTAL ACCESS ESPECIAL',      'price' => 0,      'duration_days' => 30],
            ['name' => 'INGRESO EMPLEADOS',          'price' => 0,      'duration_days' => 360],
            ['name' => 'MERRY CRHYSTMAS',            'price' => 0,      'duration_days' => 30],
            ['name' => 'Entrada 1 dia',              'price' => 10000,  'duration_days' => 1],
            ['name' => 'EVOLUCION IRONBODY X20',     'price' => 220000, 'duration_days' => 20],
            // No es una membresía de acceso sino el registro de una comisión de
            // entrenamiento personalizado. Se crea solo para que los socios cuyo
            // único registro es este no queden con un plan inexistente.
            ['name' => 'Comisión de personalizados', 'price' => 0,      'duration_days' => 30],
        ];

        foreach ($plans as $i => $plan) {
            Plan::updateOrCreate(
                ['name' => $plan['name']],
                [
                    'tier' => 'lite',
                    'price' => $plan['price'],
                    'original_price' => null,
                    'duration_days' => $plan['duration_days'],
                    'sort_order' => 100 + $i,
                    'benefits' => json_encode(self::BENEFITS, JSON_UNESCAPED_UNICODE),
                    'active' => false,
                ],
            );
        }
    }
}
