<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Services\Billing\SaleReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué puede cobrarse en mostrador, y qué pasa cuando no.
 *
 * El problema que corrige: el CRM listaba cualquier producto activo como
 * vendible y el fallo aparecía al final, en el cobro, con un mensaje sobre
 * tratamiento tributario que recepción ni entiende ni puede resolver. En el
 * catálogo real eran 23 de 31 productos.
 *
 * La regla no se duplica: el estado se deriva del propio PricingService. Estas
 * pruebas fijan las dos mitades — que la UI reciba el estado, y que el backend
 * siga rechazando aunque alguien manipule el navegador.
 */
class SaleReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function rate(): TaxRate
    {
        return TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Producto listo',
            'category' => 'Cafetería',
            'sale_price' => 3000,
            'cost_price' => 1200,
            'stock' => 10,
            'min_stock' => 1,
            'active' => true,
            'visible_in_app' => true,
            'tax_rate_id' => $this->rate()->id,
            'pricing_mode' => 'legacy_inclusive',
        ], $over));
    }

    private function readiness(Product $p): array
    {
        return app(SaleReadiness::class)->for($p);
    }

    // ── El estado que consume la UI ─────────────────────────────────────────

    public function test_un_producto_con_tarifa_y_stock_esta_listo(): void
    {
        $estado = $this->readiness($this->product());

        $this->assertTrue($estado['sale_ready']);
        $this->assertNull($estado['sale_block_reason']);
        $this->assertNull($estado['sale_block_message']);
    }

    public function test_sin_tarifa_queda_bloqueado_con_un_motivo_accionable(): void
    {
        $estado = $this->readiness($this->product(['name' => 'AGUA', 'tax_rate_id' => null]));

        $this->assertFalse($estado['sale_ready']);
        $this->assertSame(SaleReadiness::REASON_TAX, $estado['sale_block_reason']);

        // Lo que ve recepción tiene que decirle qué hacer, no describirle el
        // motor de precios.
        $this->assertStringContainsString('administrador', $estado['sale_block_message']);
        foreach (['tax_rate', 'PricingService', 'legacy_inclusive', 'tarifa de impuesto'] as $jerga) {
            $this->assertStringNotContainsString($jerga, $estado['sale_block_message']);
        }
    }

    public function test_sin_stock_queda_bloqueado_por_otro_motivo(): void
    {
        $estado = $this->readiness($this->product(['stock' => 0]));

        $this->assertFalse($estado['sale_ready']);
        $this->assertSame(SaleReadiness::REASON_STOCK, $estado['sale_block_reason']);
    }

    public function test_la_falta_de_tarifa_pesa_mas_que_la_falta_de_stock(): void
    {
        // Reponer no lo desbloquea: primero hay que configurarlo. Decir
        // «sin unidades» mandaría a recepción a resolver lo que no falla.
        $estado = $this->readiness($this->product(['tax_rate_id' => null, 'stock' => 0]));

        $this->assertSame(SaleReadiness::REASON_TAX, $estado['sale_block_reason']);
    }

    public function test_un_producto_no_facturable_si_se_puede_cobrar(): void
    {
        // Sin tarifa PERO marcado como no facturable: es una decisión tomada,
        // no una configuración a medias.
        $estado = $this->readiness($this->product(['tax_rate_id' => null, 'billing_enabled' => false]));

        $this->assertTrue($estado['sale_ready']);
    }

    public function test_el_catalogo_del_crm_entrega_el_estado(): void
    {
        $this->product(['name' => 'Listo']);
        $this->product(['name' => 'Bloqueado', 'tax_rate_id' => null]);

        $res = $this->getJson('/api/admin/products', $this->adminHeaders())->assertOk();
        $items = collect($res->json('data'))->keyBy('name');

        $this->assertTrue($items['Listo']['sale_ready']);
        $this->assertFalse($items['Bloqueado']['sale_ready']);
        $this->assertSame(SaleReadiness::REASON_TAX, $items['Bloqueado']['sale_block_reason']);
    }

    // ── El backend sigue siendo la autoridad ────────────────────────────────

    public function test_cotizar_un_producto_listo_funciona(): void
    {
        $p = $this->product();

        $this->postJson('/api/admin/billing/quote', [
            'source_type' => 'product', 'source_id' => $p->id, 'quantity' => 1,
        ], $this->adminHeaders())->assertOk();
    }

    public function test_cotizar_uno_bloqueado_devuelve_422(): void
    {
        $p = $this->product(['tax_rate_id' => null]);

        $this->postJson('/api/admin/billing/quote', [
            'source_type' => 'product', 'source_id' => $p->id, 'quantity' => 1,
        ], $this->adminHeaders())->assertStatus(422);
    }

    public function test_vender_un_producto_listo_funciona(): void
    {
        $p = $this->product();
        $h = $this->actingAsAdmin($this->cajero());
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $h)->assertStatus(201);

        $this->assertSame(9, $p->fresh()->stock);
    }

    /**
     * El caso que importa de verdad: la UI ya no ofrece el producto, pero si
     * alguien fuerza la petición —consola del navegador, curl, un cliente
     * antiguo en caché— el servidor tiene que negarse igual.
     */
    public function test_manipular_el_frontend_no_salta_la_validacion(): void
    {
        $p = $this->product(['tax_rate_id' => null]);
        $h = $this->actingAsAdmin($this->cajero());
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $h)->assertStatus(422);

        // Y no dejó nada a medias.
        $this->assertSame(0, ProductSale::count());
        $this->assertSame(10, $p->fresh()->stock, 'el stock no se toca');
        $this->assertSame(0, \App\Models\InventoryMovement::count());
    }

    public function test_una_venta_rechazada_no_mueve_la_caja(): void
    {
        $p = $this->product(['tax_rate_id' => null]);
        $h = $this->actingAsAdmin($this->cajero());
        $turno = $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $h)->assertStatus(422);

        $t = $turno->fresh()->computeTotals();
        $this->assertSame(0, $t['operations_count']);
        $this->assertSame('0.00', $t['gross_total']);
        $this->assertSame('0.00', $t['expected_cash']);
    }

    public function test_una_venta_valida_genera_exactamente_un_movimiento(): void
    {
        $p = $this->product();
        $h = $this->actingAsAdmin($this->cajero());
        $this->openCashShift(null, CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ], $h)->assertStatus(201);

        $this->assertSame(1, \App\Models\InventoryMovement::where('product_id', $p->id)->count());
        $this->assertSame(8, $p->fresh()->stock);
    }

    // ── Quién puede desbloquearlo ───────────────────────────────────────────

    public function test_recepcion_no_puede_asignar_la_tarifa(): void
    {
        $p = $this->product(['tax_rate_id' => null]);

        $this->putJson("/api/admin/billing/products/{$p->id}/tax-rate",
            ['tax_rate_id' => $this->rate()->id],
            $this->actingAsAdmin($this->cajero()))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'billing.manage');

        $this->assertNull($p->fresh()->tax_rate_id, 'la configuración fiscal no cambió');
    }

    public function test_un_administrador_si_puede(): void
    {
        $p = $this->product(['tax_rate_id' => null]);

        $this->putJson("/api/admin/billing/products/{$p->id}/tax-rate",
            ['tax_rate_id' => $this->rate()->id],
            $this->adminAs(Admin::ROLE_ADMINISTRADOR))
            ->assertOk();

        $this->assertSame($this->rate()->id, $p->fresh()->tax_rate_id);
        // Y con eso queda listo para cobrar, sin más pasos.
        $this->assertTrue($this->readiness($p->fresh())['sale_ready']);
    }

    private function cajero(): Admin
    {
        return Admin::create([
            'name' => 'Recepción',
            'email' => 'caj-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);
    }
}
