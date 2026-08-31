<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Frontera entre INVENTARIO, CAJA y VENTA DE PLAN.
 *
 * Fija por contrato lo que antes solo era una intención escrita en la
 * documentación: que un plan no mueve existencias, que una venta de producto sí
 * y siempre con traza, y que cobrar sin stock es un error en voz alta y no un
 * descuadre silencioso.
 */
class InventorySeparationTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10, float $price = 3000, float $cost = 1200): Product
    {
        return Product::create([
            'name' => 'Agua 600 ml',
            'category' => 'Bebidas',
            'sale_price' => $price,
            'cost_price' => $cost,
            'stock' => $stock,
            'min_stock' => 2,
            'active' => true,
            'visible_in_app' => true,
        ]);
    }

    private function saleFor(Product $product, int $qty): ProductSale
    {
        $sale = ProductSale::create([
            'channel' => 'pos',
            'status' => 'pending',
            'payment_method' => 'cash',
            'subtotal' => $product->sale_price * $qty,
            'discount' => 0,
            'total' => $product->sale_price * $qty,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'unit_price' => $product->sale_price,
            'quantity' => $qty,
            'subtotal' => $product->sale_price * $qty,
        ]);

        return $sale->load('items');
    }

    // ── CASO 3 y 4: la venta de cafetería descuenta y deja movimiento ────────

    public function test_venta_de_producto_descuenta_stock_y_genera_movimiento_automatico(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->saleFor($product, 2);

        $sale->markPaid('cash');

        $this->assertSame(8, $product->fresh()->stock, 'stock 10 → 8');

        $movement = InventoryMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame(InventoryMovementType::OUT, $movement->type);
        $this->assertSame(InventoryMovementOrigin::SALE_CAFETERIA, $movement->origin);
        $this->assertSame(2, $movement->quantity);
        $this->assertSame(10, $movement->stock_before);
        $this->assertSame(8, $movement->stock_after);

        // Trazable hasta el comprobante de la venta.
        $this->assertSame($sale->getMorphClass(), $movement->reference_type);
        $this->assertSame($sale->id, (int) $movement->reference_id);
        $this->assertTrue($movement->origin->isAutomatic());
    }

    // ── CASO 8: sin stock, no hay venta ─────────────────────────────────────

    public function test_cobrar_sin_stock_suficiente_falla_y_no_deja_la_venta_pagada(): void
    {
        $product = $this->product(stock: 1);
        $sale = $this->saleFor($product, 5);

        try {
            $sale->markPaid('cash');
            $this->fail('markPaid debía lanzar InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(5, $e->requested);
            $this->assertSame(1, $e->available);
        }

        $this->assertSame(1, $product->fresh()->stock, 'el stock no se toca');
        $this->assertSame('pending', $sale->fresh()->status, 'la venta NO queda cobrada');
        $this->assertSame(0, InventoryMovement::count(), 'no se escribe ningún movimiento');
    }

    public function test_el_stock_nunca_queda_negativo(): void
    {
        $product = $this->product(stock: 3);

        $this->expectException(InsufficientStockException::class);
        app(InventoryService::class)->registerExit(
            product: $product,
            quantity: 4,
            origin: InventoryMovementOrigin::DAMAGE,
            reason: 'prueba',
        );
    }

    // ── CASO 5 y 6: la venta de plan no toca inventario ─────────────────────

    public function test_venta_de_plan_no_genera_movimiento_de_inventario(): void
    {
        $product = $this->product(stock: 10);

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Mensual',
            'price' => 80000,
            'duration_days' => 30,
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertSame('paid', $payment->status);
        $this->assertSame(10, $product->fresh()->stock, 'el inventario no cambia');
        $this->assertSame(0, InventoryMovement::count(), 'un plan no escribe en el libro de inventario');
    }

    // ── CASO 7: salida administrativa, separada de la venta ─────────────────

    public function test_salida_manual_por_dano_exige_motivo_y_queda_trazada(): void
    {
        $product = $this->product(stock: 10);
        $actor = User::factory()->create(['name' => 'Cajera Ana']);

        $movement = app(InventoryService::class)->registerExit(
            product: $product,
            quantity: 3,
            origin: InventoryMovementOrigin::DAMAGE,
            reason: 'Botellas rotas en bodega',
            user: $actor,
        );

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(InventoryMovementOrigin::DAMAGE, $movement->origin);
        $this->assertSame('Botellas rotas en bodega', $movement->reason);
        $this->assertSame($actor->id, $movement->user_id);
        $this->assertSame('Cajera Ana', $movement->user_name);
        $this->assertNull($movement->reference_type, 'una merma no tiene venta detrás');
        $this->assertSame(10, $movement->stock_before);
        $this->assertSame(7, $movement->stock_after);
    }

    public function test_entrada_de_mercancia_suma_y_traza(): void
    {
        $product = $this->product(stock: 0);

        app(InventoryService::class)->registerEntry(
            product: $product,
            quantity: 10,
            origin: InventoryMovementOrigin::PURCHASE,
            reason: 'Compra a proveedor',
        );

        $this->assertSame(10, $product->fresh()->stock);
        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(InventoryMovementType::IN, $movement->type);
        $this->assertSame(0, $movement->stock_before);
        $this->assertSame(10, $movement->stock_after);
    }

    // ── Idempotencia: cobrar dos veces no descuenta dos veces ───────────────

    public function test_cobrar_dos_veces_no_descuenta_dos_veces(): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->saleFor($product, 2);

        $sale->markPaid('cash');
        $sale->fresh()->markPaid('cash');

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::sales()->count());
    }

    // ── El mismo producto en dos líneas cuenta como una sola demanda ────────

    public function test_la_validacion_suma_las_lineas_repetidas_del_mismo_producto(): void
    {
        $product = $this->product(stock: 5);

        $this->expectException(InsufficientStockException::class);
        app(InventoryService::class)->assertAvailability([
            ['product_id' => $product->id, 'quantity' => 3],
            ['product_id' => $product->id, 'quantity' => 3],
        ]);
    }
}
