<?php

namespace Tests\Feature\Caja;

use App\Models\Admin;
use App\Models\CashShift;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Turnos de caja: apertura, atribución, arqueo y cierre.
 *
 * Fija además la corrección de un fallo propio: `$request->user()` es null en
 * las rutas /api/admin/*, así que las ventas se guardaban sin cajero y los
 * movimientos de inventario sin autor. La traza registraba el qué y perdía el
 * quién.
 */
class CashShiftTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_RECEPCION, string $name = 'Ana'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'test-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function product(int $stock = 20): Product
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

    private function sell(array $headers, Product $p, int $qty = 1): TestResponse
    {
        return $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => $qty]],
            'payment_method' => 'cash',
            'paid' => true,
        ], $headers);
    }

    // ── CASO 4 · sin turno abierto no se cobra ──────────────────────────────

    public function test_caso_4_cobrar_sin_caja_abierta_se_rechaza(): void
    {
        $p = $this->product();

        $this->sell($this->actingAsAdmin($this->admin()), $p)
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_open_shift');

        $this->assertSame(0, ProductSale::count(), 'no se crea la venta');
        $this->assertSame(20, $p->fresh()->stock, 'el stock no se toca');
    }

    // ── CASO 1, 2, 3 · abrir, vender, atribuir ──────────────────────────────

    public function test_caso_1_2_3_abre_caja_vende_y_la_venta_queda_atribuida(): void
    {
        $ana = $this->admin(name: 'Ana');
        $headers = $this->actingAsAdmin($ana);
        $p = $this->product();

        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opened_by_name', 'Ana')
            ->assertJsonPath('data.opening_amount', 100000);

        $shift = CashShift::current();
        $this->sell($headers, $p, 2)->assertStatus(201);

        $sale = ProductSale::first();
        $this->assertSame($shift->id, $sale->cash_shift_id, 'la venta pertenece al turno');
        $this->assertSame($ana->id, $sale->cashier_admin_id, 'y tiene cajero');
        $this->assertSame('Ana', $sale->cashier_name);

        // El movimiento de inventario también registra al autor: era null.
        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame($ana->id, $movement->admin_id);
        $this->assertSame('Ana', $movement->user_name);
    }

    // ── CASO 5, 6, 7 · cierre y arqueo ──────────────────────────────────────

    public function test_caso_5_6_7_cierra_con_efectivo_esperado_y_diferencia(): void
    {
        $headers = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $p = $this->product();

        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $headers)->assertStatus(201);
        $this->sell($headers, $p, 2)->assertStatus(201);   // 2 × 3000 = 6000 en efectivo

        // Se cuenta 1.000 de menos: la diferencia debe quedar registrada.
        $res = $this->postJson('/api/admin/caja/shift/close', [
            'counted_amount' => 105000,
            'notes' => 'Falta un billete',
        ], $headers)->assertOk();

        $this->assertEqualsWithDelta(6000.0, $res->json('data.cash_sales_total'), 0.001);
        $this->assertEqualsWithDelta(106000.0, $res->json('data.expected_amount'), 0.001, '100.000 inicial + 6.000 en efectivo');
        $this->assertEqualsWithDelta(105000.0, $res->json('data.counted_amount'), 0.001);
        $this->assertEqualsWithDelta(-1000.0, $res->json('data.difference'), 0.001, 'negativo = falta dinero');
        $this->assertSame('closed', $res->json('data.status'));
    }

    public function test_el_arqueo_solo_cuenta_el_efectivo(): void
    {
        // Una venta con tarjeta no pone dinero en el cajón: incluirla haría que
        // la caja «faltara» siempre.
        $headers = $this->actingAsAdmin($this->admin());
        $p = $this->product();

        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 50000], $headers)->assertStatus(201);
        $this->sell($headers, $p, 1)->assertStatus(201);
        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'card', 'paid' => true,
        ], $headers)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', ['counted_amount' => 53000], $headers)->assertOk();

        $this->assertEqualsWithDelta(6000.0, $res->json('data.sales_total'), 0.001, 'total cobrado incluye la tarjeta');
        $this->assertEqualsWithDelta(3000.0, $res->json('data.cash_sales_total'), 0.001, 'pero el arqueo solo el efectivo');
        $this->assertEqualsWithDelta(53000.0, $res->json('data.expected_amount'), 0.001);
        $this->assertEqualsWithDelta(0.0, $res->json('data.difference'), 0.001, 'cuadra');
    }

    // ── CASO 8, 9 · relevo de empleado ──────────────────────────────────────

    public function test_caso_8_9_otro_empleado_abre_turno_nuevo_y_quedan_separados(): void
    {
        $ana = $this->admin(name: 'Ana');
        $beto = $this->admin(name: 'Beto');
        $p = $this->product();

        $ah = $this->actingAsAdmin($ana);
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $ah)->assertStatus(201);
        $this->sell($ah, $p, 1)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', ['counted_amount' => 103000], $ah)->assertOk();

        $bh = $this->actingAsAdmin($beto);
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 20000], $bh)->assertStatus(201);
        $this->sell($bh, $p, 1)->assertStatus(201);

        $this->assertSame(2, CashShift::count());
        $turnos = CashShift::orderBy('id')->get();
        $this->assertSame('Ana', $turnos[0]->opened_by_name);
        $this->assertSame('Beto', $turnos[1]->opened_by_name);

        // Cada venta con su turno: no se hereda el anterior en silencio.
        $ventas = ProductSale::orderBy('id')->get();
        $this->assertSame($turnos[0]->id, $ventas[0]->cash_shift_id);
        $this->assertSame($turnos[1]->id, $ventas[1]->cash_shift_id);
    }

    public function test_no_se_pueden_abrir_dos_turnos_a_la_vez(): void
    {
        $ah = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $ah)->assertStatus(201);

        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 50000],
            $this->actingAsAdmin($this->admin(name: 'Beto')))
            ->assertStatus(409)
            ->assertJsonPath('code', 'shift_already_open');

        $this->assertSame(1, CashShift::count());
    }

    // ── CASO 11 · cierre forzado ────────────────────────────────────────────

    public function test_caso_11_recepcion_no_puede_cerrar_el_turno_de_otro(): void
    {
        $ah = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $ah)->assertStatus(201);

        // Recepción tiene caja.sell pero no caja.manage.
        $this->postJson('/api/admin/caja/shift/close', ['counted_amount' => 100000],
            $this->actingAsAdmin($this->admin(name: 'Beto')))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'caja.manage');

        $this->assertTrue(CashShift::current()->isOpen(), 'el turno sigue abierto');
    }

    public function test_el_cierre_forzado_exige_motivo_y_queda_marcado(): void
    {
        $ah = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000], $ah)->assertStatus(201);

        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Super'));

        $this->postJson('/api/admin/caja/shift/close', ['counted_amount' => 100000], $super)
            ->assertStatus(422)
            ->assertJsonPath('code', 'forced_reason_required');

        $res = $this->postJson('/api/admin/caja/shift/close', [
            'counted_amount' => 100000,
            'forced_reason' => 'La cajera se fue sin cerrar el turno',
        ], $super)->assertOk();

        $this->assertTrue($res->json('data.forced'));
        $this->assertSame('Super', $res->json('data.closed_by_name'));
    }

    // ── CASO 10 · permisos ──────────────────────────────────────────────────

    public function test_caso_10_sin_caja_sell_no_se_abre_turno(): void
    {
        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000],
            $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'caja.sell');
    }

    public function test_el_token_compartido_no_abre_caja(): void
    {
        // Un turno necesita un responsable con nombre; un secreto estático no lo es.
        config(['admin.api_token' => 'token-de-automatizacion']);

        $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 100000],
            ['Authorization' => 'Bearer token-de-automatizacion'])
            ->assertStatus(403);
    }

    // ── Histórico ───────────────────────────────────────────────────────────

    public function test_el_historico_no_se_borra_y_se_pagina(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $h = $this->actingAsAdmin($this->admin(name: "Emp{$i}"));
            $this->postJson('/api/admin/caja/shift/open', ['opening_amount' => 1000 * $i], $h)->assertStatus(201);
            $this->postJson('/api/admin/caja/shift/close', ['counted_amount' => 1000 * $i], $h)->assertOk();
        }

        $res = $this->getJson('/api/admin/caja/shifts?per_page=2', $this->actingAsAdmin($this->admin()))->assertOk();
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $res->json('meta.last_page'));
    }
}
