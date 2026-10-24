<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementOrigin;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\TaxRate;
use App\Services\Inventory\InventoryService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EL COSTO DE COMPRA SE VE SI SE TIENE LA LLAVE.
 *
 * Lo que cuesta la mercancía es con cuánto se queda el gimnasio en cada venta,
 * y hay dueños que no lo quieren a la vista de quien atiende el mostrador. El
 * precio de VENTA y las existencias siguen siendo de todos —sin eso no se
 * trabaja—; lo reservado es el costo.
 *
 * Y no se reserva escondiendo un cuadro en la pantalla: el servidor NO MANDA el
 * campo. Estas pruebas llaman a la API como la llamaría cualquiera con la
 * sesión abierta y una pestaña del navegador, que es por donde se iba a mirar.
 *
 * El costo no sale solo por la ficha del producto, así que se comprueban las
 * cuatro puertas: la ficha, el valor del inventario a costo, el importe de cada
 * movimiento de existencias y la utilidad de cafetería, que es venta menos
 * costo y lo despejaría con una resta.
 */
class CostVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const COSTO = 1200.0;

    private function producto(): Product
    {
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Bebidas',
            'sale_price' => 3000, 'cost_price' => self::COSTO,
            'stock' => 10, 'min_stock' => 2, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    /** Quien entra a Inventario pero no puede ver lo que costó. */
    private function sinCostos(): array
    {
        return $this->adminWithPermissions([
            CrmPermission::INVENTORY_VIEW,
            CrmPermission::INVENTORY_EDIT,
            CrmPermission::INVENTORY_CREATE,
        ]);
    }

    /** La misma persona, con la llave del costo. */
    private function conCostos(): array
    {
        return $this->adminWithPermissions([
            CrmPermission::INVENTORY_VIEW,
            CrmPermission::INVENTORY_EDIT,
            CrmPermission::INVENTORY_CREATE,
            CrmPermission::INVENTORY_COST,
        ]);
    }

    // ── La ficha del producto ───────────────────────────────────────────────

    public function test_el_catalogo_trae_el_costo_a_quien_puede_verlo(): void
    {
        $this->producto();

        $fila = $this->getJson('/api/admin/products', $this->conCostos())
            ->assertOk()->json('data.0');

        $this->assertEquals(self::COSTO, $fila['cost_price']);
    }

    public function test_el_catalogo_no_trae_el_costo_a_quien_no_puede_verlo(): void
    {
        $this->producto();

        $fila = $this->getJson('/api/admin/products', $this->sinCostos())
            ->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('cost_price', $fila);

        // Y lo demás sigue entero: sin precio de venta ni existencias el
        // mostrador no podría trabajar, que es lo contrario de lo que se busca.
        $this->assertEquals(3000, $fila['sale_price']);
        $this->assertSame(10, $fila['stock']);
        $this->assertSame('ok', $fila['stock_status']);
    }

    public function test_el_catalogo_filtrado_por_estado_tampoco_lo_trae(): void
    {
        // Esta rama devuelve los productos por otro camino que el listado
        // normal. Es exactamente donde se olvida un recorte.
        $this->producto();

        $fila = $this->getJson('/api/admin/products?status=ok', $this->sinCostos())
            ->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('cost_price', $fila);
        $this->assertEquals(3000, $fila['sale_price']);
    }

    public function test_la_ficha_de_un_producto_tampoco_lo_trae(): void
    {
        $p = $this->producto();

        $this->assertArrayNotHasKey(
            'cost_price',
            $this->getJson("/api/admin/products/{$p->id}", $this->sinCostos())->assertOk()->json('data'),
        );
    }

    // ── El valor del inventario a costo ─────────────────────────────────────

    public function test_el_valor_del_inventario_a_costo_es_el_costo_sumado(): void
    {
        $this->producto();

        $con = $this->getJson('/api/admin/products/stats', $this->conCostos())->assertOk()->json();
        $sin = $this->getJson('/api/admin/products/stats', $this->sinCostos())->assertOk()->json();

        $this->assertEquals(12000, $con['inventory_value']);   // 10 × 1200
        $this->assertArrayNotHasKey('inventory_value', $sin);

        // La valorización a precio de VENTA se queda: no dice nada que no esté
        // ya en la ficha. Y el resto del recuento sigue sirviendo.
        $this->assertEquals(30000, $sin['retail_value']);
        $this->assertSame(1, $sin['total']);
    }

    // ── Los movimientos de existencias ──────────────────────────────────────

    public function test_los_movimientos_no_dicen_el_importe_sin_permiso(): void
    {
        $p = $this->producto();
        app(InventoryService::class)->registerEntry(
            product: $p, quantity: 5, origin: InventoryMovementOrigin::PURCHASE,
        );

        $fila = $this->getJson('/api/admin/inventory/movements', $this->sinCostos())
            ->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('unit_amount', $fila);
        $this->assertArrayNotHasKey('total_amount', $fila);

        // La historia del inventario sigue completa: qué entró, cuánto, de
        // dónde venía el saldo y a dónde quedó. Lo único que se fue es el
        // dinero.
        $this->assertSame(5, $fila['quantity']);
        $this->assertSame(10, $fila['stock_before']);
        $this->assertSame(15, $fila['stock_after']);
    }

    public function test_los_movimientos_dicen_el_importe_a_quien_ve_costos(): void
    {
        $p = $this->producto();
        app(InventoryService::class)->registerEntry(
            product: $p, quantity: 5, origin: InventoryMovementOrigin::PURCHASE,
        );

        $fila = $this->getJson('/api/admin/inventory/movements', $this->conCostos())
            ->assertOk()->json('data.0');

        $this->assertEquals(self::COSTO, $fila['unit_amount']);
        $this->assertEquals(6000, $fila['total_amount']);
    }

    public function test_la_historia_de_un_producto_tampoco_dice_el_importe(): void
    {
        $p = $this->producto();
        app(InventoryService::class)->registerEntry(
            product: $p, quantity: 5, origin: InventoryMovementOrigin::PURCHASE,
        );

        $fila = $this->getJson("/api/admin/products/{$p->id}/movements", $this->sinCostos())
            ->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('unit_amount', $fila);
        $this->assertArrayNotHasKey('total_amount', $fila);
    }

    // ── Escribir el costo ───────────────────────────────────────────────────

    public function test_editar_sin_permiso_conserva_el_costo_que_ya_tenia(): void
    {
        // El caso que importa: el formulario de quien no ve el costo no lo
        // manda, pero si llegara de todas formas —un cliente viejo, una llamada
        // a mano— no puede pisar un dato que esa persona no puede leer. Un cero
        // ahí diría que la mercancía no costó nada y falsearía el margen de
        // todas las ventas de ese producto.
        $p = $this->producto();

        $this->putJson("/api/admin/products/{$p->id}", [
            'name' => 'Agua 600 ml (caja)',
            'sale_price' => 3500,
            'cost_price' => 1,
        ], $this->sinCostos())->assertOk();

        $p->refresh();
        $this->assertEquals(self::COSTO, $p->cost_price);

        // Y el resto de la edición sí se aplica: no se rechaza la petición, se
        // ignora el campo.
        $this->assertSame('Agua 600 ml (caja)', $p->name);
        $this->assertEquals(3500, $p->sale_price);
    }

    public function test_editar_con_permiso_si_cambia_el_costo(): void
    {
        $p = $this->producto();

        $this->putJson("/api/admin/products/{$p->id}", [
            'name' => 'Agua 600 ml',
            'sale_price' => 3000,
            'cost_price' => 1500,
        ], $this->conCostos())->assertOk();

        $this->assertEquals(1500, $p->refresh()->cost_price);
    }

    public function test_crear_sin_permiso_deja_el_costo_sin_poner(): void
    {
        // Sin poner, no en cero: no se sabe lo que costó, y eso es más honesto
        // que un número inventado por el formulario.
        $this->postJson('/api/admin/products', [
            'name' => 'Proteína',
            'category' => 'Suplementos',
            'sale_price' => 90000,
            'cost_price' => 45000,
        ], $this->sinCostos())->assertCreated();

        $this->assertNull(Product::where('name', 'Proteína')->sole()->cost_price);
    }

    public function test_una_entrada_sin_permiso_no_escribe_el_costo_enviado(): void
    {
        $p = $this->producto();

        $respuesta = $this->postJson("/api/admin/products/{$p->id}/entry", [
            'quantity' => 3,
            'origin' => InventoryMovementOrigin::PURCHASE->value,
            'unit_amount' => 99999,
        ], $this->sinCostos())->assertCreated();

        // El movimiento hereda el costo que ya tiene el producto, que es el dato
        // bueno. Aceptar el enviado dejaría a quien no ve el costo escribiéndolo.
        $this->assertEquals(self::COSTO, $p->inventoryMovements()->first()->unit_amount);

        // Y la respuesta no se lo devuelve.
        $this->assertArrayNotHasKey('unit_amount', $respuesta->json('movement'));
        $this->assertArrayNotHasKey('cost_price', $respuesta->json('data'));

        // Lo que sí pasó: las existencias subieron. Mover stock no depende de
        // ver costos.
        $this->assertSame(13, $p->refresh()->stock);
    }

    public function test_una_entrada_con_permiso_si_escribe_el_costo_enviado(): void
    {
        $p = $this->producto();

        $this->postJson("/api/admin/products/{$p->id}/entry", [
            'quantity' => 3,
            'origin' => InventoryMovementOrigin::PURCHASE->value,
            'unit_amount' => 1400,
        ], $this->conCostos())->assertCreated();

        $this->assertEquals(1400, $p->inventoryMovements()->first()->unit_amount);
    }

    // ── La utilidad de cafetería ────────────────────────────────────────────

    private function ventaDeCafeteria(): void
    {
        $p = $this->producto();
        $venta = ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-COSTO', 'channel' => 'pos',
            'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 6000, 'total' => 6000, 'paid_at' => '2026-06-10 13:00:00',
        ]);
        ProductSaleItem::create([
            'product_sale_id' => $venta->id, 'product_id' => $p->id,
            'name' => $p->name, 'unit_price' => 3000, 'quantity' => 2, 'subtotal' => 6000,
        ]);
    }

    public function test_la_utilidad_de_cafeteria_solo_la_ve_quien_ve_costos(): void
    {
        $this->ventaDeCafeteria();
        $url = '/api/admin/earnings?from=2026-06-01&to=2026-06-30&group_by=day';

        $con = $this->getJson($url, $this->adminWithPermissions([
            'earnings.view', CrmPermission::INVENTORY_COST,
        ]))->assertOk()->json();
        $sin = $this->getJson($url, $this->adminWithPermissions(['earnings.view']))
            ->assertOk()->json();

        // El cálculo sigue igual de bueno para quien puede verlo: 6000 de venta
        // menos 2400 de costo.
        $this->assertEquals(3600, $con['totals']['cafeteria_profit']);
        $this->assertEquals(3600, $con['series'][0]['cafeteria_profit']);

        $this->assertArrayNotHasKey('cafeteria_profit', $sin['totals']);
        $this->assertArrayNotHasKey('combined_profit', $sin['totals']);
        $this->assertArrayNotHasKey('cafeteria_profit', $sin['series'][0]);

        // Lo que sí ve: cuánto entró. Eso es `earnings.view` y no cambia.
        $this->assertEquals(6000, $sin['totals']['cafeteria_revenue']);
    }

    // ── La política de roles ────────────────────────────────────────────────

    public function test_el_permiso_esta_en_el_catalogo_y_se_puede_repartir(): void
    {
        $this->assertContains(CrmPermission::INVENTORY_COST, CrmPermission::all());
    }

    public function test_el_dueno_lo_conserva_y_el_mostrador_no_lo_tiene(): void
    {
        $this->assertContains(
            CrmPermission::INVENTORY_COST,
            CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN),
        );
        $this->assertNotContains(
            CrmPermission::INVENTORY_COST,
            CrmPermission::defaultsFor(Admin::ROLE_RECEPCION),
        );
    }

    public function test_el_token_compartido_no_ve_costos(): void
    {
        // Falla cerrado: una credencial sin persona detrás solo obtiene lectura
        // `.view`, y esta llave no lo es.
        $this->producto();

        $this->assertArrayNotHasKey(
            'cost_price',
            $this->getJson('/api/admin/products', $this->sharedTokenHeaders())->assertOk()->json('data.0'),
        );
    }
}
