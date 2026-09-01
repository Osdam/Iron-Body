<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorización de /admin/caja/* y /admin/products/* por PERMISO.
 *
 * Antes, estas rutas solo comprobaban que hubiera credencial administrativa:
 * `caja.sell` e `inventory.edit` vivían únicamente en el navegador. Cualquier
 * admin con sesión —Recepción incluida— podía llamar a la API directamente y
 * cambiar precios, costos y existencias. Estos tests fijan que el servidor
 * decide, y que lo hace con 403 y no con 500.
 */
class InventoryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cobrar exige un turno de caja abierto desde que Caja lleva arqueo. Lo que
     * verifican estas pruebas no cambia; solo necesitan el turno para llegar.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->openCashShift();
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => "Test {$role}",
            'email' => 'test-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function product(int $stock = 10): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Bebidas',
            'sale_price' => 3000, 'cost_price' => 1200,
            'stock' => $stock, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $rate->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    // ── A · el autorizado sí puede vender ───────────────────────────────────

    public function test_caso_a_un_admin_con_caja_sell_puede_vender(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ], $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN)))
            ->assertStatus(201);

        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_caso_a2_recepcion_puede_vender_es_su_trabajo(): void
    {
        $product = $this->product();

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)))
            ->assertStatus(201);

        $this->assertSame(9, $product->fresh()->stock);
    }

    // ── B · sin caja.sell → 403 en los endpoints de venta ───────────────────

    public function test_caso_b_sin_caja_sell_la_venta_recibe_403(): void
    {
        $product = $this->product();
        // Administrativo no tiene perfil operativo de caja.
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO));

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'caja.sell');

        $this->assertSame(10, $product->fresh()->stock, 'el stock no se toca');
        $this->assertSame(0, ProductSale::count(), 'no se crea ninguna venta');
    }

    public function test_caso_b2_sin_caja_sell_tampoco_puede_cobrar_un_pedido(): void
    {
        $product = $this->product();
        $sale = ProductSale::create(['channel' => 'app', 'status' => 'pending',
            'payment_method' => 'cash', 'subtotal' => 3000, 'discount' => 0, 'total' => 3000]);
        $sale->items()->create(['product_id' => $product->id, 'name' => $product->name,
            'unit_price' => 3000, 'quantity' => 1, 'subtotal' => 3000]);

        $this->postJson("/api/admin/caja/sales/{$sale->id}/pay", [],
            $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'caja.sell');

        $this->assertSame('pending', $sale->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_caso_b3_recepcion_no_puede_anular_una_venta(): void
    {
        // Anular revierte un hecho económico: exige caja.manage, no caja.sell.
        $sale = ProductSale::create(['channel' => 'pos', 'status' => 'pending',
            'payment_method' => 'cash', 'subtotal' => 3000, 'discount' => 0, 'total' => 3000]);

        $this->postJson("/api/admin/caja/sales/{$sale->id}/cancel", [],
            $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'caja.manage');

        $this->assertSame('pending', $sale->fresh()->status);
    }

    // ── C · el autorizado sí puede tocar inventario ─────────────────────────

    public function test_caso_c_un_admin_con_inventory_edit_mueve_existencias(): void
    {
        $product = $this->product();
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRADOR));

        $this->postJson("/api/admin/products/{$product->id}/entry",
            ['quantity' => 5, 'origin' => 'purchase'], $headers)->assertStatus(201);

        $this->postJson("/api/admin/products/{$product->id}/exit",
            ['quantity' => 2, 'origin' => 'damage', 'reason' => 'rotas'], $headers)->assertStatus(201);

        $this->assertSame(13, $product->fresh()->stock);
    }

    // ── D · sin inventory.edit → 403 en toda escritura de inventario ────────

    public function test_caso_d_recepcion_no_puede_escribir_inventario(): void
    {
        $product = $this->product();
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        // Entrada de mercancía.
        $this->postJson("/api/admin/products/{$product->id}/entry",
            ['quantity' => 100, 'origin' => 'purchase'], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.edit');

        // Salida administrativa.
        $this->postJson("/api/admin/products/{$product->id}/exit",
            ['quantity' => 5, 'origin' => 'loss', 'reason' => 'x'], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.edit');

        // Ajuste heredado por delta.
        $this->postJson("/api/admin/products/{$product->id}/stock",
            ['delta' => -5], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.edit');

        // Editar la ficha: `sale_price` es lo que Caja cobra.
        $this->putJson("/api/admin/products/{$product->id}",
            ['name' => 'Agua', 'sale_price' => 1], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.edit');

        // Crear y borrar catálogo.
        $this->postJson('/api/admin/products',
            ['name' => 'Nuevo', 'sale_price' => 1000], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.create');

        $this->deleteJson("/api/admin/products/{$product->id}", [], $headers)
            ->assertStatus(403)->assertJsonPath('required_permission', 'inventory.delete');

        $fresh = $product->fresh();
        $this->assertSame(10, $fresh->stock, 'las existencias siguen intactas');
        $this->assertSame('Agua 600 ml', $fresh->name, 'la ficha sigue intacta');
        $this->assertSame(1, Product::count(), 'no se creó ni se borró nada');
    }

    public function test_caso_d2_recepcion_si_puede_consultar_inventario(): void
    {
        // Mínimo privilegio no es «nada»: para vender hay que ver qué hay.
        $this->product();
        $headers = $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION));

        $this->getJson('/api/admin/products', $headers)->assertOk();
        $this->getJson('/api/admin/products/stats', $headers)->assertOk();
        $this->getJson('/api/admin/inventory/movements', $headers)->assertOk();
        $this->getJson('/api/admin/caja/sales', $headers)->assertOk();
    }

    // ── E · la llamada directa no esquiva la restricción ────────────────────

    public function test_caso_e_el_token_compartido_de_automatizaciones_no_puede_escribir(): void
    {
        // El token compartido no resuelve a una persona. Puede leer, nunca
        // cobrar ni mover existencias. Misma política que ModerationPermission.
        config(['admin.api_token' => 'token-de-automatizacion-para-pruebas']);
        $product = $this->product();
        $headers = ['Authorization' => 'Bearer token-de-automatizacion-para-pruebas'];

        $this->getJson('/api/admin/products', $headers)->assertOk();

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)->assertStatus(403);

        $this->postJson("/api/admin/products/{$product->id}/exit",
            ['quantity' => 1, 'origin' => 'loss', 'reason' => 'x'], $headers)->assertStatus(403);

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_caso_e2_sin_credencial_sigue_siendo_401_no_403(): void
    {
        // El orden importa: primero QUIÉN eres, después QUÉ puedes hacer.
        $this->postJson('/api/admin/caja/sales', [])->assertStatus(401);
        $this->getJson('/api/admin/products')->assertStatus(401);
    }

    public function test_caso_e3_un_admin_desactivado_no_conserva_permisos(): void
    {
        $admin = $this->admin(Admin::ROLE_SUPER_ADMIN);
        $headers = $this->actingAsAdmin($admin);

        $admin->update(['status' => 'inactive']);

        // La sesión de un admin desactivado deja de ser válida (401 del guard
        // de credencial), y aunque llegara, no tendría permisos.
        $this->getJson('/api/admin/products', $headers)->assertStatus(403);
        $this->assertSame([], CrmPermission::forAdmin($admin->fresh()));
    }

    // ── G · el mapa de permisos, explícito ──────────────────────────────────

    public function test_caso_g_el_mapa_por_rol_respeta_el_minimo_privilegio(): void
    {
        $super = $this->admin(Admin::ROLE_SUPER_ADMIN);
        $administrador = $this->admin(Admin::ROLE_ADMINISTRADOR);
        $recepcion = $this->admin(Admin::ROLE_RECEPCION);
        $administrativo = $this->admin(Admin::ROLE_ADMINISTRATIVO);

        $this->assertTrue(CrmPermission::allows($super, CrmPermission::INVENTORY_DELETE));
        $this->assertTrue(CrmPermission::allows($administrador, CrmPermission::INVENTORY_DELETE));

        $this->assertTrue(CrmPermission::allows($recepcion, CrmPermission::CAJA_SELL));
        $this->assertTrue(CrmPermission::allows($recepcion, CrmPermission::INVENTORY_VIEW));
        $this->assertFalse(CrmPermission::allows($recepcion, CrmPermission::INVENTORY_EDIT));
        $this->assertFalse(CrmPermission::allows($recepcion, CrmPermission::CAJA_MANAGE));

        $this->assertSame([], CrmPermission::forAdmin($administrativo));

        // Sin persona detrás: solo lectura.
        $this->assertSame(CrmPermission::readOnly(), CrmPermission::forAdmin(null));
        $this->assertFalse(CrmPermission::allows(null, CrmPermission::CAJA_SELL));
    }

    public function test_caso_g2_un_rol_desconocido_no_obtiene_nada(): void
    {
        // Falla cerrado: un rol nuevo no hereda permisos por accidente.
        $raro = $this->admin(Admin::ROLE_RECEPCION);
        $raro->forceFill(['role' => 'Rol Inventado'])->save();

        $this->assertSame([], CrmPermission::forAdmin($raro));
    }
}
