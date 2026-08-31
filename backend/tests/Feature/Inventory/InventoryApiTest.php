<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato HTTP de Inventario y Caja tras separar los dominios.
 */
class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Producto con tratamiento tributario asignado, como los de producción:
     * PricingService rechaza cobrar un producto facturable sin tarifa, y ese
     * guard es anterior a este trabajo.
     */
    private function product(int $stock = 10): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml',
            'category' => 'Bebidas',
            'sale_price' => 3000,
            'cost_price' => 1200,
            'stock' => $stock,
            'min_stock' => 2,
            'active' => true,
            'visible_in_app' => true,
            'tax_rate_id' => $rate->id,
            'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    // ── Caja rechaza vender lo que no hay (validación en BACKEND) ───────────

    public function test_caja_rechaza_la_venta_sin_stock_suficiente(): void
    {
        $product = $this->product(stock: 1);

        $response = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payment_method' => 'cash',
            'paid' => true,
        ], $this->actingAsAdmin());

        $response->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_stock')
            ->assertJsonPath('stock.requested', 5)
            ->assertJsonPath('stock.available', 1);

        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, ProductSale::count(), 'no se crea la venta');
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_caja_vende_y_deja_movimiento_de_salida_por_venta(): void
    {
        $product = $this->product(stock: 10);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'paid' => true,
        ], $this->actingAsAdmin())->assertStatus(201);

        $this->assertSame(8, $product->fresh()->stock);

        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(InventoryMovementOrigin::SALE_CAFETERIA, $movement->origin);
        $this->assertSame(ProductSale::first()->getMorphClass(), $movement->reference_type);
    }

    // ── Inventario: entradas y salidas administrativas ──────────────────────

    public function test_entrada_de_inventario_suma_y_devuelve_el_movimiento(): void
    {
        $product = $this->product(stock: 0);

        $this->postJson("/api/admin/products/{$product->id}/entry", [
            'quantity' => 10,
            'origin' => 'purchase',
            'reason' => 'Compra a proveedor',
        ], $this->actingAsAdmin())
            ->assertStatus(201)
            ->assertJsonPath('data.stock', 10)
            ->assertJsonPath('movement.type', 'in')
            ->assertJsonPath('movement.stock_before', 0)
            ->assertJsonPath('movement.stock_after', 10);
    }

    public function test_salida_administrativa_exige_motivo(): void
    {
        $product = $this->product(stock: 10);

        $this->postJson("/api/admin/products/{$product->id}/exit", [
            'quantity' => 2,
            'origin' => 'damage',
        ], $this->actingAsAdmin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_la_salida_administrativa_no_admite_origen_de_venta(): void
    {
        $product = $this->product(stock: 10);

        // Registrar una «venta» desde Inventario tiene que ser imposible: el
        // punto de venta ya no vive aquí.
        $this->postJson("/api/admin/products/{$product->id}/exit", [
            'quantity' => 2,
            'origin' => 'sale_cafeteria',
            'reason' => 'intento de vender desde inventario',
        ], $this->actingAsAdmin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('origin');

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_salida_administrativa_rechaza_mas_de_lo_disponible(): void
    {
        $product = $this->product(stock: 3);

        $this->postJson("/api/admin/products/{$product->id}/exit", [
            'quantity' => 5,
            'origin' => 'loss',
            'reason' => 'faltante de conteo',
        ], $this->actingAsAdmin())
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_stock');

        $this->assertSame(3, $product->fresh()->stock);
    }

    // ── Historial real (antes era estado local del navegador) ───────────────

    public function test_el_historial_de_movimientos_se_sirve_desde_el_backend(): void
    {
        $product = $this->product(stock: 10);
        $headers = $this->actingAsAdmin();

        $this->postJson("/api/admin/products/{$product->id}/exit", [
            'quantity' => 1, 'origin' => 'damage', 'reason' => 'botella rota',
        ], $headers)->assertStatus(201);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)->assertStatus(201);

        $response = $this->getJson('/api/admin/inventory/movements', $headers)->assertOk();

        $origins = collect($response->json('data'))->pluck('origin')->all();
        $this->assertContains('damage', $origins);
        $this->assertContains('sale_cafeteria', $origins);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_crear_producto_con_stock_inicial_deja_movimiento(): void
    {
        $this->postJson('/api/admin/products', [
            'name' => 'Proteína Whey',
            'category' => 'Suplementos',
            'sale_price' => 135000,
            'cost_price' => 95000,
            'stock' => 12,
            'min_stock' => 3,
        ], $this->actingAsAdmin())
            ->assertStatus(201)
            ->assertJsonPath('data.stock', 12);

        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(InventoryMovementOrigin::INITIAL_STOCK, $movement->origin);
        $this->assertSame(0, $movement->stock_before);
        $this->assertSame(12, $movement->stock_after);
    }

    public function test_el_ajuste_heredado_por_delta_ahora_deja_traza(): void
    {
        $product = $this->product(stock: 10);

        $this->postJson("/api/admin/products/{$product->id}/stock", [
            'delta' => -4,
            'reason' => 'conteo físico',
        ], $this->actingAsAdmin())->assertOk();

        $this->assertSame(6, $product->fresh()->stock);
        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(InventoryMovementOrigin::ADJUSTMENT, $movement->origin);
        $this->assertSame('conteo físico', $movement->reason);
    }

    public function test_el_ajuste_heredado_ya_no_recorta_en_silencio(): void
    {
        $product = $this->product(stock: 2);

        $this->postJson("/api/admin/products/{$product->id}/stock", [
            'delta' => -10,
        ], $this->actingAsAdmin())
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_stock');

        $this->assertSame(2, $product->fresh()->stock, 'antes habría quedado en 0 sin avisar');
    }
}
