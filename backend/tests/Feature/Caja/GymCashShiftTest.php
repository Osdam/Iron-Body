<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\PaymentMembershipActivator;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caja del GIMNASIO: los pagos de membresías y planes, separados del dinero de
 * productos.
 *
 * Lo que estos tests fijan, y que es el núcleo del diseño: qué pagos pertenecen
 * a una caja física y cuáles no. Un cobro de mostrador exige turno abierto; uno
 * de pasarela nunca lo lleva, porque ocurre sin nadie presente. Ver
 * App\Support\Caja\PaymentOrigin.
 */
class GymCashShiftTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_ADMINISTRADOR, string $name = 'Ana'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'caja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Socio Prueba',
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
    }

    /** Cobro de mostrador: el endpoint que usa el CRM. */
    private function cobrar(array $headers, User $u, string $method, float $amount = 50000)
    {
        return $this->postJson('/api/payments', [
            'user_id' => $u->id,
            'amount' => $amount,
            'method' => $method,
            'status' => 'paid',
        ], $headers);
    }

    private function abrirGym(array $headers)
    {
        return $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $headers);
    }

    // ── Apertura ────────────────────────────────────────────────────────────

    public function test_abre_la_caja_de_gimnasio_en_cero_y_de_un_clic(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        $this->abrirGym($h)
            ->assertStatus(201)
            ->assertJsonPath('results.gym.result', 'opened')
            ->assertJsonPath('results.gym.shift.type', 'gym')
            ->assertJsonPath('results.gym.shift.opening_amount', 0)
            ->assertJsonPath('results.gym.shift.opening_policy', 'zero');
    }

    public function test_productos_y_gimnasio_pueden_estar_abiertas_a_la_vez(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)->assertStatus(201);
        $this->abrirGym($h)->assertStatus(201);

        $this->assertSame(2, CashShift::query()->open()->count(), 'una de cada tipo');
    }

    public function test_no_se_abren_dos_turnos_de_gimnasio(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);

        $this->abrirGym($this->actingAsAdmin($this->admin(name: 'Beto')))
            ->assertStatus(207)
            ->assertJsonPath('results.gym.result', 'already_open');

        $this->assertSame(1, CashShift::query()->open()->ofType(CashShiftType::GYM)->count());
    }

    // ── Cobro presencial ────────────────────────────────────────────────────

    public function test_el_cobro_presencial_exige_caja_de_gimnasio_abierta(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        $this->cobrar($h, $this->user(), 'efectivo')
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_open_shift');

        $this->assertSame(0, Payment::count(), 'sin caja abierta no se registra el cobro');
    }

    public function test_el_cobro_presencial_queda_atribuido_al_turno(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);
        $turno = CashShift::currentOfType(CashShiftType::GYM);

        $this->cobrar($h, $this->user(), 'efectivo', 50000)->assertStatus(201);

        $this->assertSame($turno->id, Payment::first()->cash_shift_id);
    }

    public function test_el_cliente_no_puede_elegir_el_turno(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);
        $real = CashShift::currentOfType(CashShiftType::GYM);

        // Turno ajeno ya cerrado: si el payload mandara, el cobro se colaría ahí.
        $ajeno = CashShift::create([
            'type' => 'gym', 'status' => 'closed', 'opened_by' => $this->admin()->id,
            'opened_by_name' => 'Otro', 'opened_at' => now()->subDay(), 'opening_amount' => 0,
        ]);

        $this->postJson('/api/payments', [
            'user_id' => $this->user()->id,
            'amount' => 50000,
            'method' => 'efectivo',
            'status' => 'paid',
            'cash_shift_id' => $ajeno->id,
        ], $h)->assertStatus(201);

        $this->assertSame($real->id, Payment::first()->cash_shift_id, 'manda el servidor, no el payload');
    }

    // ── Totales por medio ───────────────────────────────────────────────────

    public function test_el_cierre_desglosa_por_medio_y_solo_el_efectivo_es_esperado(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);

        $this->cobrar($h, $this->user(), 'efectivo', 500000)->assertStatus(201);
        $this->cobrar($h, $this->user(), 'transferencia', 300000)->assertStatus(201);
        $this->cobrar($h, $this->user(), 'datafono', 200000)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();
        $t = $res->json('results.gym.shift');

        $this->assertEqualsWithDelta(1000000.0, $t['gross_total'], 0.001, 'bruto = todo lo cobrado');
        $this->assertEqualsWithDelta(500000.0, $t['cash_total'], 0.001);
        $this->assertEqualsWithDelta(300000.0, $t['transfer_total'], 0.001);
        $this->assertEqualsWithDelta(200000.0, $t['card_total'], 0.001, 'datafono es tarjeta');
        // Solo el efectivo deja billetes en el cajón.
        $this->assertEqualsWithDelta(500000.0, $t['expected_cash'], 0.001);
        $this->assertSame(3, $t['operations_count']);
    }

    public function test_un_pago_cancelado_no_entra_en_el_cierre(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);

        $this->cobrar($h, $this->user(), 'efectivo', 50000)->assertStatus(201);
        $cancelado = $this->cobrar($h, $this->user(), 'efectivo', 90000)->assertStatus(201);
        Payment::whereKey($cancelado->json('id'))->update(['status' => 'cancelled']);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();

        $this->assertEqualsWithDelta(50000.0, $res->json('results.gym.shift.cash_total'), 0.001);
        $this->assertSame(1, $res->json('results.gym.shift.operations_count'));
    }

    // ── Pagos EXTERNOS: no pertenecen a ninguna caja ────────────────────────

    public function test_un_pago_de_pasarela_no_se_asocia_a_ningun_turno(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);   // caja abierta a propósito

        $u = $this->user();
        $tx = PaymentTransaction::create([
            'reference' => 'WOMPI-TEST-1',
            'idempotency_key' => 'idem-'.uniqid(),
            'user_id' => $u->id,
            'amount' => 120000,
            'status' => 'approved',
            'provider' => 'wompi',
            'paid_at' => now(),
        ]);

        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        $pago = Payment::where('reference', 'WOMPI-TEST-1')->first();
        $this->assertNotNull($pago, 'el pago de pasarela sí se registra');
        $this->assertNull($pago->cash_shift_id, 'pero NUNCA entra en una caja física');

        // Y por tanto no contamina el cierre presencial.
        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();
        $this->assertEqualsWithDelta(0.0, $res->json('results.gym.shift.gross_total'), 0.001);
    }

    // ── Histórico importado ─────────────────────────────────────────────────

    public function test_los_pagos_migrados_no_contaminan_un_cierre_nuevo(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $u = $this->user();

        // Un MIGR-* histórico: sin turno, como los 1.484 de producción.
        Payment::create([
            'user_id' => $u->id, 'amount' => 999999, 'method' => 'manual',
            'reference' => 'MIGR-1', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->abrirGym($h)->assertStatus(201);
        $this->cobrar($h, $u, 'efectivo', 50000)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();

        $this->assertEqualsWithDelta(50000.0, $res->json('results.gym.shift.gross_total'), 0.001);
        $this->assertEqualsWithDelta(0.0, $res->json('results.gym.shift.other_total'), 0.001);
    }

    public function test_manual_nunca_se_normaliza_como_efectivo(): void
    {
        // La garantía de fondo: aunque un `manual` cayera dentro de un turno,
        // jamás inflaría el efectivo que alguien tiene que cuadrar a mano.
        $this->assertSame(PaymentMethodKind::OTHER, PaymentMethodKind::normalize('manual'));
        $this->assertNotContains('manual', PaymentMethodKind::selectableAtCounter());
    }

    /**
     * Los nueve valores REALES de `payments.method` en producción, con el conteo
     * que tenían al auditar. Si alguien añade un medio nuevo sin mapearlo, cae
     * en OTHER y este test lo deja por escrito.
     */
    public function test_el_vocabulario_real_de_produccion_mapea_correctamente(): void
    {
        $esperado = [
            'efectivo' => PaymentMethodKind::CASH,        // 4.250 filas
            'cash' => PaymentMethodKind::CASH,            //    20
            'transferencia' => PaymentMethodKind::TRANSFER, // 2.959
            'transfer' => PaymentMethodKind::TRANSFER,    //     8
            'datafono' => PaymentMethodKind::CARD,        //   599
            'card' => PaymentMethodKind::CARD,            //     2
            'wompi' => PaymentMethodKind::WOMPI,          //     2
            'manual' => PaymentMethodKind::OTHER,         // 1.484 (histórico)
            'other' => PaymentMethodKind::OTHER,          //     1
        ];

        foreach ($esperado as $crudo => $canonico) {
            $this->assertSame($canonico, PaymentMethodKind::normalize($crudo), "medio '{$crudo}'");
        }

        // Y el vocabulario de productos, que es otro.
        $this->assertSame(PaymentMethodKind::WOMPI, PaymentMethodKind::normalize('online'));
        $this->assertSame(PaymentMethodKind::TRANSFER, PaymentMethodKind::normalize('nequi'));

        // Desconocido → OTHER, nunca CASH.
        $this->assertSame(PaymentMethodKind::OTHER, PaymentMethodKind::normalize('criptomonedas'));
        $this->assertSame(PaymentMethodKind::OTHER, PaymentMethodKind::normalize(null));
    }

    // ── Inmutabilidad ───────────────────────────────────────────────────────

    public function test_un_turno_cerrado_no_se_cierra_dos_veces(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();

        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)
            ->assertStatus(207)
            ->assertJsonPath('results.gym.result', 'already_closed');
    }

    public function test_los_totales_congelados_no_cambian_si_cambia_un_pago(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->abrirGym($h)->assertStatus(201);
        $this->cobrar($h, $this->user(), 'efectivo', 50000)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();

        $turno = CashShift::latest('id')->first();
        $antes = (float) $turno->cash_sales_total;

        // Alguien corrige el pago DESPUÉS del cierre.
        Payment::first()->update(['amount' => 1]);

        $this->assertEqualsWithDelta($antes, (float) $turno->fresh()->cash_sales_total, 0.001,
            'el arqueo congelado sigue diciendo lo que se cerró');
    }
}
