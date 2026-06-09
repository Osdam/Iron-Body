<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Productos iniciales del inventario/tienda (idempotente por SKU).
 *
 * Migra el catálogo que antes vivía como mock en el CRM. Edítalo o gestiónalo
 * desde el módulo Inventario del CRM. `visible_in_app = true` → aparece en la
 * Tienda de la app.
 *
 *   php artisan db:seed --class='Database\Seeders\ProductSeeder'
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->products() as $row) {
            Product::firstOrCreate(['sku' => $row['sku']], $row);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function products(): array
    {
        return [
            ['sku' => 'SUP-WHEY-2LB', 'name' => 'Proteína Whey 2 lb', 'category' => 'Suplementos', 'sale_price' => 135000, 'cost_price' => 95000, 'stock' => 18, 'min_stock' => 6, 'supplier' => 'NutriFit', 'visible_in_app' => true, 'description' => 'Proteína de suero, 2 libras. Apoya recuperación y ganancia muscular.'],
            ['sku' => 'SUP-CREA-300', 'name' => 'Creatina Monohidrato 300 g', 'category' => 'Suplementos', 'sale_price' => 75000, 'cost_price' => 48000, 'stock' => 12, 'min_stock' => 5, 'supplier' => 'NutriFit', 'visible_in_app' => true, 'description' => 'Creatina monohidrato micronizada.'],
            ['sku' => 'ACC-GUA-L', 'name' => 'Guantes de entrenamiento L', 'category' => 'Accesorios', 'sale_price' => 45000, 'cost_price' => 28000, 'stock' => 5, 'min_stock' => 8, 'supplier' => 'FitGear', 'visible_in_app' => true, 'description' => 'Guantes con soporte de muñeca, talla L.'],
            ['sku' => 'ACC-SHAKER', 'name' => 'Shaker 600 ml', 'category' => 'Accesorios', 'sale_price' => 25000, 'cost_price' => 12000, 'stock' => 30, 'min_stock' => 10, 'supplier' => 'FitGear', 'visible_in_app' => true, 'description' => 'Vaso mezclador con rejilla.'],
            ['sku' => 'BEB-HID-500', 'name' => 'Bebida hidratante 500 ml', 'category' => 'Bebidas', 'sale_price' => 5000, 'cost_price' => 2500, 'stock' => 42, 'min_stock' => 20, 'supplier' => 'Distribuidora Norte', 'visible_in_app' => true, 'description' => 'Bebida con electrolitos.'],
            ['sku' => 'BEB-AGUA-600', 'name' => 'Agua 600 ml', 'category' => 'Bebidas', 'sale_price' => 3000, 'cost_price' => 1200, 'stock' => 60, 'min_stock' => 24, 'supplier' => 'Distribuidora Norte', 'visible_in_app' => true, 'description' => 'Agua sin gas.'],
            ['sku' => 'MER-CAM-IB', 'name' => 'Camiseta Iron Body', 'category' => 'Mercancía', 'sale_price' => 60000, 'cost_price' => 32000, 'stock' => 20, 'min_stock' => 6, 'supplier' => 'TextilPro', 'visible_in_app' => true, 'description' => 'Camiseta deportiva con logo Iron Body.'],
        ];
    }
}
