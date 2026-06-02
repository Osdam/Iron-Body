<?php

namespace Database\Seeders;

use App\Models\NutritionFoodItem;
use Illuminate\Database\Seeder;

/**
 * Catálogo base de alimentos (macros por 100 g / porción). Antes estaba
 * hardcodeado en Flutter (food_library.dart); ahora vive en PostgreSQL como
 * alimentos globales (member_id null). Idempotente.
 */
class NutritionFoodSeeder extends Seeder
{
    public function run(): void
    {
        if (NutritionFoodItem::query()->whereNull('member_id')->exists()) {
            return;
        }

        $foods = [
            ['Arroz cocido', 130, 2.7, 28.0, 0.3, '100 g'],
            ['Pechuga de pollo', 165, 31.0, 0.0, 3.6, '100 g'],
            ['Huevo entero', 155, 13.0, 1.1, 11.0, '100 g'],
            ['Avena', 389, 17.0, 66.0, 7.0, '100 g'],
            ['Banano', 89, 1.1, 23.0, 0.3, '100 g'],
            ['Arepa de maíz', 175, 4.0, 36.0, 2.0, '100 g'],
            ['Carne magra (res)', 250, 26.0, 0.0, 15.0, '100 g'],
            ['Atún en agua', 116, 26.0, 0.0, 1.0, '100 g'],
            ['Papa cocida', 77, 2.0, 17.0, 0.1, '100 g'],
            ['Yogur griego', 59, 10.0, 3.6, 0.4, '100 g'],
            ['Leche entera', 61, 3.2, 4.8, 3.3, '100 ml'],
            ['Pan integral', 247, 13.0, 41.0, 4.2, '100 g'],
            ['Aguacate', 160, 2.0, 9.0, 15.0, '100 g'],
            ['Queso blanco', 264, 18.0, 3.4, 20.0, '100 g'],
            ['Frijoles cocidos', 127, 8.7, 23.0, 0.5, '100 g'],
            ['Lentejas cocidas', 116, 9.0, 20.0, 0.4, '100 g'],
            ['Pasta cocida', 131, 5.0, 25.0, 1.1, '100 g'],
            ['Salmón', 208, 20.0, 0.0, 13.0, '100 g'],
            ['Manzana', 52, 0.3, 14.0, 0.2, '100 g'],
            ['Proteína Whey', 120, 24.0, 3.0, 1.5, '1 scoop (30 g)'],
        ];

        foreach ($foods as [$name, $cal, $prot, $carbs, $fat, $serving]) {
            NutritionFoodItem::create([
                'member_id' => null,
                'name' => $name,
                'calories' => $cal,
                'protein_g' => $prot,
                'carbs_g' => $carbs,
                'fat_g' => $fat,
                'serving_label' => $serving,
                'source' => 'base',
            ]);
        }
    }
}
