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

        $this->postJson('/api/admin/caja/shift/open', [], $headers)
            ->assertStatus(201)
            ->assertJsonPath('results.products.result', 'opened')
            ->assertJsonPath('results.products.shift.status', 'open')
            ->assertJsonPath('results.products.shift.opened_by_name', 'Ana')
            // Política `zero`: el turno abre contablemente en 0, sin pedir nada.
            ->assertJsonPath('results.products.shift.opening_amount', 0);

        $shift = CashShift::currentOfType(\App\Enums\CashShiftType::PRODUCTS);
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

        $this->postJson('/api/admin/caja/shift/open', [], $headers)->assertStatus(201);
        $this->sell($headers, $p, 2)->assertStatus(201);   // 2 × 3000 = 6000 en efectivo

        $res = $this->postJson('/api/admin/caja/shift/close', [
            'note' => 'Turno tranquilo',
        ], $headers)->assertOk();

        $shift = $res->json('results.products.shift');
        $this->assertEqualsWithDelta(6000.0, $shift['cash_total'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $shift['gross_total'], 0.001);
        // Apertura 0 + 6.000 en efectivo. Nadie contó billetes, así que el
        // arqueo físico queda pendiente y sin inventar.
        $this->assertEqualsWithDelta(6000.0, $shift['expected_cash'], 0.001);
        $this->assertNull($shift['counted_amount'], 'el cierre cotidiano no cuenta efectivo');
        $this->assertNull($shift['difference'], 'sin conteo no hay diferencia que declarar');
        $this->assertSame('closed', $shift['status']);
        $this->assertStringContainsString('Cierre automático', $shift['auto_observation']);
    }

    public function test_el_arqueo_fisico_es_una_accion_aparte(): void
    {
        $ana = $this->admin(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $headers = $this->actingAsAdmin($ana);
        $p = $this->product();

        $this->postJson('/api/admin/caja/shift/open', [], $headers)->assertStatus(201);
        $this->sell($headers, $p, 2)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', [], $headers)->assertOk();

        $shift = CashShift::latest('id')->first();

        // Se contaron 1.000 de menos: la diferencia queda con signo.
        $res = $this->postJson("/api/admin/caja/shifts/{$shift->id}/difference", [
            'counted_amount' => 5000,
            'reason' => 'Arqueo de cierre de jornada',
        ], $headers)->assertOk();

        $this->assertEqualsWithDelta(5000.0, $res->json('data.counted_amount'), 0.001);
        $this->assertEqualsWithDelta(-1000.0, $res->json('data.difference'), 0.001, 'negativo = falta dinero');
        // El esperado NO se recalcula: es el que se congeló al cerrar.
        $this->assertEqualsWithDelta(6000.0, $res->json('data.expected_cash'), 0.001);
    }

    public function test_el_arqueo_solo_cuenta_el_efectivo(): void
    {
        // Una venta con tarjeta no pone dinero en el cajón: incluirla haría que
        // la caja «faltara» siempre.
        $headers = $this->actingAsAdmin($this->admin());
        $p = $this->product();

        $this->postJson('/api/admin/caja/shift/open', [], $headers)->assertStatus(201);
        $this->sell($headers, $p, 1)->assertStatus(201);
        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'card', 'paid' => true,
        ], $headers)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', [], $headers)->assertOk();
        $shift = $res->json('results.products.shift');

        $this->assertEqualsWithDelta(6000.0, $shift['gross_total'], 0.001, 'total cobrado incluye la tarjeta');
        $this->assertEqualsWithDelta(3000.0, $shift['cash_total'], 0.001, 'pero el arqueo solo el efectivo');
        $this->assertEqualsWithDelta(3000.0, $shift['card_total'], 0.001, 'la tarjeta se desglosa aparte');
        $this->assertEqualsWithDelta(3000.0, $shift['expected_cash'], 0.001, 'apertura 0 + solo el efectivo');
    }

    // ── CASO 8, 9 · relevo de empleado ──────────────────────────────────────

    public function test_caso_8_9_otro_empleado_abre_turno_nuevo_y_quedan_separados(): void
    {
        $ana = $this->admin(name: 'Ana');
        $beto = $this->admin(name: 'Beto');
        $p = $this->product();

        $ah = $this->actingAsAdmin($ana);
        $this->postJson('/api/admin/caja/shift/open', [], $ah)->assertStatus(201);
        $this->sell($ah, $p, 1)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', [], $ah)->assertOk();

        $bh = $this->actingAsAdmin($beto);
        $this->postJson('/api/admin/caja/shift/open', [], $bh)->assertStatus(201);
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
        $this->postJson('/api/admin/caja/shift/open', [], $ah)->assertStatus(201);

        $this->postJson('/api/admin/caja/shift/open', [],
            $this->actingAsAdmin($this->admin(name: 'Beto')))
            ->assertStatus(207)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.products.result', 'already_open');

        $this->assertSame(1, CashShift::count());
    }

    // ── CASO 11 · cierre forzado ────────────────────────────────────────────

    public function test_caso_11_recepcion_no_puede_cerrar_el_turno_de_otro(): void
    {
        $ah = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $this->postJson('/api/admin/caja/shift/open', [], $ah)->assertStatus(201);

        // Recepción puede operar la caja, pero no supervisar (cash.products.manage).
        $this->postJson('/api/admin/caja/shift/close', [],
            $this->actingAsAdmin($this->admin(name: 'Beto')))
            ->assertStatus(207)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.products.result', 'error');

        $this->assertTrue(
            CashShift::currentOfType(\App\Enums\CashShiftType::PRODUCTS)->isOpen(),
            'el turno sigue abierto',
        );
    }

    public function test_el_cierre_forzado_exige_motivo_y_queda_marcado(): void
    {
        $ah = $this->actingAsAdmin($this->admin(name: 'Ana'));
        $this->postJson('/api/admin/caja/shift/open', [], $ah)->assertStatus(201);

        $super = $this->actingAsAdmin($this->admin(Admin::ROLE_SUPER_ADMIN, 'Super'));

        // Supervisar no exime de explicarse.
        $this->postJson('/api/admin/caja/shift/close', [], $super)
            ->assertStatus(207)
            ->assertJsonPath('results.products.result', 'error')
            ->assertJsonPath('results.products.message', 'Cerrar el turno de otra persona exige indicar el motivo.');

        $res = $this->postJson('/api/admin/caja/shift/close', [
            'forced_reason' => 'La cajera se fue sin cerrar el turno',
        ], $super)->assertOk();

        $this->assertTrue($res->json('results.products.shift.forced'));
        $this->assertSame('Super', $res->json('results.products.shift.closed_by_name'));
    }

    // ── CASO 10 · permisos ──────────────────────────────────────────────────

    public function test_caso_10_sin_caja_sell_no_se_abre_turno(): void
    {
        $this->postJson('/api/admin/caja/shift/open', [],
            $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            // Administrativo no tiene NINGÚN permiso de caja. El 403 lo da
            // ahora el controlador, no una puerta fija en la ruta, y por eso
            // nombra el permiso que de verdad hace falta para abrir —`operate`—
            // en vez de `view`, que era el que la ruta pedía de más.
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.operate');

        $this->assertSame(0, CashShift::count());
    }

    public function test_quien_solo_ve_la_caja_no_puede_abrirla(): void
    {
        // Ver una caja no es operarla. Pedir abrir SOLO esa caja sin su
        // `operate` se rechaza de plano: no hay nada que se pudiera intentar,
        // así que un 207 con una única negativa sería un resultado parcial
        // inventado. El 207 sigue vivo para la operación DOBLE, donde una caja
        // sí se opera y la otra no: {@see DualCashShiftTest}.
        $mirón = $this->admin(Admin::ROLE_ADMINISTRATIVO, 'Mirón');
        \App\Models\RolePermission::create([
            'role' => Admin::ROLE_ADMINISTRATIVO,
            'permission' => 'cash.products.view',
            'granted' => true,
        ]);
        app(\App\Support\Access\RolePermissionPolicy::class)->flush();

        $this->postJson('/api/admin/caja/shift/open', [], $this->actingAsAdmin($mirón))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.operate');

        // Lo que importa no ha cambiado: no se abrió ningún turno.
        $this->assertSame(0, CashShift::count());
    }

    public function test_el_token_compartido_no_abre_caja(): void
    {
        // Un turno necesita un responsable con nombre; un secreto estático no lo es.
        config(['admin.api_token' => 'token-de-automatizacion']);

        $this->postJson('/api/admin/caja/shift/open', [],
            ['Authorization' => 'Bearer token-de-automatizacion'])
            ->assertStatus(403);
    }

    // ── Histórico ───────────────────────────────────────────────────────────

    public function test_el_historico_no_se_borra_y_se_pagina(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $h = $this->actingAsAdmin($this->admin(name: "Emp{$i}"));
            $this->postJson('/api/admin/caja/shift/open', [], $h)->assertStatus(201);
            $this->postJson('/api/admin/caja/shift/close', [], $h)->assertOk();
        }

        $res = $this->getJson('/api/admin/caja/shifts?per_page=2', $this->actingAsAdmin($this->admin()))->assertOk();
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $res->json('meta.last_page'));
    }
}
