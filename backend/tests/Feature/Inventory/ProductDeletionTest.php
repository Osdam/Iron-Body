<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSaleItem;
use App\Models\TaxRate;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Eliminar un producto no puede destruir el historial económico.
 *
 * Un producto vendido está citado por líneas de venta y comprobantes; uno con
 * movimientos, por su libro de existencias. Borrarlo dejaría esas filas
 * apuntando a nada.
 */
class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Super', 'email' => 'super-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
    }

    private function product(string $name = 'Agua 600 ml'): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => $name, 'category' => 'Bebidas',
            'sale_price' => 3000, 'cost_price' => 1200,
            'stock' => 10, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $rate->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    public function test_un_producto_sin_historial_se_elimina_de_verdad(): void
    {
        // Recién creado con stock 0: ni ventas ni movimientos que preservar.
        $product = Product::create([
            'name' => 'Producto sin usar', 'category' => 'Otros',
            'sale_price' => 1000, 'stock' => 0, 'min_stock' => 0, 'active' => true,
        ]);

        $this->deleteJson("/api/admin/products/{$product->id}", [], $this->actingAsAdmin($this->admin()))
            ->assertOk()
            ->assertJsonPath('archived', false);

        $this->assertSame(0, Product::withTrashed()->where('id', $product->id)->count());
    }

    public function test_un_producto_con_ventas_se_archiva_y_conserva_su_historial(): void
    {
        $product = $this->product();
        $this->openCashShift();
        $headers = $this->actingAsAdmin($this->admin());

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)->assertStatus(201);

        $res = $this->deleteJson("/api/admin/products/{$product->id}", [], $headers)->assertOk();
        $this->assertTrue($res->json('archived'));

        // Sigue existiendo, archivado.
        $archived = Product::withTrashed()->find($product->id);
        $this->assertNotNull($archived, 'el producto NO se destruyó');
        $this->assertNotNull($archived->deleted_at);
        $this->assertFalse((bool) $archived->active);
        $this->assertFalse((bool) $archived->visible_in_app);

        // Y el historial económico intacto: la línea conserva nombre y precio.
        $item = ProductSaleItem::where('product_id', $product->id)->first();
        $this->assertNotNull($item, 'la línea de venta sigue ahí');
        $this->assertSame('Agua 600 ml', $item->name);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_un_producto_con_movimientos_pero_sin_ventas_tambien_se_archiva(): void
    {
        $product = $this->product('Con merma');
        app(InventoryService::class)->registerExit(
            product: $product, quantity: 1,
            origin: InventoryMovementOrigin::DAMAGE, reason: 'rota',
        );

        $this->deleteJson("/api/admin/products/{$product->id}", [], $this->actingAsAdmin($this->admin()))
            ->assertOk()
            ->assertJsonPath('archived', true);

        $this->assertNotNull(Product::withTrashed()->find($product->id));
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_un_producto_archivado_no_aparece_para_ventas_nuevas(): void
    {
        $product = $this->product();
        $this->openCashShift();
        $headers = $this->actingAsAdmin($this->admin());

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)->assertStatus(201);

        $this->deleteJson("/api/admin/products/{$product->id}", [], $headers)->assertOk();

        // Fuera del catálogo activo.
        $listado = $this->getJson('/api/admin/products', $headers)->assertOk()->json('data');
        $this->assertNotContains($product->id, array_column($listado, 'id'));
    }

    public function test_el_crm_puede_preguntar_antes_que_va_a_pasar(): void
    {
        // Sin esto la confirmación prometería una cosa y el backend haría otra.
        $limpio = Product::create([
            'name' => 'Limpio', 'category' => 'Otros',
            'sale_price' => 1000, 'stock' => 0, 'min_stock' => 0, 'active' => true,
        ]);
        $headers = $this->actingAsAdmin($this->admin());

        $this->getJson("/api/admin/products/{$limpio->id}/usage", $headers)
            ->assertOk()
            ->assertJsonPath('can_hard_delete', true)
            ->assertJsonPath('usage.sale_items', 0);

        $usado = $this->product('Usado');
        app(InventoryService::class)->registerExit(
            product: $usado, quantity: 1,
            origin: InventoryMovementOrigin::LOSS, reason: 'faltante',
        );

        $this->getJson("/api/admin/products/{$usado->id}/usage", $headers)
            ->assertOk()
            ->assertJsonPath('can_hard_delete', false)
            ->assertJsonPath('usage.movements', 1);
    }

    public function test_sin_inventory_delete_no_se_elimina(): void
    {
        $product = $this->product();
        $recepcion = Admin::create([
            'name' => 'Ana', 'email' => 'ana-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);

        $this->deleteJson("/api/admin/products/{$product->id}", [], $this->actingAsAdmin($recepcion))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'inventory.delete');

        $this->assertNull(Product::find($product->id)->deleted_at);
    }
}
