<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AppNotification;
use App\Models\AutomationEvent;
use App\Models\CashShift;
use App\Models\CommercialAlert;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Caja\ReceivableDueNotifier as Notifier;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El dinero de los abonos, y los avisos de vencimiento.
 *
 * EL FALLO QUE ORIGINÓ ESTO. Un plan de 80.000 pagado en dos abonos de 40.000
 * aparecía como 80.000 recaudados desde el PRIMER abono, y el segundo no sumaba
 * nada. La causa no estaba en el arqueo —que siempre estuvo bien— sino en que
 * los KPIs sumaban el asiento de devengo del plan: un `Payment` por el importe
 * completo que representa el contrato, no dinero en el cajón.
 *
 * LA REGLA que se protege aquí: se reconoce el dinero el día que entra, ni
 * antes ni dos veces. Una venta a crédito no es caja; el abono sí lo es.
 */
class CajaDineroYAvisosTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'dinero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        config(['caja.payment_terms.gym' => 15, 'caja.payment_terms.products' => 8]);
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function h(): array
    {
        return $this->actingAsAdmin($this->cajero);
    }

    private function socio(): Member
    {
        $user = User::create([
            'name' => 'Socio', 'email' => 'socio-'.uniqid().'@t.test', 'password' => 'x',
            'plan' => 'Premium', 'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Socio Prueba',
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function turno(CashShiftType $tipo = CashShiftType::GYM): CashShift
    {
        $abierto = CashShift::where('type', $tipo->value)->open()->first();

        return $abierto ?? app(CashShiftService::class)->open($this->cajero, $tipo);
    }

    private function cerrarTurno(CashShift $t): void
    {
        app(CashShiftService::class)->close($this->cajero, $t->type, 0);
    }

    private function totales(CashShift $t): array
    {
        return app(CashShiftTotalsService::class)->for($t->fresh());
    }

    private function kpiPagos(): array
    {
        return $this->getJson('/api/admin/payments/stats', $this->h())->json();
    }

    private function producto(float $precio = 100000, int $stock = 50): Product
    {
        $tasa = TaxRate::firstOrCreate(['code' => 'IVA0'], ['name' => 'IVA 0', 'rate' => 0, 'active' => true]);

        return Product::create([
            'name' => 'Proteína '.uniqid(), 'sku' => 'SKU-'.uniqid(),
            'sale_price' => $precio, 'price' => $precio, 'cost' => $precio / 2,
            'stock' => $stock, 'active' => true,
            'tax_rate_id' => $tasa->id, 'pricing_mode' => 'tax_exclusive',
        ]);
    }

    // ── 1-3 · Gimnasio: el caso de QA ───────────────────────────────────────

    public function test_1_el_primer_abono_de_un_plan_suma_solo_lo_que_entro(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $turno = $this->turno();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertSame('40000.00', $this->totales($turno)['gross_total'], 'arqueo del turno');
        // Lo que fallaba: el KPI daba 80.000 porque sumaba el contrato.
        $this->assertEqualsWithDelta(40000, $this->kpiPagos()['total_amount'], 0.01,
            'El plan completo NO es dinero recibido: solo entraron 40.000.');
    }

    public function test_2_el_abono_final_en_el_mismo_turno_completa_la_caja(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $turno = $this->turno();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $cuenta = Receivable::first();
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertSame('80000.00', $this->totales($turno)['gross_total']);
        $this->assertEqualsWithDelta(80000, $this->kpiPagos()['total_amount'], 0.01);
        $this->assertEqualsWithDelta(80000, $this->kpiPagos()['paid_amount'], 0.01);

        $cuenta->refresh();
        $this->assertEqualsWithDelta(80000, $cuenta->paidAmount()->toFloat(), 0.01);
        $this->assertEqualsWithDelta(0, $cuenta->balance()->toFloat(), 0.01);
    }

    public function test_3_cada_turno_se_queda_con_el_dinero_que_recibio(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $turnoA = $this->turno();
        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);
        $this->cerrarTurno($turnoA);

        $turnoB = $this->turno();
        $cuenta = Receivable::first();
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        // El dinero cuenta el día que entra, no el día que nació la deuda: ése
        // es justamente el sentido de fiar.
        $this->assertSame('40000.00', $this->totales($turnoA)['gross_total'], 'turno A');
        $this->assertSame('40000.00', $this->totales($turnoB)['gross_total'], 'turno B');
    }

    public function test_4_un_abono_anulado_deja_de_ser_dinero(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $turno = $this->turno();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $abono = ReceivablePayment::first();
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", [
            'reason' => 'se tecleó mal',
        ], $this->h())->assertOk();

        $this->assertSame('0.00', $this->totales($turno)['gross_total'], 'salió del cajón');
        $this->assertEqualsWithDelta(0, $this->kpiPagos()['total_amount'], 0.01);
    }

    public function test_5_el_desglose_por_medio_reparte_cada_abono_donde_toca(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $turno = $this->turno();

        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $cuenta = Receivable::first();
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 40000, 'method' => 'transfer',
        ], $this->h())->assertStatus(201);

        $t = $this->totales($turno);
        $this->assertSame('40000.00', $t['cash_total'], 'efectivo');
        $this->assertSame('40000.00', $t['transfer_total'], 'transferencia');
        // Y el cajón solo tiene el efectivo: la transferencia no deja billetes.
        $this->assertSame('40000.00', $t['expected_cash']);
    }

    // ── 6-9 · Productos a crédito ───────────────────────────────────────────

    /** @return array{0: ProductSale, 1: Receivable} */
    private function venderACredito(Member $socio, float $precio = 100000, ?string $vence = null): array
    {
        $producto = $this->producto($precio);
        $this->turno(CashShiftType::PRODUCTS);

        $r = $this->postJson('/api/admin/caja/sales', array_filter([
            'items' => [['product_id' => $producto->id, 'quantity' => 1, 'unit_price' => $precio]],
            'credit' => true,
            'debtor_type' => DebtorType::MEMBER->value,
            'debtor_id' => $socio->id,
            'due_at' => $vence,
        ]), $this->h())->assertStatus(201);

        return [ProductSale::find($r->json('data.id')), Receivable::find($r->json('receivable.id'))];
    }

    public function test_6_una_venta_a_credito_no_es_caja_pero_si_descuenta_stock(): void
    {
        $socio = $this->socio();
        $turno = $this->turno(CashShiftType::PRODUCTS);
        [$venta, $cuenta] = $this->venderACredito($socio);

        $this->assertSame('0.00', $this->totales($turno)['gross_total'], 'el dinero no entró');
        $this->assertEqualsWithDelta(100000, $cuenta->total()->toFloat(), 0.01);
        $this->assertSame(49, $venta->items->first()->product->fresh()->stock, 'el producto salió UNA vez');
    }

    public function test_7_los_abonos_de_un_credito_si_entran_a_la_caja_de_productos(): void
    {
        $socio = $this->socio();
        $turno = $this->turno(CashShiftType::PRODUCTS);
        [, $cuenta] = $this->venderACredito($socio);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 30000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);
        $this->assertSame('30000.00', $this->totales($turno)['gross_total']);
        $this->assertEqualsWithDelta(70000, $cuenta->fresh()->balance()->toFloat(), 0.01);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 70000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);
        $this->assertSame('100000.00', $this->totales($turno)['gross_total']);
        $this->assertEqualsWithDelta(0, $cuenta->fresh()->balance()->toFloat(), 0.01);
    }

    public function test_8_cobrar_un_credito_no_vuelve_a_tocar_el_inventario(): void
    {
        $socio = $this->socio();
        [$venta, $cuenta] = $this->venderACredito($socio);
        $this->turno(CashShiftType::PRODUCTS);

        $stockAntes = $venta->items->first()->product->fresh()->stock;
        $ventasAntes = ProductSale::count();

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 100000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertSame($stockAntes, $venta->items->first()->product->fresh()->stock,
            'Abonar no mueve stock: el producto ya salió al fiarlo.');
        $this->assertSame($ventasAntes, ProductSale::count(), 'ni crea otra venta');
    }

    public function test_9_el_kpi_de_caja_productos_ve_la_cobranza_de_creditos(): void
    {
        $socio = $this->socio();
        [, $cuenta] = $this->venderACredito($socio);
        $this->turno(CashShiftType::PRODUCTS);

        // Antes de cobrar: la venta a crédito no es ingreso.
        $this->assertEqualsWithDelta(0, $this->getJson('/api/admin/caja/stats', $this->h())->json('revenue_today'), 0.01);

        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertEqualsWithDelta(
            40000,
            $this->getJson('/api/admin/caja/stats', $this->h())->json('revenue_today'),
            0.01,
            'Sin esto, toda la cobranza de cafetería era invisible.',
        );
    }

    public function test_9b_la_manana_y_la_noche_del_mismo_dia_suman_juntas(): void
    {
        // El día del gimnasio del 13 de septiembre va de las 05:00 UTC del 13 a
        // las 05:00 UTC del 14. Dentro caben las dos franjas reales del negocio:
        //
        //   08:00 en Neiva  = 13:00 UTC del 13
        //   20:30 en Neiva  = 01:30 UTC del 14   ← ya es «mañana» en UTC
        //
        // Si «hoy» se decide con la fecha UTC, a las siete de la tarde el
        // mostrador ve cómo los ingresos de la mañana desaparecen del KPI.
        $socio = $this->socio();

        $this->travelTo(Carbon::parse('2026-09-13 13:00:00', 'UTC'));
        [, $cuenta] = $this->venderACredito($socio);
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 25000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->travelTo(Carbon::parse('2026-09-14 01:30:00', 'UTC'));
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 15000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertSame('2026-09-13', Receivable::businessToday()->toDateString(), 'preparación');
        $this->assertEqualsWithDelta(
            40000,
            $this->getJson('/api/admin/caja/stats', $this->h())->json('revenue_today'),
            0.01,
            'Los 25.000 de la mañana no pueden evaporarse al anochecer.',
        );
    }

    public function test_10_el_dinero_del_gimnasio_no_se_mezcla_con_el_de_productos(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $gym = $this->turno(CashShiftType::GYM);
        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $prod = $this->turno(CashShiftType::PRODUCTS);
        [, $credito] = $this->venderACredito($socio, 50000);
        $this->postJson("/api/admin/receivables/{$credito->id}/payments", [
            'amount' => 20000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->assertSame('40000.00', $this->totales($gym)['gross_total'], 'gimnasio');
        $this->assertSame('20000.00', $this->totales($prod)['gross_total'], 'productos');
    }

    public function test_11_sin_caja_abierta_no_se_acepta_un_abono(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $turno = $this->turno();
        $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        $this->cerrarTurno($turno);
        $cuenta = Receivable::first();

        // Sin turno no hay dónde registrar el dinero: se rechaza en vez de
        // aceptarlo en silencio y descuadrar el siguiente arqueo.
        $this->postJson("/api/admin/receivables/{$cuenta->id}/payments", [
            'amount' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(409);

        $this->assertEqualsWithDelta(40000, $cuenta->fresh()->balance()->toFloat(), 0.01);
    }

    // ── 12-14 · Fecha límite ────────────────────────────────────────────────

    public function test_12_un_credito_de_productos_nace_con_su_plazo(): void
    {
        $socio = $this->socio();
        [, $cuenta] = $this->venderACredito($socio);

        $this->assertNotNull($cuenta->due_at, 'Sin plazo nunca vencería y nunca avisaría.');
        $this->assertSame(
            Receivable::businessToday()->addDays(8)->toDateString(),
            $cuenta->due_at->toDateString(),
            'el plazo de cafetería son 8 días',
        );
    }

    public function test_12b_y_el_mostrador_puede_pactar_otra_fecha(): void
    {
        $socio = $this->socio();
        $pactada = Receivable::businessToday()->addDays(3)->toDateString();
        [, $cuenta] = $this->venderACredito($socio, vence: $pactada);

        $this->assertSame($pactada, $cuenta->due_at->toDateString());
    }

    public function test_12c_una_fecha_pasada_se_rechaza_tambien_en_productos(): void
    {
        $socio = $this->socio();
        $producto = $this->producto();
        $this->turno(CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $producto->id, 'quantity' => 1, 'unit_price' => 100000]],
            'credit' => true,
            'debtor_type' => DebtorType::MEMBER->value, 'debtor_id' => $socio->id,
            'due_at' => Receivable::businessToday()->subDay()->toDateString(),
        ], $this->h())->assertStatus(422)->assertJsonValidationErrors('due_at');
    }

    public function test_13_gimnasio_y_productos_tienen_plazos_distintos(): void
    {
        $socio = $this->socio();
        $plan = Plan::create(['name' => 'P80', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->turno();

        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id, 'plan_id' => $plan->id,
            'first_payment' => 40000, 'method' => 'cash',
        ], $this->h())->assertStatus(201);

        [, $credito] = $this->venderACredito($socio);

        $this->assertSame(Receivable::businessToday()->addDays(15)->toDateString(), $r->json('data.due_date'));
        $this->assertSame(Receivable::businessToday()->addDays(8)->toDateString(), $credito->due_at->toDateString());
    }

    public function test_14_las_deudas_antiguas_sin_plazo_siguen_sin_vencer(): void
    {
        $socio = $this->socio();
        $this->turno();
        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id,
            type: CashShiftType::GYM, concept: 'Deuda vieja',
            total: Money::fromAmount(50000), actor: $this->cajero,
        );

        $this->assertNull($cuenta->due_at);
        $this->travel(400)->days();
        $this->assertFalse($cuenta->fresh()->isOverdue());
    }

    // ── 15-18 · El ciclo de avisos ──────────────────────────────────────────

    /** Deuda de gimnasio viva con el plazo indicado. */
    private function deudaGym(Member $socio, string $vence, float $total = 100000, float $abonado = 40000): Receivable
    {
        $this->turno();
        $cuenta = app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id,
            type: CashShiftType::GYM, concept: 'Plan a plazos',
            total: Money::fromAmount($total), actor: $this->cajero,
            dueAt: Carbon::parse($vence),
        );
        if ($abonado > 0) {
            app(ReceivableService::class)->settle($cuenta, Money::fromAmount($abonado), 'cash', $this->cajero);
        }

        return $cuenta->fresh();
    }

    private function correrCiclo(): void
    {
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
    }

    private function eventos(string $tipo): int
    {
        return AutomationEvent::where('event_type', $tipo)->count();
    }

    public function test_15_el_dia_antes_se_recuerda_que_vence_manana(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->addDay()->toDateString());

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.due_soon'));
        $this->assertSame(0, $this->eventos('receivable.due_today'));
        $this->assertSame(0, $this->eventos('receivable.overdue'));
        $this->assertDatabaseHas('app_notifications', [
            'member_id' => $socio->id, 'type' => 'receivable_due_soon',
        ]);
    }

    public function test_16_el_dia_del_plazo_se_avisa_que_vence_hoy(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->toDateString());

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.due_today'));
        $this->assertSame(0, $this->eventos('receivable.overdue'), 'el día pactado NO está vencido');
        // Y todavía no hay alerta interna: recordar no es un problema del equipo.
        $this->assertSame(0, CommercialAlert::where('type', Notifier::ALERT_TYPE)->count());
    }

    public function test_17_al_dia_siguiente_vence_y_se_alerta_a_administracion(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.overdue'));
        $this->assertDatabaseHas('app_notifications', [
            'member_id' => $socio->id, 'type' => 'receivable_overdue',
        ]);
        $alerta = CommercialAlert::where('type', Notifier::ALERT_TYPE)->first();
        $this->assertNotNull($alerta, 'administración tiene que enterarse');
        $this->assertSame(CommercialAlert::STATUS_OPEN, $alerta->status);
        $this->assertStringContainsString('60.000', $alerta->summary);
    }

    public function test_18_una_deuda_ya_saldada_no_recuerda_nada(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deudaGym($socio, Receivable::businessToday()->addDay()->toDateString());
        app(ReceivableService::class)->settle($cuenta, Money::fromAmount(60000), 'cash', $this->cajero);

        $this->correrCiclo();

        $this->assertSame(0, $this->eventos('receivable.due_soon'));
        $this->assertSame(0, AppNotification::where('member_id', $socio->id)->count(),
            'Recordarle el plazo a quien ya pagó es la forma rápida de que deje de leer los avisos.');
    }

    public function test_19_el_socio_recibe_el_aviso_en_su_propia_bandeja(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());

        $this->correrCiclo();

        $aviso = AppNotification::where('member_id', $socio->id)->first();
        $this->assertNotNull($aviso);
        $this->assertSame('high', $aviso->priority, 'una retención no es una notificación cualquiera');
        $this->assertStringContainsString('reactiva', $aviso->body, 'y le dice cómo salir');
        $this->assertSame(60000.0, (float) ($aviso->payload_json['balance'] ?? 0));
    }

    public function test_20_un_deudor_interno_no_recibe_aviso_personal_pero_si_alerta(): void
    {
        $recepcion = Admin::create([
            'name' => 'Mostrador', 'email' => 'rec-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => 'Recepción', 'status' => 'active',
        ]);
        $this->turno(CashShiftType::PRODUCTS);
        app(ReceivableService::class)->create(
            debtorType: DebtorType::RECEPTION, debtorId: $recepcion->id,
            type: CashShiftType::PRODUCTS, concept: 'Consumo mostrador',
            total: Money::fromAmount(20000), actor: $this->cajero,
            dueAt: Receivable::businessToday()->subDay(),
        );

        $this->correrCiclo();

        // No tiene app: su deuda se ve en la alerta interna, que es donde miran.
        $this->assertSame(0, AppNotification::count());
        $this->assertSame(1, CommercialAlert::where('type', Notifier::ALERT_TYPE)->count());
    }

    public function test_21_una_deuda_de_productos_vencida_no_retiene_la_membresia(): void
    {
        $socio = $this->socio();
        [, $cuenta] = $this->venderACredito($socio);
        $cuenta->update(['due_at' => Receivable::businessToday()->subDay()->toDateString()]);

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.products_overdue'));
        $this->assertSame(0, $this->eventos('receivable.overdue'), 'no es deuda de gimnasio');
        $this->assertFalse(
            app(\App\Services\Caja\MembershipFinancialStanding::class)->isOverdue($socio),
            'Deber en cafetería no puede quitarle el gimnasio a nadie.',
        );
    }

    public function test_22_pagar_cierra_la_alerta_y_avisa_del_cierre(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());
        $this->correrCiclo();
        $this->assertSame(1, CommercialAlert::where('type', Notifier::ALERT_TYPE)->where('status', 'open')->count());

        app(ReceivableService::class)->settle($cuenta->fresh(), Money::fromAmount(60000), 'cash', $this->cajero);
        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.cleared'));
        $this->assertSame(0, CommercialAlert::where('type', Notifier::ALERT_TYPE)->where('status', 'open')->count(),
            'La alerta deja de tener sentido en cuanto se cobra.');
    }

    public function test_23_el_ciclo_completo_no_repite_ningun_aviso(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());

        $this->correrCiclo();
        $this->correrCiclo();
        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.overdue'));
        $this->assertSame(1, AppNotification::where('member_id', $socio->id)->count(),
            'Tres corridas, un solo aviso al socio.');
        $this->assertSame(1, CommercialAlert::where('type', Notifier::ALERT_TYPE)->count());
    }

    public function test_24_cambiar_la_fecha_pactada_vuelve_a_avisar(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deudaGym($socio, Receivable::businessToday()->addDay()->toDateString());
        $this->correrCiclo();
        $this->assertSame(1, $this->eventos('receivable.due_soon'));

        // Se repacta para dentro de una semana; ese día vuelve a tocar recordar.
        $cuenta->update(['due_at' => Receivable::businessToday()->addDays(7)->toDateString()]);
        $this->travel(6)->days();
        $this->correrCiclo();

        $this->assertSame(2, $this->eventos('receivable.due_soon'), 'otro plazo, otro recordatorio');
    }

    public function test_25_tras_un_reverso_la_nueva_mora_vuelve_a_avisar(): void
    {
        $socio = $this->socio();
        $cuenta = $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());
        $this->correrCiclo();

        $final = app(ReceivableService::class)->settle($cuenta->fresh(), Money::fromAmount(60000), 'cash', $this->cajero);
        $this->correrCiclo();
        $this->assertSame(1, $this->eventos('receivable.cleared'));

        app(ReceivableService::class)->reverse($final, 'error de caja', $this->cajero);
        $this->correrCiclo();

        $this->assertSame(2, $this->eventos('receivable.overdue'), 'vuelve a deber: vuelve a avisarse');
        $this->assertSame(1, CommercialAlert::where('type', Notifier::ALERT_TYPE)->where('status', 'open')->count(),
            'y la alerta se reabre como una sola, no como dos');
    }

    public function test_26_el_ciclo_no_avisa_de_una_deuda_sin_plazo(): void
    {
        $socio = $this->socio();
        $this->deudaGym($socio, Receivable::businessToday()->subDay()->toDateString());
        Receivable::query()->update(['due_at' => null]);

        $this->correrCiclo();

        $this->assertSame(0, AutomationEvent::where('event_type', 'like', 'receivable.%')->count());
        $this->assertSame(0, AppNotification::count());
    }

    public function test_27_el_ciclo_se_juzga_con_el_dia_de_colombia(): void
    {
        // 04:30 UTC del 16 son las 23:30 del 15 en Bogotá: allí todavía es el
        // día pactado, así que toca «vence hoy», no «vencida».
        $this->travelTo(Carbon::parse('2026-09-16 04:30:00', 'UTC'));
        $socio = $this->socio();
        $this->deudaGym($socio, '2026-09-15');

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.due_today'));
        $this->assertSame(0, $this->eventos('receivable.overdue'));
    }
}
