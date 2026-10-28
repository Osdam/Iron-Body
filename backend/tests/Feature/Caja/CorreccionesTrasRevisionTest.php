<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\ElectronicInvoice;
use App\Models\InvoiceBundle;
use App\Models\InvoiceBundleItem;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\Receivable;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Caja\PaymentCorrections;
use App\Services\Caja\SaleCorrections;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Correcciones administrativas: lo que encontró la revisión de seguridad (M-1,
 * M-2, B-2, B-3, B-4) y las devoluciones en los indicadores e informes.
 * Ver docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §6.
 */
class CorreccionesTrasRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Admin $super;

    private const MOTIVO = 'Corrección registrada en la prueba';

    protected function setUp(): void
    {
        parent::setUp();
        // Día 1: martes 27-10-2026, mediodía en Bogotá (instante UTC).
        Carbon::setTestNow(Carbon::parse('2026-10-27 11:30', 'America/Bogota')->utc());

        $this->super = Admin::create([
            'name' => 'Revisión Seguridad', 'email' => 'revision-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        app(CashShiftService::class)->open($this->super, CashShiftType::GYM);
        app(CashShiftService::class)->open($this->super, CashShiftType::PRODUCTS);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(): array
    {
        return $this->actingAsAdmin($this->super);
    }

    private function socio(): User
    {
        $user = User::create(['name' => 'Socio '.uniqid(), 'email' => 's-'.uniqid().'@ironbody.test', 'password' => 'secret', 'status' => 'active']);
        Member::create(['user_id' => $user->id, 'full_name' => $user->name, 'document_number' => (string) random_int(10000000, 99999999), 'is_staff' => false]);

        return $user;
    }

    private function plan(): Plan
    {
        return Plan::firstOrCreate(['name' => 'Mensual'], ['price' => 80000, 'duration_days' => 30, 'active' => true]);
    }

    private function cobrar(?User $socio = null, array $extra = []): Payment
    {
        $socio ??= $this->socio();

        return Payment::findOrFail($this->postJson('/api/payments', array_merge([
            'user_id' => $socio->id, 'plan_id' => $this->plan()->id, 'amount' => 80000, 'method' => 'cash', 'status' => 'paid',
        ], $extra), $this->h())->assertStatus(201)->json('id'));
    }

    private function producto(float $precio = 5000): Product
    {
        $tarifa = TaxRate::firstOrCreate(['code' => 'EXEMPT'], ['name' => 'Exento', 'rate' => 0, 'active' => true, 'price_includes_tax' => true]);

        return Product::create([
            'name' => 'Batido '.uniqid(), 'category' => 'Cafetería', 'sale_price' => $precio, 'cost_price' => $precio / 2,
            'stock' => 50, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
            'tax_rate_id' => $tarifa->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    private function vender(Product $p, int $cantidad, array $extra = []): ProductSale
    {
        return ProductSale::with('items')->findOrFail($this->postJson('/api/admin/caja/sales', array_merge([
            'items' => [['product_id' => $p->id, 'quantity' => $cantidad]], 'payment_method' => 'cash',
        ], $extra), $this->h())->assertStatus(201)->json('data.id'));
    }

    // ── M-1 · Lo devuelto no cambia de importe ni de medio ──────────────────

    /**
     * Devolver 80.000 en efectivo y después poner el importe a 0 dejaba un
     * faltante sin explicación: la salida seguía restando 80.000 y la entrada
     * ya no sumaba nada.
     */
    public function test_un_cobro_devuelto_no_cambia_de_importe_ni_de_medio(): void
    {
        $cobro = $this->cobrar();
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'Se mudó de ciudad'], $this->h())->assertStatus(201);

        $this->patchJson("/api/payments/{$cobro->id}", ['amount' => 0, 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'already_refunded');
        $this->postJson("/api/payments/{$cobro->id}/method", ['method' => 'transfer', 'reason' => self::MOTIVO], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'already_refunded');
        $p = $this->getJson("/api/payments/{$cobro->id}/correction-preview?action=method", $this->h())->assertOk()->json();
        $this->assertFalse($p['allowed']);
        $this->assertContains('already_refunded', array_column($p['blockers'], 'code'));

        $this->assertEquals(80000, $cobro->fresh()->amount);
        $this->assertSame('cash', $cobro->fresh()->method);
        // La referencia no es dinero: se sigue pudiendo editar.
        $this->patchJson("/api/payments/{$cobro->id}", ['reference' => 'DEV-1'], $this->h())->assertOk();
    }

    // ── M-2 · Anular un abono de un turno cerrado ───────────────────────────

    public function test_anular_un_abono_de_un_turno_cerrado_exige_administrar_la_caja(): void
    {
        $deuda = $this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $this->socio()->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo', 'total_amount' => 50000,
        ], $this->h())->json('data.id');
        $abono = $this->postJson("/api/admin/receivables/{$deuda}/payments", ['amount' => 20000, 'method' => 'cash'], $this->h())->json('data.id');
        app(CashShiftService::class)->close($this->super, CashShiftType::GYM);

        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'El dinero nunca entró'],
            $this->adminWithPermissions(['receivables.view', 'receivables.manage']))
            ->assertStatus(403)->assertJsonPath('code', 'closed_shift_requires_manage');
        $this->postJson("/api/admin/receivables/payments/{$abono}/reverse", ['reason' => 'El dinero nunca entró'],
            $this->adminWithPermissions(['receivables.view', 'receivables.manage', 'cash.gym.manage']))
            ->assertOk();
    }

    // ── B-2 · La llave de idempotencia no sirve para otra operación ─────────

    public function test_la_llave_de_un_cobro_no_devuelve_ni_crea_otro_cobro(): void
    {
        $a = $this->socio();
        $b = $this->socio();
        $cuerpo = ['plan_id' => $this->plan()->id, 'amount' => 80000, 'method' => 'cash', 'status' => 'paid', 'client_request_id' => 'llave-1'];
        $this->postJson('/api/payments', $cuerpo + ['user_id' => $a->id], $this->h())->assertStatus(201);

        $r = $this->postJson('/api/payments', $cuerpo + ['user_id' => $b->id], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');
        $this->assertArrayNotHasKey('id', $r->json());
        $this->assertArrayNotHasKey('user', $r->json());
        $this->postJson('/api/payments', ['amount' => 79000] + $cuerpo + ['user_id' => $a->id], $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');

        $this->assertSame(1, Payment::count());
        $this->postJson('/api/payments', $cuerpo + ['user_id' => $a->id], $this->h())->assertOk()->assertJsonPath('idempotent_replay', true);
    }

    public function test_la_llave_de_una_venta_no_devuelve_ni_crea_otra_venta(): void
    {
        $p = $this->producto();
        $cuerpo = ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'payment_method' => 'cash', 'client_request_id' => 'venta-1', 'customer_name' => 'Ana'];
        $this->postJson('/api/admin/caja/sales', $cuerpo, $this->h())->assertStatus(201);

        $r = $this->postJson('/api/admin/caja/sales', ['items' => [['product_id' => $p->id, 'quantity' => 2]]] + $cuerpo, $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');
        $this->assertArrayNotHasKey('data', $r->json());
        $this->postJson('/api/admin/caja/sales', ['customer_name' => 'Pedro'] + $cuerpo, $this->h())
            ->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');

        $this->assertSame(1, ProductSale::count());
        $this->postJson('/api/admin/caja/sales', $cuerpo, $this->h())->assertOk()->assertJsonPath('idempotent_replay', true);
    }

    // ── B-3/B-4 · Historial y vistas previas: una persona ───────────────────

    public function test_historiales_y_vistas_previas_exigen_una_persona(): void
    {
        $cobro = $this->cobrar();
        $venta = $this->vender($this->producto(), 1);
        $deuda = $this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $this->socio()->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo', 'total_amount' => 10000,
        ], $this->h())->json('data.id');
        $token = $this->sharedTokenHeaders();

        foreach ([
            "/api/payments/{$cobro->id}/history",
            "/api/payments/{$cobro->id}/correction-preview?action=cancel",
            "/api/admin/receivables/{$deuda}/history",
            "/api/admin/caja/sales/{$venta->id}/correction-preview?action=refund",
        ] as $url) {
            $this->getJson($url, $token)->assertStatus(403)->assertJsonPath('code', 'person_required');
            $this->getJson($url, $this->h())->assertOk();
        }
    }

    /** Una mejora que no se puede deshacer es un BLOQUEO en la vista previa, no un 422. */
    public function test_la_vista_previa_de_una_mejora_irreversible_es_un_bloqueo(): void
    {
        $socio = $this->socio();
        $basico = $this->plan();
        $mejora = Payment::create([
            'user_id' => $socio->id, 'plan_id' => Plan::create(['name' => 'Total', 'price' => 150000, 'duration_days' => 30, 'active' => true])->id,
            'upgraded_from_plan_id' => $basico->id, 'amount' => 70000, 'method' => 'cash', 'status' => 'paid',
            'paid_at' => now(), 'origin' => 'counter', 'cash_shift_id' => CashShift::currentOfType(CashShiftType::GYM)->id,
        ]);

        $p = $this->getJson("/api/payments/{$mejora->id}/correction-preview?action=cancel", $this->h())->assertOk()->json();
        $this->assertFalse($p['allowed']);
        $bloqueo = collect($p['blockers'])->firstWhere('code', 'membership_blocked');
        $this->assertNotNull($bloqueo);
        $this->assertStringContainsString('cambios posteriores', $bloqueo['message']);
    }

    /** La vista previa no deja en el log avisos de una anulación que no ocurrió. */
    public function test_la_vista_previa_no_deja_avisos_en_el_log(): void
    {
        $socio = $this->socio();
        $cobro = $this->cobrar($socio);
        // Un ajuste manual: la cadena de cobros ya no explica el vencimiento.
        $socio->forceFill(['membership_end_date' => now()->addDays(45)->toDateString()])->save();
        Log::spy();

        $this->getJson("/api/payments/{$cobro->id}/correction-preview?action=cancel", $this->h())->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    // ── Indicadores e informes con devoluciones ─────────────────────────────

    /**
     * GANANCIAS es base de caja: la venta cuenta el día que entró el dinero y la
     * devolución resta el día que salió. Una venta devuelta entera sigue contando
     * el día que se cobró.
     */
    public function test_ganancias_restan_la_devolucion_el_dia_que_sale(): void
    {
        $batido = $this->producto(5000);
        $parcial = $this->vender($batido, 2);
        $entera = $this->vender($batido, 1);

        $this->travel(1)->days();
        $this->postJson("/api/admin/caja/sales/{$parcial->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $parcial->items->first()->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(201);
        $this->postJson("/api/admin/caja/sales/{$entera->id}/refund", ['reason' => self::MOTIVO], $this->h())->assertStatus(201);

        $dia = fn (string $f, string $t) => $this->getJson("/api/admin/earnings?from={$f}&to={$t}&source=cafeteria", $this->h())->assertOk()->json();
        $this->assertEquals(15000, $dia('2026-10-27', '2026-10-27')['totals']['cafeteria_revenue'], 'el día 1 entraron 15.000');
        $this->assertEquals(-10000, $dia('2026-10-28', '2026-10-28')['totals']['cafeteria_revenue'], 'el día 2 salieron 10.000');
        $ambos = $dia('2026-10-27', '2026-10-28');
        $this->assertEquals(5000, $ambos['totals']['cafeteria_revenue']);
        $this->assertSame([15000.0, -10000.0], array_map('floatval', array_column($ambos['series'], 'cafeteria')));
        $this->assertEquals(5000, collect($ambos['by_method'])->where('source', 'cafeteria')->sum('amount'));
        // La utilidad: solo lo que no volvió (1 batido de margen 2.500).
        $this->assertEquals(2500, $ambos['totals']['cafeteria_profit']);
    }

    /** El panel (base de caja): el cobro devuelto cuenta su día y la devolución resta el suyo. */
    public function test_el_panel_resta_la_devolucion_el_dia_que_sale(): void
    {
        $cobro = $this->cobrar();
        $this->travel(1)->days();
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => self::MOTIVO], $this->h())->assertStatus(201);

        $panel = $this->getJson('/api/admin/reports/overview', $this->h())->assertOk()->json();
        $serie = collect($panel['revenue_series'])->keyBy('date');
        $this->assertSame(80000, $serie['2026-10-27']['revenue']);
        $this->assertSame(-80000, $serie['2026-10-28']['revenue']);
        $this->assertEquals(0, $panel['kpis']['total_revenue']);
    }

    /**
     * El informe de dinero (MoneyLedger) descuenta lo devuelto de cada venta en
     * su propia fecha, como ya hacía con las devueltas enteras, y enseña las
     * devoluciones aparte por la fecha en que salieron.
     */
    public function test_el_informe_de_dinero_neta_las_devoluciones_parciales(): void
    {
        $venta = $this->vender($this->producto(5000), 2);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(201);

        $libro = new MoneyLedger(ReportRange::fromRequest(['from' => '2026-10-27']), ['source' => 'product']);
        $producto = collect($libro->totals()['by_source'])->firstWhere('key', MoneyLedger::SOURCE_PRODUCT);
        $this->assertEquals(5000, $producto['amount']);
        $this->assertEquals(5000, collect($libro->byMethod())->firstWhere('key', 'cash')['amount']);
        $this->assertEquals(5000, collect($libro->series())->sum('product'));
        $this->assertEquals(['amount' => 5000.0, 'count' => 1], $libro->reversals()['refunds']);
    }

    /** «Ingresos hoy» de la caja de cafetería: lo que entró menos lo que salió hoy. */
    public function test_ingresos_de_hoy_restan_lo_devuelto_hoy(): void
    {
        $venta = $this->vender($this->producto(5000), 2);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(201);

        $this->assertEquals(5000, $this->getJson('/api/admin/caja/stats', $this->h())->assertOk()->json('revenue_today'));
    }

    // ── Ronda final del reviewer ────────────────────────────────────────────

    /** BLOQUEANTE: un cobro no nace devuelto (sería una entrada sin su salida). */
    public function test_un_cobro_no_nace_devuelto(): void
    {
        $this->postJson('/api/payments', [
            'user_id' => $this->socio()->id, 'plan_id' => $this->plan()->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'refunded',
        ], $this->h())->assertStatus(422)->assertJsonPath('code', 'use_refund_endpoint');

        $this->assertSame(0, Payment::count());
        $turno = CashShift::currentOfType(CashShiftType::GYM);
        $this->assertSame('0.00', app(CashShiftTotalsService::class)->for($turno)['expected_cash']);
    }

    /** La devolución acepta los medios de mostrador (Nequi, Daviplata) y rechaza en español. */
    public function test_la_devolucion_acepta_medios_de_mostrador_y_responde_en_espanol(): void
    {
        $cobro = $this->cobrar();
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => self::MOTIVO, 'method' => 'wompi'], $this->h())
            ->assertStatus(422)->assertJsonPath('errors.method.0', 'Wompi no se cobra en mostrador: ese dinero lo registra la propia pasarela cuando aprueba el pago.');
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => self::MOTIVO, 'method' => 'fiado'], $this->h())
            ->assertStatus(422)->assertJsonValidationErrors('method');
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => self::MOTIVO, 'method' => 'Nequi'], $this->h())
            ->assertStatus(201)->assertJsonPath('refund.method', 'nequi');

        $venta = $this->vender($this->producto(), 1);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", ['reason' => self::MOTIVO, 'method' => 'daviplata'], $this->h())
            ->assertStatus(201)->assertJsonPath('refund.method', 'daviplata');

        $deuda = $this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $this->socio()->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo', 'total_amount' => 10000,
        ], $this->h())->json('data.id');
        $abono = $this->postJson("/api/admin/receivables/{$deuda}/payments", ['amount' => 5000, 'method' => 'cash'], $this->h())->json('data.id');
        $this->postJson("/api/admin/receivables/payments/{$abono}/method", ['method' => 'nequi', 'reason' => 'Fue por Nequi'], $this->h())
            ->assertOk()->assertJsonPath('data.method', 'transfer');
    }

    /**
     * Devolución parcial con la factura de la venta aún sin emitir: la factura
     * se recalcula por lo que quedó, en vez de emitirse luego por el total.
     */
    public function test_la_devolucion_parcial_recalcula_la_factura_pendiente(): void
    {
        $venta = $this->vender($this->producto(5000), 2);
        $factura = ElectronicInvoice::where('source_type', $venta->getMorphClass())->where('source_id', $venta->id)->firstOrFail();
        $this->assertSame('pending', $factura->status->value);
        $this->assertEquals(10000, $factura->total);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(201);

        $factura->refresh();
        $this->assertEquals(5000, $factura->total);
        $this->assertEquals(5000, $factura->source_amount_snapshot);
        $this->assertEquals(1, (float) $factura->payload_snapshot['items'][0]['quantity']);
        $this->assertSame('5000.00', $factura->payload_snapshot['payment_details'][0]['amount']);
        $this->assertEquals(5000, $venta->fresh()->grossAmountValue());
    }

    /** En un lote sin anular, o sin importes por línea, la devolución parcial no recalcula: 409. */
    public function test_la_devolucion_parcial_en_lote_o_sin_importes_por_linea_es_409(): void
    {
        $venta = $this->vender($this->producto(5000), 2);
        $socio = Member::where('user_id', $this->socio()->id)->firstOrFail();
        $lote = InvoiceBundle::create([
            'member_id' => $socio->id, 'idempotency_key' => 'lote-'.uniqid(), 'total' => 10000, 'preview_hash' => str_repeat('a', 64),
        ]);
        InvoiceBundleItem::create([
            'invoice_bundle_id' => $lote->id, 'origin_type' => 'product_sale', 'origin_id' => $venta->id,
            'description' => 'Venta', 'amount' => 10000, 'active' => true,
        ]);
        $factura = ElectronicInvoice::create([
            'source_type' => $lote->getMorphClass(), 'source_id' => $lote->id, 'type' => 'invoice', 'status' => 'pending', 'total' => '10000.00',
        ]);
        $parcial = ['reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 1]]];
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", $parcial, $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'invoice_in_bundle')->assertJsonPath('invoice_id', $factura->id);

        $antigua = $this->vender($this->producto(5000), 2);
        ProductSaleItem::where('product_sale_id', $antigua->id)->update(['gross_amount' => null, 'base_amount' => null, 'tax_amount' => null]);
        $this->postJson("/api/admin/caja/sales/{$antigua->id}/refund", ['reason' => self::MOTIVO, 'items' => [['item_id' => $antigua->items->first()->id, 'quantity' => 1]]], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'invoice_not_recalculable');
    }

    /** Historial de la venta: persona obligatoria y mismo formato que el del cobro. */
    public function test_el_historial_de_una_venta(): void
    {
        $venta = $this->vender($this->producto(), 1);
        $this->postJson("/api/admin/caja/sales/{$venta->id}/cancel", ['reason' => 'Venta duplicada'], $this->h())->assertOk();

        $this->getJson("/api/admin/caja/sales/{$venta->id}/history", $this->sharedTokenHeaders())
            ->assertStatus(403)->assertJsonPath('code', 'person_required');
        $filas = $this->getJson("/api/admin/caja/sales/{$venta->id}/history", $this->h())->assertOk()->json('data');
        $this->assertSame(['create', 'status'], array_column($filas, 'action'));
        $this->assertSame('Venta duplicada', $filas[1]['reason']);
        $this->assertSame(['id', 'at', 'action', 'entity', 'entity_id', 'summary', 'actor_name', 'actor_role', 'changes', 'reason', 'metadata'], array_keys($filas[1]));
    }

    /**
     * Reintento de una devolución con la misma llave que llega DETRÁS de su
     * propia petición (la primera termina mientras la segunda espera el
     * bloqueo): recibe la devolución ya hecha, no un 422 ni un «ya estaba».
     */
    public function test_el_reintento_que_espera_el_bloqueo_recibe_la_misma_devolucion(): void
    {
        $venta = $this->vender($this->producto(5000), 3);
        $cuerpo = ['reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 2]], 'client_request_id' => 'dev-x'];
        $this->primeroTermina(fn () => app(SaleCorrections::class)->refund($venta, $cuerpo, $this->super, request()));
        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", $cuerpo, $this->h())
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, CashRefund::where('refundable_id', $venta->id)->count());

        $cobro = $this->cobrar();
        $cuerpo = ['reason' => self::MOTIVO, 'client_request_id' => 'dev-cobro-x'];
        $this->primeroTermina(fn () => app(PaymentCorrections::class)->refund($cobro, $cuerpo, $this->super, request()));
        $this->postJson("/api/payments/{$cobro->id}/refund", $cuerpo, $this->h())
            ->assertOk()->assertJsonPath('idempotent_replay', true);
    }

    /** En devoluciones y en el medio de un abono, «mixto» y «receivable» no se admiten: el mensaje lo dice así. */
    public function test_los_medios_que_no_caben_se_rechazan_con_un_mensaje_del_sitio(): void
    {
        $cobro = $this->cobrar();
        $venta = $this->vender($this->producto(), 1);
        $deuda = $this->postJson('/api/admin/receivables', [
            'member_id' => Member::where('user_id', $this->socio()->id)->value('id'), 'type' => 'gym',
            'concept' => 'Saldo', 'total_amount' => 10000,
        ], $this->h())->json('data.id');
        $abono = $this->postJson("/api/admin/receivables/{$deuda}/payments", ['amount' => 5000, 'method' => 'cash'], $this->h())->json('data.id');

        foreach ([
            "/api/payments/{$cobro->id}/refund" => ['reason' => self::MOTIVO],
            "/api/admin/caja/sales/{$venta->id}/refund" => ['reason' => self::MOTIVO],
            "/api/admin/receivables/payments/{$abono}/method" => ['reason' => self::MOTIVO],
        ] as $url => $cuerpo) {
            $this->postJson($url, $cuerpo + ['method' => 'mixed'], $this->h())->assertStatus(422)
                ->assertJsonPath('errors.method.0', 'Aquí no se admite «mixto»: indica un solo medio (efectivo, transferencia, tarjeta, Nequi, Daviplata…).');
            $mensaje = $this->postJson($url, $cuerpo + ['method' => 'receivable'], $this->h())->assertStatus(422)->json('errors.method.0');
            $this->assertStringStartsWith('Aquí no se admite «receivable»', $mensaje);
            $this->assertStringNotContainsString('Pagos', $mensaje);
        }
    }

    /**
     * Devolución parcial con una factura que no se puede rehacer (tiene CUFE y
     * no está validada): 409 y no se devuelve nada, en vez de dejar la
     * factura por el total original.
     */
    public function test_la_devolucion_parcial_con_factura_no_rehacible_es_409(): void
    {
        $batido = $this->producto(5000);
        $venta = $this->vender($batido, 2);
        $factura = ElectronicInvoice::where('source_type', $venta->getMorphClass())->where('source_id', $venta->id)->firstOrFail();
        $factura->update(['status' => 'rejected', 'cufe' => 'cufe-rechazada-1']);

        $this->postJson("/api/admin/caja/sales/{$venta->id}/refund", [
            'reason' => self::MOTIVO, 'items' => [['item_id' => $venta->items->first()->id, 'quantity' => 1]],
        ], $this->h())->assertStatus(409)->assertJsonPath('code', 'invoice_not_recalculable')->assertJsonPath('invoice_id', $factura->id);

        $this->assertSame(0, CashRefund::count());
        $this->assertSame(0, (int) $venta->items->first()->fresh()->refunded_quantity);
        $this->assertSame(48, $batido->fresh()->stock);
        $this->assertEquals(10000, $factura->fresh()->total);
    }

    // ── Fugas de datos (revisión automática tras eea3134) ───────────────────

    /**
     * El token compartido (con `payments.view` y `cash.gym.view` por ser
     * lectura) no recibe los enlaces del cobro —devoluciones con motivo, autor
     * y referencia; recibos; posibles duplicados— ni el informe del turno.
     */
    public function test_el_token_no_recibe_enlaces_ni_detalle_de_correcciones(): void
    {
        $socio = $this->socio();
        $devuelto = $this->cobrar($socio);
        $anulado = $this->cobrar();
        $this->postJson("/api/payments/{$devuelto->id}/refund", ['reason' => 'Motivo privado de la devolución'], $this->h())->assertStatus(201);
        $turno = CashShift::currentOfType(CashShiftType::GYM);
        app(CashShiftService::class)->close($this->super, CashShiftType::GYM);
        $this->travel(5)->minutes();
        $this->patchJson("/api/payments/{$anulado->id}", ['status' => 'cancelled', 'reason' => 'Motivo privado de la anulación'], $this->h())->assertOk();
        $token = $this->sharedTokenHeaders();

        $this->getJson("/api/payments/{$devuelto->id}", $token)->assertOk()->assertJsonMissingPath('links');
        $persona = $this->getJson("/api/payments/{$devuelto->id}", $this->h())->assertOk();
        $this->assertSame('Motivo privado de la devolución', $persona->json('links.refunds.0.reason'));
        $sinCreditos = $this->getJson("/api/payments/{$devuelto->id}", $this->adminWithPermissions(['payments.view']))->assertOk()->json('links');
        $this->assertArrayNotHasKey('receivable', $sinCreditos);
        $this->assertArrayHasKey('receivable', $persona->json('links'));

        // El informe del turno (pantalla y PDF), con sus devoluciones y sus
        // correcciones posteriores, ya exige una PERSONA: el token recibe 403.
        $this->getJson("/api/admin/caja/shifts/{$turno->id}", $token)->assertStatus(403);
        $this->get("/api/admin/caja/shifts/{$turno->id}/pdf", $token)->assertStatus(403);
        $dePersona = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->h())->assertOk()->json();
        $this->assertSame('Motivo privado de la devolución', $dePersona['refunds'][0]['reason']);
        $this->assertSame('Motivo privado de la anulación', $dePersona['post_close_corrections'][0]['reason']);
    }

    /** Historial de una venta fiada: las filas de deuda y abono, solo con `receivables.view`. */
    public function test_el_historial_de_la_venta_sin_creditos_no_ve_deuda_ni_abonos(): void
    {
        $socio = Member::where('user_id', $this->socio()->id)->firstOrFail();
        $fiada = $this->vender($this->producto(), 1, ['credit' => true, 'payment_method' => null, 'member_id' => $socio->id]);
        $deuda = Receivable::where('source_type', ProductSale::class)->where('source_id', $fiada->id)->firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", ['amount' => 1000, 'method' => 'cash'], $this->h())->assertStatus(201);

        $soloCaja = $this->getJson("/api/admin/caja/sales/{$fiada->id}/history", $this->adminWithPermissions(['cash.products.view']))
            ->assertOk()->json('data');
        $this->assertSame(['venta'], array_values(array_unique(array_column($soloCaja, 'entity'))));

        $conCreditos = $this->getJson("/api/admin/caja/sales/{$fiada->id}/history", $this->adminWithPermissions(['cash.products.view', 'receivables.view']))
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['venta', 'cuenta por cobrar', 'abono'], array_values(array_unique(array_column($conCreditos, 'entity'))));

        // Y la vista previa de la venta tampoco enseña la deuda sin Créditos.
        $this->assertNull($this->getJson("/api/admin/caja/sales/{$fiada->id}/correction-preview?action=cancel", $this->adminWithPermissions(['cash.products.view']))
            ->assertOk()->json('effects.receivable'));
        $this->assertSame($deuda->id, $this->getJson("/api/admin/caja/sales/{$fiada->id}/correction-preview?action=cancel", $this->adminWithPermissions(['cash.products.view', 'receivables.view']))
            ->assertOk()->json('effects.receivable.id'));
    }

    /**
     * Hace que la PRIMERA petición con la llave termine justo cuando la segunda
     * ya pasó la comprobación rápida y va a bloquear: al pedir el turno abierto.
     */
    private function primeroTermina(callable $primera): void
    {
        $this->app->instance(CashShiftService::class, new class(app(CashShiftTotalsService::class), $primera) extends CashShiftService
        {
            private $antes;

            public function __construct(CashShiftTotalsService $t, callable $antes)
            {
                parent::__construct($t);
                $this->antes = $antes;
            }

            public function lockOpen(CashShiftType $type): CashShift
            {
                if ($this->antes !== null) {
                    $antes = $this->antes;
                    $this->antes = null;
                    $antes();
                }

                return parent::lockOpen($type);
            }
        });
    }
}
