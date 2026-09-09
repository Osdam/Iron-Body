<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
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
 * Cuentas por cobrar: créditos y abonos.
 *
 * LO QUE SE VIGILA
 * ----------------
 * Dinero. Un fallo aquí no es una pantalla fea: es una caja que no cuadra al
 * cerrar, un producto que salió del almacén y nadie pagó, o un socio al que se
 * le cobra dos veces. Por eso cada invariante tiene su prueba y ninguna se
 * apoya en que la interfaz «no deje» hacerlo: todo se comprueba llamando a la
 * API como la llamaría alguien con la sesión abierta.
 *
 * EL INVARIANTE, en una línea: TOTAL = PAGADO + SALDO, siempre, y el saldo
 * nunca baja de cero.
 */
class ReceivableTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    private Member $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'caja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        $this->empleado = $this->member('Juan Pérez', staff: true);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function member(string $nombre, bool $staff = false): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);

        return Member::create([
            'full_name' => $nombre,
            'email' => $user->email,
            'document_number' => (string) random_int(10_000_000, 99_999_999),
            'phone' => '30'.random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
            'is_staff' => $staff,
            'user_id' => $user->id,
        ]);
    }

    /** Mismo montaje que el resto de pruebas de caja: precio CON IVA incluido. */
    private function product(string $nombre, float $precio, int $stock = 50): Product
    {
        $iva = TaxRate::firstOrCreate(
            ['code' => 'IVA_19_INCL'],
            ['name' => 'IVA 19% incluido', 'rate' => 19.00, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => $nombre, 'category' => 'Bebidas',
            'sale_price' => $precio, 'cost_price' => $precio / 2,
            'stock' => $stock, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $iva->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    private function openShift(CashShiftType $type, float $apertura = 0): CashShift
    {
        $turno = app(CashShiftService::class)->open($this->cajero, $type);
        $turno->opening_amount = $apertura;
        $turno->save();

        return $turno;
    }

    private function receivable(float $total = 18000, ?Member $quien = null): Receivable
    {
        return app(ReceivableService::class)->create(
            member: $quien ?? $this->empleado,
            type: CashShiftType::PRODUCTS,
            concept: 'Consumos de cafetería',
            total: Money::fromAmount($total),
            actor: $this->cajero,
        );
    }

    private function headers(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    private function adminConRol(string $rol): Admin
    {
        return Admin::create([
            'name' => 'Cuenta '.$rol,
            'email' => 'rol-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    // ── 1-2 · La cuenta y su saldo derivado ─────────────────────────────────

    public function test_crear_una_cuenta_por_cobrar(): void
    {
        $cuenta = $this->receivable(18000);

        $this->assertSame(Receivable::STATUS_PENDING, $cuenta->status);
        $this->assertSame(18000.0, $cuenta->total()->toFloat());
        $this->assertSame(0.0, $cuenta->paidAmount()->toFloat());
        $this->assertSame(18000.0, $cuenta->balance()->toFloat());
    }

    /**
     * El saldo NO está guardado en ninguna columna: se deriva de los abonos.
     * Por eso escribir un abono a mano en la base lo cambia, y por eso no puede
     * quedar desincronizado.
     */
    public function test_el_saldo_se_deriva_de_los_abonos_y_no_se_almacena(): void
    {
        $cuenta = $this->receivable(18000);

        $this->assertArrayNotHasKey('balance', $cuenta->getAttributes());
        $this->assertArrayNotHasKey('paid_amount', $cuenta->getAttributes());

        $this->openShift(CashShiftType::PRODUCTS);
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(5000), 'cash', $this->cajero);

        $cuenta->refresh();
        $this->assertSame(5000.0, $cuenta->paidAmount()->toFloat());
        $this->assertSame(13000.0, $cuenta->balance()->toFloat());
        // TOTAL = PAGADO + SALDO
        $this->assertSame(
            $cuenta->total()->toFloat(),
            $cuenta->paidAmount()->toFloat() + $cuenta->balance()->toFloat(),
        );
    }

    // ── 3-6 · Plan a plazos: activar UNA vez ────────────────────────────────

    /** CASO A del enunciado: plan 140.000, primer abono 70.000. */
    public function test_plan_a_plazos_activa_la_membresia_con_el_primer_pago(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 140000, 'duration_days' => 30, 'active' => true]);
        $socio = $this->member('Socio Plan');
        $this->openShift(CashShiftType::GYM);

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'first_payment' => 70000,
            'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(140000, $r->json('data.total_amount'));
        $this->assertEquals(70000, $r->json('data.paid_amount'));
        $this->assertEquals(70000, $r->json('data.balance'));
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $r->json('data.status'));

        $usuario = User::find($socio->user_id);
        $this->assertSame('active', $usuario->status, 'la membresía debe quedar ACTIVA');
        $this->assertNotNull($usuario->membership_end_date);
    }

    /** CASO B: el segundo abono NO vuelve a extender la membresía. */
    public function test_los_abonos_siguientes_no_extienden_la_membresia(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 140000, 'duration_days' => 30, 'active' => true]);
        $socio = $this->member('Socio Plan');
        $this->openShift(CashShiftType::GYM);

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 70000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $finTrasPrimero = User::find($socio->user_id)->membership_end_date;
        $cuentaId = $r->json('data.id');

        // Segundo abono: 30.000 → saldo 40.000
        $r2 = $this->postJson("/api/admin/receivables/{$cuentaId}/payments", [
            'amount' => 30000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(100000, $r2->json('receivable.paid_amount'));
        $this->assertEquals(40000, $r2->json('receivable.balance'));
        $this->assertSame(
            $finTrasPrimero,
            User::find($socio->user_id)->membership_end_date,
            'un abono NO puede regalar otro mes',
        );

        // Y NO nace otra membresía ni otro plan cobrado.
        $this->assertSame(1, Payment::where('user_id', $socio->user_id)->count());
    }

    public function test_el_abono_final_liquida_la_cuenta(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        $svc->settle($cuenta, Money::fromAmount(10000), 'cash', $this->cajero);
        $ultimo = $svc->settle($cuenta->fresh(), Money::fromAmount(8000), 'cash', $this->cajero);

        $cuenta->refresh();
        $this->assertSame(0.0, $cuenta->balance()->toFloat());
        $this->assertSame(Receivable::STATUS_PAID, $cuenta->status);
        $this->assertSame(0.0, (float) $ultimo->balance_after);
    }

    public function test_una_cuenta_saldada_no_admite_mas_abonos(): void
    {
        $cuenta = $this->receivable(5000);
        $this->openShift(CashShiftType::PRODUCTS);
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(5000), 'cash', $this->cajero);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_settled');
    }

    // ── 7 · Sobrepago ───────────────────────────────────────────────────────

    public function test_un_abono_mayor_que_el_saldo_se_rechaza(): void
    {
        $cuenta = $this->receivable(20000);
        $this->openShift(CashShiftType::PRODUCTS);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 30000, 'method' => 'cash',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'overpayment')
            // El mensaje lleva el saldo REAL: quien está en el mostrador
            // necesita saber cuánto cobrar.
            ->assertJsonPath('message', 'El saldo pendiente es $20.000.');

        $this->assertSame(0, ReceivablePayment::count());
        $this->assertSame(20000.0, $cuenta->fresh()->balance()->toFloat());
    }

    // ── 8 · Idempotencia ────────────────────────────────────────────────────

    /** Doble clic con el MISMO identificador: un solo abono. */
    public function test_dos_peticiones_con_el_mismo_request_id_dejan_un_solo_abono(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::PRODUCTS);

        $cuerpo = ['amount' => 5000, 'method' => 'cash', 'client_request_id' => 'req-doble-clic'];

        $a = $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", $cuerpo, $this->headers())
            ->assertStatus(201);
        $b = $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", $cuerpo, $this->headers())
            ->assertStatus(201);

        $this->assertSame($a->json('data.id'), $b->json('data.id'), 'debe devolver el MISMO abono');
        $this->assertSame(1, ReceivablePayment::count());
        $this->assertSame(13000.0, $cuenta->fresh()->balance()->toFloat());
    }

    /** Sin identificador no hay idempotencia: son dos abonos distintos. */
    public function test_sin_request_id_dos_abonos_son_dos_abonos(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::PRODUCTS);

        $cuerpo = ['amount' => 5000, 'method' => 'cash'];
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", $cuerpo, $this->headers());
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", $cuerpo, $this->headers());

        $this->assertSame(2, ReceivablePayment::count());
        $this->assertSame(8000.0, $cuenta->fresh()->balance()->toFloat());
    }

    // ── 9 · Concurrencia ────────────────────────────────────────────────────

    /**
     * Dos cajeros con la pantalla vieja intentan liquidar el mismo saldo.
     *
     * El segundo NO puede dejar la cuenta en negativo: al bloquear la fila
     * vuelve a leer el saldo real —ya cero— y recibe el rechazo.
     */
    public function test_dos_cajeros_no_pueden_liquidar_el_mismo_saldo_dos_veces(): void
    {
        $cuenta = $this->receivable(50000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        // Cajero A liquida.
        $svc->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        // Cajero B llega con el MISMO objeto que tenía antes de que A cobrara.
        // El servicio no se fía de él: vuelve a leer el saldo con la fila
        // bloqueada, lo encuentra en cero y rechaza. Si confiara en el objeto
        // recibido, la cuenta acabaría en -50.000.
        $this->expectException(\App\Exceptions\ReceivableException::class);
        try {
            $svc->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);
        } finally {
            $this->assertSame(50000.0, $cuenta->fresh()->paidAmount()->toFloat());
            $this->assertSame(0.0, $cuenta->fresh()->balance()->toFloat());
            $this->assertSame(1, ReceivablePayment::count());
        }
    }

    // ── 10-12 · Crédito de producto ─────────────────────────────────────────

    /** CASO C: el empleado se lleva el agua. Inventario baja, dinero no entra. */
    public function test_un_credito_de_producto_descuenta_inventario_una_sola_vez(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $this->openShift(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true,
            'member_id' => $this->empleado->id,
        ], $this->headers())->assertStatus(201);

        $this->assertSame(9, $agua->fresh()->stock, 'el producto salió del almacén');
        $this->assertSame(ProductSale::STATUS_CREDIT, $r->json('data.status'));
        $this->assertEquals(5000, $r->json('receivable.balance'));
        $this->assertSame($this->empleado->id, $r->json('receivable.member_id'));
    }

    /** El crédito NO es dinero: el cierre de hoy no puede contarlo. */
    public function test_un_credito_no_suma_al_efectivo_esperado(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 100000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $this->empleado->id,
        ], $this->headers())->assertStatus(201);

        $t = $turno->fresh()->computeTotals();
        $this->assertSame('0.00', $t['cash_total'], 'no entró efectivo');
        $this->assertSame('100000.00', $t['expected_cash'], 'solo el fondo de apertura');
    }

    /** CASO D: pagar la deuda no vuelve a sacar producto del almacén. */
    public function test_el_abono_de_un_credito_no_toca_el_inventario(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $this->openShift(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $this->empleado->id,
        ], $this->headers())->assertStatus(201);

        $this->assertSame(9, $agua->fresh()->stock);

        $this->postJson("/api/admin/receivables/{$r->json('receivable.id')}/payments", [
            'amount' => 5000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame(9, $agua->fresh()->stock, 'pagar no saca más producto');
        // Y NO nace otra venta.
        $this->assertSame(1, ProductSale::count());
    }

    // ── 13, 16-17 · El arqueo ───────────────────────────────────────────────

    /** El abono SÍ es dinero, y se cuenta en el turno en que se recibe. */
    public function test_un_abono_en_efectivo_suma_al_cierre_del_turno_que_lo_recibe(): void
    {
        $cuenta = $this->receivable(18000);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 50000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 8000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $t = $turno->fresh()->computeTotals();
        $this->assertSame('8000.00', $t['cash_total']);
        $this->assertSame('58000.00', $t['expected_cash'], 'fondo + abono');
        $this->assertSame(1, $t['operations_count']);
    }

    /** Un abono con tarjeta no deja billetes en el cajón. */
    public function test_un_abono_con_tarjeta_no_infla_el_efectivo_esperado(): void
    {
        $cuenta = $this->receivable(18000);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 50000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 8000, 'method' => 'card',
        ], $this->headers())->assertStatus(201);

        $t = $turno->fresh()->computeTotals();
        $this->assertSame('0.00', $t['cash_total']);
        $this->assertSame('8000.00', $t['card_total']);
        $this->assertSame('50000.00', $t['expected_cash']);
    }

    /** Crédito el lunes, abono el viernes: el dinero cuenta el VIERNES. */
    public function test_el_dinero_cuenta_el_dia_que_entra_no_el_dia_de_la_deuda(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);

        // Lunes: se fía y se cierra sin dinero.
        $lunes = $this->openShift(CashShiftType::PRODUCTS, apertura: 0);
        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $this->empleado->id,
        ], $this->headers())->assertStatus(201);
        $totalesLunes = $lunes->fresh()->computeTotals();
        app(CashShiftService::class)->close($this->cajero, CashShiftType::PRODUCTS, null, true);

        $this->assertSame('0.00', $totalesLunes['cash_total'], 'el lunes no entró dinero');

        // Viernes: paga.
        $viernes = $this->openShift(CashShiftType::PRODUCTS, apertura: 0);
        $this->postJson("/api/admin/receivables/{$r->json('receivable.id')}/payments", [
            'amount' => 5000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame('5000.00', $viernes->fresh()->computeTotals()['cash_total']);
    }

    // ── 14-15 · Las dos cajas, independientes ───────────────────────────────

    public function test_un_abono_de_productos_no_toca_la_caja_del_gimnasio(): void
    {
        $cuenta = $this->receivable(18000); // type = PRODUCTS
        $productos = $this->openShift(CashShiftType::PRODUCTS, apertura: 10000);
        $gimnasio = $this->openShift(CashShiftType::GYM, apertura: 20000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 8000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame('8000.00', $productos->fresh()->computeTotals()['cash_total']);
        $this->assertSame('0.00', $gimnasio->fresh()->computeTotals()['cash_total']);
    }

    public function test_un_abono_de_gimnasio_no_toca_la_caja_de_productos(): void
    {
        $cuenta = app(ReceivableService::class)->create(
            member: $this->empleado,
            type: CashShiftType::GYM,
            concept: 'Plan a plazos',
            total: Money::fromAmount(140000),
            actor: $this->cajero,
        );
        $productos = $this->openShift(CashShiftType::PRODUCTS, apertura: 10000);
        $gimnasio = $this->openShift(CashShiftType::GYM, apertura: 20000);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 70000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame('0.00', $productos->fresh()->computeTotals()['cash_total']);
        $this->assertSame('70000.00', $gimnasio->fresh()->computeTotals()['cash_total']);
    }

    /** Sin turno abierto de SU caja, el abono no se registra. */
    public function test_sin_caja_abierta_no_se_puede_abonar(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::GYM); // la que NO corresponde

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 5000, 'method' => 'cash',
        ], $this->headers())->assertStatus(409);

        $this->assertSame(0, ReceivablePayment::count());
    }

    // ── 18 · Historial auditable ────────────────────────────────────────────

    public function test_cada_abono_conserva_su_estado_de_cuenta_y_su_autor(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        $svc->settle($cuenta, Money::fromAmount(5000), 'cash', $this->cajero, reference: 'REC-1');
        $svc->settle($cuenta->fresh(), Money::fromAmount(3000), 'transfer', $this->cajero);

        $historial = $cuenta->payments()->orderBy('id')->get();

        $this->assertSame(18000.0, (float) $historial[0]->balance_before);
        $this->assertSame(13000.0, (float) $historial[0]->balance_after);
        $this->assertSame(13000.0, (float) $historial[1]->balance_before);
        $this->assertSame(10000.0, (float) $historial[1]->balance_after);

        foreach ($historial as $abono) {
            $this->assertSame($this->cajero->id, $abono->created_by);
            $this->assertSame('Ana Recepción', $abono->created_by_name);
            $this->assertNotNull($abono->cash_shift_id);
            $this->assertNotNull($abono->created_at);
        }
        $this->assertSame('REC-1', $historial[0]->reference);
    }

    /** Un abono no se borra: se revierte, y las dos filas siguen visibles. */
    public function test_un_abono_se_revierte_sin_borrarse(): void
    {
        $cuenta = $this->receivable(18000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        $abono = $svc->settle($cuenta, Money::fromAmount(5000), 'cash', $this->cajero);
        $this->assertSame(13000.0, $cuenta->fresh()->balance()->toFloat());

        $svc->reverse($abono, 'Cobrado por error', $this->cajero);

        $this->assertSame(1, ReceivablePayment::count(), 'la fila SIGUE ahí');
        $this->assertSame(ReceivablePayment::STATUS_REVERSED, $abono->fresh()->status);
        $this->assertSame(18000.0, $cuenta->fresh()->balance()->toFloat(), 'la deuda vuelve');
        $this->assertSame(Receivable::STATUS_PENDING, $cuenta->fresh()->status);
    }

    /** Y un abono revertido deja de contar como dinero en el arqueo. */
    public function test_un_abono_revertido_sale_del_arqueo(): void
    {
        $cuenta = $this->receivable(18000);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 0);

        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(5000), 'cash', $this->cajero);
        $this->assertSame('5000.00', $turno->fresh()->computeTotals()['cash_total']);

        app(ReceivableService::class)->reverse($abono, 'Error de digitación', $this->cajero);

        $this->assertSame('0.00', $turno->fresh()->computeTotals()['cash_total']);
    }

    // ── 20 · Lo que ya funcionaba sigue funcionando ─────────────────────────

    /** Una venta de contado normal se comporta exactamente igual que antes. */
    public function test_la_venta_de_contado_no_cambia(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 20000);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertSame(8, $agua->fresh()->stock);
        $t = $turno->fresh()->computeTotals();
        $this->assertSame('10000.00', $t['cash_total']);
        $this->assertSame('30000.00', $t['expected_cash']);
        $this->assertSame(0, Receivable::count(), 'una venta de contado no crea deuda');
    }

    /** Y un cobro de membresía normal tampoco. */
    public function test_el_cobro_normal_de_membresia_no_cambia(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $socio = $this->member('Socio Contado');
        $turno = $this->openShift(CashShiftType::GYM, apertura: 0);

        $this->postJson('/api/payments', [
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            'method' => 'efectivo',
            'status' => 'paid',
        ], $this->headers())->assertStatus(201);

        $this->assertSame('80000.00', $turno->fresh()->computeTotals()['cash_total']);
        $this->assertSame(0, Receivable::count());
    }

    // ── Alcance del crédito ─────────────────────────────────────────────────

    /** Fiar exige decir a quién: una deuda sin deudor no se puede cobrar. */
    public function test_un_credito_sin_socio_se_rechaza(): void
    {
        $agua = $this->product('Agua', 5000);
        $this->openShift(CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('member_id');

        $this->assertSame(0, Receivable::count());
        $this->assertSame(50, $agua->fresh()->stock);
    }

    /**
     * Se puede fiar identificando al socio por su cuenta de la app.
     *
     * El CRM busca personas por `users` en todas sus pantallas; exigirle el
     * `member_id` habría significado un buscador nuevo solo para esto.
     */
    public function test_se_puede_fiar_identificando_al_socio_por_su_usuario(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $this->openShift(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true,
            'user_id' => $this->empleado->user_id,
        ], $this->headers())->assertStatus(201);

        $this->assertSame($this->empleado->id, $r->json('receivable.member_id'));
        $this->assertSame(9, $agua->fresh()->stock);
    }

    /**
     * EL FLUJO COMPLETO del enunciado, de principio a fin, contando cuántas
     * veces se extiende la membresía. Debe ser UNA, y solo una: la del primer
     * pago. Cada abono posterior que la extendiera regalaría un mes.
     */
    public function test_flujo_completo_del_plan_a_plazos_extiende_la_membresia_una_sola_vez(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 140000, 'duration_days' => 30, 'active' => true]);
        $socio = $this->member('Socio Plazos');
        $this->openShift(CashShiftType::GYM);

        // 1er pago: 70.000
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 70000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $id = $r->json('data.id');
        $finTrasActivar = User::find($socio->user_id)->membership_end_date;

        $this->assertEquals(140000, $r->json('data.total_amount'));
        $this->assertEquals(70000, $r->json('data.paid_amount'));
        $this->assertEquals(70000, $r->json('data.balance'));
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $r->json('data.status'));
        $this->assertNotNull($finTrasActivar);

        // 2º abono: 30.000 → 100.000 / 40.000
        $r2 = $this->postJson("/api/admin/receivables/{$id}/payments", [
            'amount' => 30000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(100000, $r2->json('receivable.paid_amount'));
        $this->assertEquals(40000, $r2->json('receivable.balance'));
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $r2->json('receivable.status'));
        $this->assertSame($finTrasActivar, User::find($socio->user_id)->membership_end_date);

        // 3er abono: 40.000 → saldada
        $r3 = $this->postJson("/api/admin/receivables/{$id}/payments", [
            'amount' => 40000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $this->assertEquals(140000, $r3->json('receivable.paid_amount'));
        $this->assertEquals(0, $r3->json('receivable.balance'));
        $this->assertSame(Receivable::STATUS_PAID, $r3->json('receivable.status'));

        // La membresía NO se movió ni una sola vez más.
        $this->assertSame(
            $finTrasActivar,
            User::find($socio->user_id)->membership_end_date,
            'la membresía se extiende UNA vez, con el primer pago',
        );
        // Y sigue habiendo UN solo Payment: no nacieron ventas ni planes nuevos.
        $this->assertSame(1, Payment::where('user_id', $socio->user_id)->count());
        $this->assertSame(3, ReceivablePayment::where('receivable_id', $id)->count());
    }

    /**
     * El turno lo elige el SERVIDOR a partir del tipo de la cuenta. Si el
     * cliente pudiera mandarlo, dirigiría el dinero a la caja que quisiera —o
     * a ninguna— y el arqueo del día dejaría de significar nada.
     */
    public function test_el_cliente_no_puede_elegir_el_turno_donde_entra_el_dinero(): void
    {
        $cuenta = $this->receivable(18000); // PRODUCTS
        $productos = $this->openShift(CashShiftType::PRODUCTS, apertura: 0);
        $gimnasio = $this->openShift(CashShiftType::GYM, apertura: 0);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 5000,
            'method' => 'cash',
            // Intento deliberado de desviar el dinero a la otra caja.
            'cash_shift_id' => $gimnasio->id,
        ], $this->headers())->assertStatus(201);

        $abono = ReceivablePayment::latest('id')->first();
        $this->assertSame($productos->id, $abono->cash_shift_id, 'manda el tipo de la cuenta');
        $this->assertSame('5000.00', $productos->fresh()->computeTotals()['cash_total']);
        $this->assertSame('0.00', $gimnasio->fresh()->computeTotals()['cash_total']);
    }

    /** El dinero se guarda en centavos enteros: nada de acumular en float. */
    public function test_los_importes_no_pierden_centavos(): void
    {
        $cuenta = $this->receivable(0.30);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        // 0.10 + 0.20 en float no da 0.30. En centavos, sí.
        $svc->settle($cuenta, Money::fromAmount(0.10), 'cash', $this->cajero);
        $svc->settle($cuenta->fresh(), Money::fromAmount(0.20), 'cash', $this->cajero);

        $cuenta->refresh();
        $this->assertSame(0.0, $cuenta->balance()->toFloat());
        $this->assertSame(Receivable::STATUS_PAID, $cuenta->status);
    }

    // ── ANULACIÓN AUDITADA ──────────────────────────────────────────────────

    /** Un abono mal tecleado se corrige anulándolo, nunca borrándolo. */
    public function test_supervision_puede_anular_un_abono(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);

        // Recepción cobra 50.000 cuando en realidad recibió 5.000.
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $r = $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Valor digitado incorrectamente. Pago real: $5.000.',
        ], $this->headers())->assertOk();

        $this->assertSame(ReceivablePayment::STATUS_REVERSED, $r->json('data.status'));
        $this->assertEquals(70000, $r->json('receivable.balance'), 'la deuda vuelve entera');
        $this->assertEquals(0, $r->json('receivable.paid_amount'));
    }

    /** Cobrar es del mostrador; deshacer un cobro, de supervisión. */
    public function test_recepcion_puede_abonar_pero_no_anular(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $recepcion = $this->adminConRol(Admin::ROLE_RECEPCION);

        // Abonar sí.
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $this->actingAsAdmin($recepcion))->assertStatus(201);

        // Anular no.
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Me equivoqué al teclear el importe.',
        ], $this->actingAsAdmin($recepcion))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'receivables.manage');

        $this->assertSame(ReceivablePayment::STATUS_APPLIED, $abono->fresh()->status);
    }

    /** El entrenador no toca dinero, ni para cobrarlo ni para deshacerlo. */
    public function test_el_entrenador_no_puede_anular_ni_abonar(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $h = $this->actingAsAdmin($this->adminConRol(CrmPermission::ROLE_ENTRENADOR));

        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Un motivo suficientemente largo.',
        ], $h)->assertStatus(403);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 1000, 'method' => 'cash',
        ], $h)->assertStatus(403);

        $this->getJson('/api/admin/receivables', $h)->assertStatus(403);
    }

    /** Sin motivo la corrección no se puede revisar después. */
    public function test_anular_exige_motivo(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        // Y tampoco vale un motivo de dos letras.
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'ok',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(ReceivablePayment::STATUS_APPLIED, $abono->fresh()->status);
    }

    /** El original permanece, con quién lo anuló, cuándo y por qué. */
    public function test_la_anulacion_conserva_las_dos_operaciones(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero, reference: 'REC-9');

        $motivo = 'Valor digitado incorrectamente. Pago real: $5.000.';
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => $motivo,
        ], $this->headers())->assertOk();

        $fresco = $abono->fresh();

        $this->assertSame(1, ReceivablePayment::count(), 'la fila original SIGUE ahí');
        $this->assertSame(50000.0, (float) $fresco->amount, 'el importe original no se toca');
        $this->assertSame('REC-9', $fresco->reference);
        $this->assertSame($this->cajero->id, $fresco->created_by, 'quién lo cobró');
        $this->assertSame($this->cajero->id, $fresco->reversed_by, 'quién lo anuló');
        $this->assertNotNull($fresco->reversed_at);
        $this->assertSame($motivo, $fresco->reversal_reason);
        // Y el estado de cuenta congelado en su momento se conserva.
        $this->assertSame(70000.0, (float) $fresco->balance_before);
        $this->assertSame(20000.0, (float) $fresco->balance_after);
    }

    /** CASO 7 del enunciado: 140.000 / pagado 100.000 → se anula uno de 30.000. */
    public function test_el_saldo_se_recalcula_al_anular_un_abono_intermedio(): void
    {
        $cuenta = $this->receivable(140000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);

        $svc->settle($cuenta, Money::fromAmount(70000), 'cash', $this->cajero);
        $treinta = $svc->settle($cuenta->fresh(), Money::fromAmount(30000), 'cash', $this->cajero);

        $this->assertSame(100000.0, $cuenta->fresh()->paidAmount()->toFloat());
        $this->assertSame(40000.0, $cuenta->fresh()->balance()->toFloat());

        $r = $this->postJson("/api/admin/receivables/payments/{$treinta->id}/reverse", [
            'reason' => 'El socio no llegó a entregar ese dinero.',
        ], $this->headers())->assertOk();

        $this->assertEquals(70000, $r->json('receivable.paid_amount'));
        $this->assertEquals(70000, $r->json('receivable.balance'));
        $this->assertSame(Receivable::STATUS_PARTIALLY_PAID, $r->json('receivable.status'));
    }

    /** El abono anulado deja de ser dinero en el cajón. */
    public function test_el_arqueo_deja_de_contar_un_abono_anulado(): void
    {
        $cuenta = $this->receivable(70000);
        $turno = $this->openShift(CashShiftType::PRODUCTS, apertura: 0);

        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $antes = $turno->fresh()->computeTotals();
        $this->assertSame('50000.00', $antes['cash_total']);

        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Valor digitado incorrectamente.',
        ], $this->headers())->assertOk();

        $despues = $turno->fresh()->computeTotals();
        $this->assertSame('0.00', $despues['cash_total']);
        $this->assertSame('0.00', $despues['expected_cash']);
        // Por EXCLUSIÓN del abono revertido, no por un segundo asiento ficticio.
        $this->assertSame(1, ReceivablePayment::count());
    }

    /** Anular dos veces no puede restar la deuda dos veces. */
    public function test_un_abono_ya_anulado_no_se_puede_volver_a_anular(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $abono = app(ReceivableService::class)
            ->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Valor digitado incorrectamente.',
        ], $this->headers())->assertOk();

        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'Intento de anular por segunda vez.',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_reversed');

        // El motivo original NO se pisa con el del segundo intento.
        $this->assertSame(
            'Valor digitado incorrectamente.',
            $abono->fresh()->reversal_reason,
        );
        $this->assertSame(70000.0, $cuenta->fresh()->balance()->toFloat());
    }

    /** Dos supervisores anulando a la vez: solo uno aplica. */
    public function test_dos_anulaciones_simultaneas_solo_aplican_una(): void
    {
        $cuenta = $this->receivable(70000);
        $this->openShift(CashShiftType::PRODUCTS);
        $svc = app(ReceivableService::class);
        $abono = $svc->settle($cuenta, Money::fromAmount(50000), 'cash', $this->cajero);

        $svc->reverse($abono, 'Primer supervisor.', $this->cajero);

        // El segundo llega con el MISMO objeto en memoria. El servicio relee
        // bajo bloqueo y rechaza; el saldo no se mueve dos veces.
        $this->expectException(\App\Exceptions\ReceivableException::class);
        try {
            $svc->reverse($abono, 'Segundo supervisor.', $this->cajero);
        } finally {
            $this->assertSame(70000.0, $cuenta->fresh()->balance()->toFloat());
            $this->assertSame(1, ReceivablePayment::count());
        }
    }

    /** Anular un abono no devuelve producto al almacén ni crea otra venta. */
    public function test_anular_no_toca_inventario_ni_crea_ventas(): void
    {
        $agua = $this->product('Agua', 5000, stock: 10);
        $this->openShift(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $this->empleado->id,
        ], $this->headers())->assertStatus(201);

        $this->postJson("/api/admin/receivables/{$r->json('receivable.id')}/payments", [
            'amount' => 5000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $abono = ReceivablePayment::latest('id')->first();
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'El empleado no llegó a pagar.',
        ], $this->headers())->assertOk();

        $this->assertSame(9, $agua->fresh()->stock, 'el producto sigue fuera del almacén');
        $this->assertSame(1, ProductSale::count(), 'no nace otra venta');
    }

    /**
     * POLÍTICA EXPLÍCITA: anular el primer pago NO da de baja la membresía.
     *
     * Anular un abono corrige un error de caja, no dice que el socio deba dejar
     * de entrenar. Quitarle el acceso en silencio por una corrección contable
     * es un efecto que nadie pidió. Si hay que darlo de baja, es otra decisión.
     */
    public function test_anular_el_primer_pago_no_da_de_baja_la_membresia(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 140000, 'duration_days' => 30, 'active' => true]);
        $socio = $this->member('Socio Plazos');
        $this->openShift(CashShiftType::GYM);

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 70000, 'method' => 'cash',
        ], $this->headers())->assertStatus(201);

        $usuario = User::find($socio->user_id);
        $finTrasActivar = $usuario->membership_end_date;
        $this->assertSame('active', $usuario->status);

        $primero = ReceivablePayment::where('receivable_id', $r->json('data.id'))->firstOrFail();
        $this->postJson("/api/admin/receivables/payments/{$primero->id}/reverse", [
            'reason' => 'El pago se registró en la cuenta equivocada.',
        ], $this->headers())->assertOk();

        $tras = User::find($socio->user_id);
        $this->assertSame('active', $tras->status, 'la membresía NO se cancela sola');
        $this->assertSame($finTrasActivar, $tras->membership_end_date, 'ni se acorta');

        // Y la deuda vuelve entera, que es lo que sí debe pasar.
        $this->assertEquals(140000, $r->json('data.total_amount'));
        $cuenta = Receivable::find($r->json('data.id'));
        $this->assertSame(140000.0, $cuenta->balance()->toFloat());
        $this->assertSame(Receivable::STATUS_PENDING, $cuenta->status);
    }

    /** El motor es genérico: fiar no está limitado al personal. */
    public function test_se_puede_fiar_a_un_socio_que_no_es_del_personal(): void
    {
        $socio = $this->member('Socio Normal', staff: false);
        $agua = $this->product('Agua', 5000, stock: 10);
        $this->openShift(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $agua->id, 'quantity' => 1]],
            'credit' => true, 'member_id' => $socio->id,
        ], $this->headers())->assertStatus(201);

        $this->assertFalse($r->json('receivable.is_staff'));
        $this->assertEquals(5000, $r->json('receivable.balance'));
    }
}
