<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membresías a plazos, estado de cuenta y honestidad del informe de ganancias.
 *
 * EL CASO CENTRAL, que es el que paga las facturas del gimnasio: un plan de
 * 200.000 que el socio empieza pagando 80.000. Lo que no puede pasar, en orden
 * de gravedad: que la caja diga que entraron 200.000, que la membresía se
 * extienda una vez por plazo, que un doble clic abra dos deudas, o que el
 * dinero del gimnasio acabe en el arqueo de la cafetería.
 *
 * `ReceivableTest` cubre el motor. Esto cubre lo que se construyó encima.
 */
class MembershipInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'plazos-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function member(string $nombre = 'Alejandro Casas'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $nombre,
            'document_number' => (string) random_int(10000000, 99999999),
            'is_staff' => false,
        ]);
    }

    private function plan(float $precio = 200000): Plan
    {
        return Plan::create([
            'name' => 'Premium', 'price' => $precio,
            'duration_days' => 30, 'active' => true,
        ]);
    }

    private function openShift(CashShiftType $type, float $apertura = 0): CashShift
    {
        $turno = app(CashShiftService::class)->open($this->cajero, $type);
        $turno->opening_amount = $apertura;
        $turno->save();

        return $turno;
    }

    private function headers(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    /** Vende el plan a plazos y devuelve [respuesta, socio, plan]. */
    private function venderAPlazos(float $primero = 80000, ?array $extra = null): array
    {
        $plan = $this->plan();
        $socio = $this->member();
        $this->openShift(CashShiftType::GYM);

        $r = $this->postJson('/api/admin/receivables/plan', array_merge([
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'first_payment' => $primero,
            'method' => 'cash',
        ], $extra ?? []), $this->headers());

        return [$r, $socio, $plan];
    }

    // ── 1 · El caso central ─────────────────────────────────────────────────

    /**
     * Plan 200.000, paga 80.000. Un Payment, una deuda, una activación,
     * 80.000 en la caja del gimnasio y 120.000 de saldo.
     */
    public function test_el_caso_central_deja_ochenta_mil_en_caja_y_ciento_veinte_de_saldo(): void
    {
        $plan = $this->plan();
        $socio = $this->member();
        $turno = $this->openShift(CashShiftType::GYM, apertura: 100000);

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(200000, $r->json('data.total_amount'));
        $this->assertEquals(80000, $r->json('data.paid_amount'));
        $this->assertEquals(120000, $r->json('data.balance'));
        $this->assertSame('gym', $r->json('data.type'));

        $this->assertSame(1, Payment::count(), 'un solo Payment');
        $this->assertSame(1, Receivable::count(), 'una sola deuda');
        $this->assertSame(1, ReceivablePayment::count(), 'un solo abono');
        $this->assertNotNull(User::find($socio->user_id)->membership_end_date);

        // El arqueo cuenta 80.000, no 200.000.
        $t = $turno->fresh()->computeTotals();
        $this->assertSame('80000.00', $t['cash_total']);
        $this->assertSame('180000.00', $t['expected_cash'], 'fondo 100.000 + 80.000');
    }

    /** Abono de 50.000: saldo 70.000 y la membresía intacta. */
    public function test_el_abono_de_cincuenta_no_vuelve_a_extender_la_membresia(): void
    {
        [$r, $socio] = $this->venderAPlazos();
        $id = $r->json('data.id');
        $fin = User::find($socio->user_id)->membership_end_date;

        $r2 = $this->postJson("/api/admin/receivables/{$id}/payments", [
            'amount' => 50000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(130000, $r2->json('receivable.paid_amount'));
        $this->assertEquals(70000, $r2->json('receivable.balance'));
        $this->assertSame($fin, User::find($socio->user_id)->membership_end_date);
    }

    /** Abono final de 70.000: saldo 0, estado `paid`, sin membresía nueva. */
    public function test_el_abono_final_liquida_sin_crear_otra_membresia(): void
    {
        [$r, $socio] = $this->venderAPlazos();
        $id = $r->json('data.id');
        $fin = User::find($socio->user_id)->membership_end_date;

        $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 50000, 'method' => 'cash'], $this->headers());
        $r3 = $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 70000, 'method' => 'cash'], $this->headers())
            ->assertStatus(201);

        $this->assertEquals(0, $r3->json('receivable.balance'));
        $this->assertSame(Receivable::STATUS_PAID, $r3->json('receivable.status'));
        $this->assertSame(1, Payment::count(), 'no nació otro plan');
        $this->assertSame($fin, User::find($socio->user_id)->membership_end_date);
        $this->assertSame(3, ReceivablePayment::count());
    }

    // ── 2 · Idempotencia del pago inicial ───────────────────────────────────

    /**
     * EL HUECO QUE SE CERRÓ. Antes, repetir la petición frenaba el abono
     * duplicado —el índice único hacía su trabajo— pero el `Payment` y la deuda
     * de la segunda ya estaban escritos: el socio acababa debiendo 400.000.
     */
    public function test_la_misma_venta_a_plazos_dos_veces_deja_una_sola_operacion(): void
    {
        $plan = $this->plan();
        $socio = $this->member();
        $this->openShift(CashShiftType::GYM);

        $cuerpo = [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
            'client_request_id' => 'plan-doble-clic-1',
        ];

        $primera = $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(201);
        $segunda = $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(200);

        $this->assertSame(1, Payment::count(), 'un solo Payment');
        $this->assertSame(1, Receivable::count(), 'una sola deuda');
        $this->assertSame(1, ReceivablePayment::count(), 'un solo abono');

        // La segunda respuesta describe la MISMA operación, no un error.
        $this->assertTrue($segunda->json('ok'));
        $this->assertTrue($segunda->json('replayed'));
        $this->assertSame($primera->json('data.id'), $segunda->json('data.id'));
        $this->assertSame($primera->json('payment_id'), $segunda->json('payment_id'));
        $this->assertEquals(120000, $segunda->json('data.balance'));
    }

    /** Y la membresía se extiende UNA vez, no dos. */
    public function test_la_repeticion_no_extiende_la_membresia_otra_vez(): void
    {
        $plan = $this->plan();
        $socio = $this->member();
        $this->openShift(CashShiftType::GYM);

        $cuerpo = [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
            'client_request_id' => 'plan-doble-clic-2',
        ];

        $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(201);
        $fin = User::find($socio->user_id)->membership_end_date;

        $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(200);

        $this->assertSame($fin, User::find($socio->user_id)->membership_end_date);
    }

    /** Sin `client_request_id` nada cambia: dos ventas son dos ventas. */
    public function test_sin_request_id_se_conserva_el_comportamiento_de_siempre(): void
    {
        $plan = $this->plan();
        $socio = $this->member();
        $this->openShift(CashShiftType::GYM);

        $cuerpo = [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 80000, 'method' => 'cash',
        ];

        $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(201);
        $this->postJson('/api/admin/receivables/plan', $cuerpo, $this->headers())->assertStatus(201);

        $this->assertSame(2, Receivable::count());
    }

    // ── 3 · Estado de cuenta unificado ──────────────────────────────────────

    /** Una persona, dos cajas: el desglose las separa y el total las suma. */
    public function test_el_estado_de_cuenta_separa_productos_de_membresias(): void
    {
        [$r, $socio] = $this->venderAPlazos();   // gimnasio: 120.000 de saldo

        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: CashShiftType::PRODUCTS,
            concept: 'Consumos de cafetería',
            total: Money::fromAmount(15000),
            actor: $this->cajero,
        );

        $cuenta = $this->getJson("/api/admin/receivables/account/{$socio->id}", $this->headers())
            ->assertStatus(200);

        $this->assertSame($socio->id, $cuenta->json('data.member.id'));
        $this->assertEquals(15000, $cuenta->json('data.summary.products_outstanding'));
        $this->assertEquals(120000, $cuenta->json('data.summary.memberships_outstanding'));
        $this->assertEquals(135000, $cuenta->json('data.summary.total_outstanding'));
        $this->assertSame(2, $cuenta->json('data.summary.outstanding_count'));
        $this->assertCount(2, $cuenta->json('data.obligations'));

        // Cada obligación conserva su caja y su origen.
        $cajas = collect($cuenta->json('data.obligations'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['gym', 'products'], $cajas);
        $this->assertNotNull($r->json('data.id'));
    }

    /** Liquidar la deuda de membresía deja el total en lo que aún se debe. */
    public function test_al_liquidar_la_membresia_el_total_solo_deja_lo_de_productos(): void
    {
        [$r, $socio] = $this->venderAPlazos();
        $id = $r->json('data.id');

        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id,
            type: CashShiftType::PRODUCTS, concept: 'Agua',
            total: Money::fromAmount(15000), actor: $this->cajero,
        );

        $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 120000, 'method' => 'cash'], $this->headers())
            ->assertStatus(201);

        $cuenta = $this->getJson("/api/admin/receivables/account/{$socio->id}", $this->headers());

        $this->assertEquals(0, $cuenta->json('data.summary.memberships_outstanding'));
        $this->assertEquals(15000, $cuenta->json('data.summary.total_outstanding'));
        // La deuda liquidada sigue en el historial: es un estado de CUENTA.
        $this->assertCount(2, $cuenta->json('data.obligations'));
    }

    /** El estado de cuenta es información financiera: exige su permiso. */
    public function test_el_estado_de_cuenta_exige_permiso(): void
    {
        $socio = $this->member();
        $mirón = Admin::create([
            'name' => 'Entrenador', 'email' => 'sin-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => CrmPermission::ROLE_ENTRENADOR, 'status' => 'active',
        ]);

        $this->getJson("/api/admin/receivables/account/{$socio->id}", $this->actingAsAdmin($mirón))
            ->assertStatus(403);
    }

    // ── 4 · Ganancias: lo recibido, no lo facturado ─────────────────────────

    /**
     * LA PANTALLA NO PUEDE MENTIR. Su propio contrato dice «estados que cuentan
     * como dinero recibido»: el día de la venta entraron 80.000.
     */
    public function test_ganancias_cuenta_lo_recibido_y_no_el_plan_entero(): void
    {
        $this->venderAPlazos();

        $g = $this->getJson('/api/admin/earnings', $this->headers())->assertStatus(200);

        $this->assertEquals(80000, $g->json('totals.gym_revenue'));
        $this->assertEquals(80000, $g->json('totals.combined_revenue'));
    }

    /** Y el abono suma el día que entra, no el día de la deuda. */
    public function test_ganancias_suma_el_abono_cuando_entra(): void
    {
        [$r] = $this->venderAPlazos();
        $id = $r->json('data.id');

        $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 50000, 'method' => 'card'], $this->headers())
            ->assertStatus(201);

        $g = $this->getJson('/api/admin/earnings', $this->headers());
        $this->assertEquals(130000, $g->json('totals.gym_revenue'), '80.000 + 50.000');

        // Y por su propio medio, no por el del primer pago.
        $porMedio = collect($g->json('by_method'))->where('source', 'gym')->pluck('amount', 'method');
        $this->assertEquals(80000, $porMedio['cash']);
        $this->assertEquals(50000, $porMedio['card']);
    }

    /** Anular el abono lo saca del informe, igual que lo saca del arqueo. */
    public function test_ganancias_deja_de_contar_un_abono_anulado(): void
    {
        [$r] = $this->venderAPlazos();
        $id = $r->json('data.id');

        $abono = $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 50000, 'method' => 'cash'], $this->headers())
            ->json('data.id');

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'se tecleó de más'], $this->headers())
            ->assertStatus(200);

        $this->assertEquals(80000, $this->getJson('/api/admin/earnings', $this->headers())->json('totals.gym_revenue'));
    }

    /** REGRESIÓN: un cobro normal de membresía sigue contando entero. */
    public function test_ganancias_no_cambia_para_un_cobro_normal(): void
    {
        $plan = $this->plan(150000);
        $socio = $this->member();
        $this->openShift(CashShiftType::GYM);

        $this->postJson('/api/payments', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'amount' => 150000, 'method' => 'cash', 'status' => 'paid',
        ], $this->headers())->assertSuccessful();

        $this->assertEquals(150000, $this->getJson('/api/admin/earnings', $this->headers())->json('totals.gym_revenue'));
    }

    /** REGRESIÓN: el abono de un fiado de productos también es dinero real. */
    public function test_ganancias_suma_el_abono_de_un_fiado_de_productos(): void
    {
        $socio = $this->member();
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );
        $agua = Product::create([
            'name' => 'Agua', 'category' => 'Bebidas',
            'sale_price' => 15000, 'cost_price' => 7500,
            'stock' => 10, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
        $this->openShift(CashShiftType::PRODUCTS);

        $venta = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $socio->id,
        ], $this->headers())->assertStatus(201);

        // Fiado: todavía no entró dinero.
        $this->assertEquals(0, $this->getJson('/api/admin/earnings', $this->headers())->json('totals.cafeteria_revenue'));

        $cuenta = $venta->json('receivable.id');
        $this->postJson("/api/admin/receivables/{$cuenta}/payments", ['amount' => 15000, 'method' => 'cash'], $this->headers())
            ->assertStatus(201);

        $this->assertEquals(15000, $this->getJson('/api/admin/earnings', $this->headers())->json('totals.cafeteria_revenue'));
    }

    // ── 5 · Auditoría financiera ────────────────────────────────────────────

    /** Abrir una deuda deja traza de quién y por cuánto. */
    public function test_la_auditoria_registra_la_apertura_de_la_deuda(): void
    {
        $this->venderAPlazos();

        $traza = AuditLog::where('entity', 'cuenta por cobrar')->first();

        $this->assertNotNull($traza, 'abrir una deuda tiene que dejar traza');
        $this->assertSame('create', $traza->action);
        $this->assertSame('Caja', $traza->module);
        $this->assertSame($this->cajero->name, $traza->actor_name);
        $this->assertSame('gym', $traza->metadata['cash']);
        $this->assertSame('200000.00', $traza->metadata['total']);
    }

    /** El abono deja el saldo de antes y el de después: es el estado de cuenta. */
    public function test_la_auditoria_del_abono_guarda_los_dos_saldos(): void
    {
        [$r] = $this->venderAPlazos();
        $this->postJson("/api/admin/receivables/{$r->json('data.id')}/payments",
            ['amount' => 50000, 'method' => 'card'], $this->headers())->assertStatus(201);

        $traza = AuditLog::where('entity', 'abono')->latest('id')->first();

        $this->assertNotNull($traza);
        $this->assertSame('50000.00', $traza->metadata['amount']);
        $this->assertSame('card', $traza->metadata['method']);
        $this->assertSame('120000.00', $traza->metadata['balance_before']);
        $this->assertSame('70000.00', $traza->metadata['balance_after']);
        $this->assertSame('gym', $traza->metadata['cash']);
        $this->assertFalse($traza->metadata['settled']);
    }

    /** Anular deja traza con motivo, y dice que es un cambio de estado. */
    public function test_la_auditoria_registra_la_anulacion_con_su_motivo(): void
    {
        [$r] = $this->venderAPlazos();
        $abono = $this->postJson("/api/admin/receivables/{$r->json('data.id')}/payments",
            ['amount' => 50000, 'method' => 'cash'], $this->headers())->json('data.id');

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse",
            ['reason' => 'se tecleó 50.000 donde eran 5.000'], $this->headers())->assertStatus(200);

        $traza = AuditLog::where('entity', 'abono')->where('action', 'status')->first();

        $this->assertNotNull($traza, 'anular un abono tiene que dejar traza');
        $this->assertSame('se tecleó 50.000 donde eran 5.000', $traza->metadata['reason']);
        $this->assertSame('applied', $traza->changes[0]['before']);
        $this->assertSame('reversed', $traza->changes[0]['after']);
    }

    /** Se puede reconstruir la historia completa de la deuda desde la traza. */
    public function test_la_traza_permite_reconstruir_la_deuda_entera(): void
    {
        [$r] = $this->venderAPlazos();
        $id = $r->json('data.id');
        $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 50000, 'method' => 'cash'], $this->headers());
        $this->postJson("/api/admin/receivables/{$id}/payments", ['amount' => 70000, 'method' => 'cash'], $this->headers());

        $abonos = AuditLog::where('entity', 'abono')->orderBy('id')->get();

        $this->assertCount(3, $abonos);
        $this->assertSame(['120000.00', '70000.00', '0.00'],
            $abonos->pluck('metadata.balance_after')->all());
        $this->assertTrue($abonos->last()->metadata['settled'], 'el último liquida');
    }

    // ── 6 · Concurrencia que cierra exacto ──────────────────────────────────

    /**
     * Saldo 70.000. A abona 30.000 y B 40.000 a la vez: el saldo acaba en cero,
     * sin duplicar, sin negativo y sin perder ninguno de los dos.
     */
    public function test_dos_abonos_simultaneos_que_cierran_exacto_no_dejan_negativo(): void
    {
        [$r] = $this->venderAPlazos();
        $cuenta = Receivable::find($r->json('data.id'));
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", ['amount' => 50000, 'method' => 'cash'], $this->headers());

        $svc = app(ReceivableService::class);
        $svc->settle($cuenta, Money::fromAmount(30000), 'cash', $this->cajero);
        $svc->settle($cuenta, Money::fromAmount(40000), 'cash', $this->cajero);

        $fresca = $cuenta->fresh();
        $this->assertSame(200000.0, $fresca->paidAmount()->toFloat());
        $this->assertSame(0.0, $fresca->balance()->toFloat());
        $this->assertSame(Receivable::STATUS_PAID, $fresca->status);
        $this->assertSame(4, ReceivablePayment::count());
    }

    /** Y si el segundo se pasa del saldo restante, se rechaza entero. */
    public function test_el_segundo_cajero_que_se_pasa_del_saldo_es_rechazado(): void
    {
        [$r] = $this->venderAPlazos();
        $cuenta = Receivable::find($r->json('data.id'));

        $svc = app(ReceivableService::class);
        $svc->settle($cuenta, Money::fromAmount(100000), 'cash', $this->cajero);

        $this->expectException(\App\Exceptions\ReceivableException::class);
        try {
            $svc->settle($cuenta, Money::fromAmount(100000), 'cash', $this->cajero);
        } finally {
            $this->assertSame(20000.0, $cuenta->fresh()->balance()->toFloat());
        }
    }
}
