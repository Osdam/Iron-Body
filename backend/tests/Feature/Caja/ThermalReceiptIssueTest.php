<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Exceptions\ReceiptException;
use App\Models\Admin;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receipt;
use App\Models\ReceiptSource;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\TaxRate;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EMITIR UN RECIBO TÉRMICO: qué se imprime, con qué número y de qué operaciones.
 *
 * Lo que estas pruebas protegen, en orden de gravedad:
 *
 *  · Que la tirilla diga lo que pasó DE VERDAD: el total es el de la fila de
 *    dinero, el descuento y el IVA son los congelados, los medios son el
 *    desglose real. Nada se inventa —ni valor recibido ni cambio, que no existen
 *    en ninguna tabla—.
 *  · Que emitir NO toque el dinero: ni una fila de cobros, ventas, abonos,
 *    turnos, inventario o facturas cambia.
 *  · Que no haya dos recibos para la misma operación ni dos con el mismo número.
 *  · Que lo que no pasó por el mostrador (pasarela, automatización, migración,
 *    pendiente, anulado, revertido, el devengo de un plan a plazos) no se pueda
 *    imprimir como si fuera dinero recibido.
 *  · Que la hora sea la del gimnasio: las 19:30 de Neiva son las 00:30 UTC del
 *    día siguiente, y el reloj de la prueba va en UTC a propósito.
 */
class ThermalReceiptIssueTest extends TestCase
{
    use BuildsCashReceipts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relojDelMostrador();
        $this->empresa();
        $this->cajero = $this->recepcion();
        $this->h = $this->actingAsAdmin($this->cajero);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Cobro de membresía ──────────────────────────────────────────────────

    public function test_el_recibo_de_un_cobro_lleva_los_datos_reales_y_la_hora_de_neiva(): void
    {
        $socio = $this->socio('Laura Gómez');
        $pago = $this->cobrar($socio, $this->plan('Premium', 120000));
        $antes = $this->conteosDeNegocio();

        $res = $this->emitir('payment', $pago->id)->assertStatus(201);
        $recibo = $res->json('data.0');

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('RG-000001', $recibo['number']);
        $this->assertSame('gym', $recibo['cash_type']);
        $this->assertSame(Receipt::KIND_MEMBERSHIP_PAYMENT, $recibo['kind']);
        $this->assertSame($pago->reference, $recibo['operation_ref']);
        $this->assertFalse($recibo['issued_late']);
        $this->assertSame('pending', $recibo['print_state']);
        // `live` es lo que la operación es HOY. El cobro real deja su factura
        // electrónica encolada (`pending`), y eso es lo que tiene que decir.
        $factura = ElectronicInvoice::where('source_type', $pago->getMorphClass())->where('source_id', $pago->id)->first();
        $this->assertSame([
            'operation_status' => 'paid',
            'invoice' => $factura ? ['status' => $factura->status->value, 'full_number' => $factura->full_number] : null,
        ], $recibo['live']);

        // La HORA: el cobro entró a las 00:30 UTC del 17 y en Neiva eran las
        // 19:30 del 16. Es lo que se imprime.
        $this->assertSame('2026-10-17T00:30:00+00:00', $recibo['operation_at']);
        $s = $recibo['snapshot'];
        $this->assertSame('16/10/2026 19:30', $s['local_datetime']);

        $this->assertSame([
            // El nombre comercial por defecto (config/caja.php), pedido por el propietario.
            'name' => 'IRON BODY WORKOUT',
            'legal_name' => 'IRON BODY NEIVA S.A.S.',
            'nit' => '900123456-7',
            'address' => 'Calle 10 # 5-20, Neiva',
            'phone' => '3001234567',
        ], $s['business']);
        $this->assertSame('Caja de pagos', $s['cash_label']);
        $this->assertSame('Recibo de pago', $s['title']);
        $this->assertSame('Ana Recepción', $s['cashier']);
        $this->assertSame('Este documento no es una factura electrónica de venta', $s['legend']);

        // Del cliente, SOLO el nombre. Ni documento ni teléfono en ninguna parte
        // de la tirilla.
        $this->assertSame('Laura Gómez', $s['customer']);
        $json = json_encode($s);
        $this->assertStringNotContainsString($socio->document_number, $json);
        $this->assertStringNotContainsString('3109998877', $json);

        // Totales que cuadran con la fila de dinero, sin «valor recibido» ni
        // «cambio»: no existen en ninguna tabla.
        $this->assertSame(['subtotal', 'discount', 'taxes', 'total', 'paid_now', 'balance_after'], array_keys($s['totals']));
        $this->assertEquals((float) $pago->amount, $s['totals']['total']);
        $this->assertEquals(120000.0, $s['totals']['paid_now']);
        $this->assertNull($s['totals']['balance_after']);
        $this->assertEquals([[
            'description' => 'Plan Premium', 'quantity' => 1,
            'unit_price' => 120000, 'discount' => 0, 'total' => 120000,
        ]], $s['lines']);
        $this->assertEquals([['method_label' => 'Efectivo', 'amount' => 120000, 'reference' => null]], $s['payments']);

        // El periodo que el cobro cubrió DE VERDAD (lo fija MembershipPeriod).
        $pago->refresh();
        $this->assertNotNull($pago->period_start);
        $this->assertSame([
            'plan' => 'Premium',
            'period_from' => $pago->period_start->format('d/m/Y'),
            'period_to' => $pago->period_end->format('d/m/Y'),
            'upgraded_from' => null,
        ], $s['membership']);
        $this->assertSame('16/10/2026', $s['membership']['period_from'], 'el día del gimnasio, no el UTC');
        $this->assertNull($s['debt']);

        $this->assertSame($antes, $this->conteosDeNegocio(), 'emitir no mueve dinero');
    }

    public function test_pedir_dos_veces_el_mismo_recibo_devuelve_el_mismo(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());

        $primero = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0');
        $segundo = $this->emitir('payment', $pago->id)->assertStatus(200)->json('data.0');

        $this->assertSame($primero['uuid'], $segundo['uuid']);
        $this->assertSame($primero['number'], $segundo['number']);
        $this->assertSame(1, Receipt::count());
        $this->assertSame(1, ReceiptSource::count());
        $this->assertSame(1, (int) DB::table('receipt_sequences')->where('cash_type', 'gym')->value('last_number'),
            'la repetición no gasta número');
    }

    public function test_la_numeracion_es_correlativa_por_caja_y_sin_duplicados(): void
    {
        $plan = $this->plan();
        $agua = $this->producto();

        $numeros = [];
        // Intercalados a propósito: cada caja lleva su propio contador.
        $numeros[] = $this->emitir('payment', $this->cobrar($this->socio('A'), $plan)->id)->json('data.0.number');
        $numeros[] = $this->emitir('product_sale', $this->vender([['product_id' => $agua->id, 'quantity' => 1]])->id)->json('data.0.number');
        $numeros[] = $this->emitir('payment', $this->cobrar($this->socio('B'), $plan)->id)->json('data.0.number');
        $numeros[] = $this->emitir('payment', $this->cobrar($this->socio('C'), $plan)->id)->json('data.0.number');
        $numeros[] = $this->emitir('product_sale', $this->vender([['product_id' => $agua->id, 'quantity' => 2]])->id)->json('data.0.number');

        $this->assertSame(['RG-000001', 'RP-000001', 'RG-000002', 'RG-000003', 'RP-000002'], $numeros);
        $this->assertSame(Receipt::count(), Receipt::distinct()->count('number'));
        $this->assertSame(3, (int) DB::table('receipt_sequences')->where('cash_type', 'gym')->value('last_number'));
        $this->assertSame(2, (int) DB::table('receipt_sequences')->where('cash_type', 'products')->value('last_number'));
    }

    public function test_un_recibo_emitido_mas_de_diez_minutos_despues_queda_marcado(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());

        Carbon::setTestNow(now()->addMinutes(11));

        $recibo = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0');

        $this->assertTrue($recibo['issued_late']);
        // La hora impresa sigue siendo la de la OPERACIÓN, no la de emitir.
        $this->assertSame('16/10/2026 19:30', $recibo['snapshot']['local_datetime']);
    }

    // ── Lo que no se imprime ────────────────────────────────────────────────

    public function test_lo_que_no_paso_por_el_mostrador_o_no_es_dinero_no_se_imprime(): void
    {
        $plan = $this->plan();
        $socio = $this->socio();
        $super = $this->adminHeaders();
        $turnoGym = $this->abrir(CashShiftType::GYM);

        // Orígenes que el CRM no crea por HTTP, insertados como los crea su código.
        $fuera = fn (string $origen, string $metodo) => Payment::create([
            'user_id' => $socio->user_id, 'plan_id' => $plan->id, 'amount' => 120000,
            'method' => $metodo, 'status' => 'paid', 'paid_at' => now(), 'origin' => $origen,
        ]);
        $pasarela = $fuera('gateway', 'wompi');
        $automatizacion = $fuera('automation', 'transfer');
        $migracion = $fuera('legacy', 'manual');

        $pendiente = $this->cobrar($this->socio('Pendiente'), $plan, ['status' => 'pending']);
        $this->assertSame($turnoGym->id, $pendiente->cash_shift_id, 'el pendiente sí es de mostrador');

        $anulado = $this->cobrar($this->socio('Anulado'), $plan);
        $this->putJson("/api/payments/{$anulado->id}", ['status' => 'cancelled'], $super)->assertOk();

        [$devengo] = $this->venderAPlazos($this->socio('Plazos'), $this->plan('Anual', 200000), 80000);

        $agua = $this->producto();
        $ventaPendiente = $this->vender([['product_id' => $agua->id, 'quantity' => 1]], ['paid' => false]);
        $ventaAnulada = $this->vender([['product_id' => $agua->id, 'quantity' => 1]], ['paid' => false]);
        $this->postJson("/api/admin/caja/sales/{$ventaAnulada->id}/cancel", [], $super)->assertOk();

        $deuda = Receivable::where('source_type', Payment::class)->firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 1000, 'method' => 'cash', 'client_request_id' => 'abono-a-revertir',
        ], $this->h)->assertStatus(201);
        $abono = ReceivablePayment::where('client_request_id', 'abono-a-revertir')->firstOrFail();
        $this->postJson("/api/admin/receivables/payments/{$abono->id}/reverse", ['reason' => 'Se tecleó mal el importe'], $super)
            ->assertOk();

        $antes = $this->conteosDeNegocio();

        $casos = [
            ['payment', $pasarela->id, 'gateway'],
            ['payment', $automatizacion->id, 'automation'],
            ['payment', $migracion->id, 'legacy'],
            ['payment', $pendiente->id, 'pending'],
            ['payment', $anulado->id, 'cancelled'],
            ['payment', $devengo->id, 'installment_accrual'],
            ['product_sale', $ventaPendiente->id, 'pending'],
            ['product_sale', $ventaAnulada->id, 'cancelled'],
            ['receivable_payment', $abono->id, 'reversed'],
        ];

        foreach ($casos as [$tipo, $id, $motivo]) {
            $this->emitir($tipo, $id)
                ->assertStatus(422)
                ->assertJsonPath('ok', false)
                ->assertJsonPath('code', 'not_printable')
                ->assertJsonPath('reason', $motivo)
                ->assertJsonPath('source_type', $tipo)
                ->assertJsonPath('source_id', $id);
        }

        $this->assertSame(0, Receipt::count());
        $this->assertSame($antes, $this->conteosDeNegocio());
    }

    public function test_una_operacion_inexistente_es_404(): void
    {
        $this->emitir('payment', 999999)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->emitir('receivable_payment', [999998, 999999])->assertStatus(404);
    }

    // ── Snapshot congelado, estado en vivo ──────────────────────────────────

    public function test_el_snapshot_no_cambia_y_live_refleja_lo_que_paso_despues(): void
    {
        $plan = $this->plan('Premium', 120000);
        $pago = $this->cobrar($this->socio(), $plan);
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');

        // Después de imprimir: se renombra el plan, se factura y se anula.
        $plan->update(['name' => 'Premium 2027']);
        // La factura que el cobro dejó encolada se valida ante la DIAN después.
        ElectronicInvoice::updateOrCreate(
            ['source_type' => $pago->getMorphClass(), 'source_id' => $pago->id, 'type' => 'invoice'],
            ['status' => 'validated', 'full_number' => 'IBFE77', 'total' => '120000.00'],
        );

        $leido = $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->json('data');

        $this->assertSame('Plan Premium', $leido['snapshot']['lines'][0]['description'], 'la reimpresión dice lo que dijo');
        $this->assertSame('Premium', $leido['snapshot']['membership']['plan']);
        $this->assertSame(['status' => 'validated', 'full_number' => 'IBFE77'], $leido['live']['invoice']);
        $this->assertSame([], $leido['prints']);

        $this->putJson("/api/payments/{$pago->id}", ['status' => 'cancelled'], $this->adminHeaders())->assertOk();
        $otraVez = $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->json('data');
        $this->assertSame('cancelled', $otraVez['live']['operation_status']);
        $this->assertSame($leido['snapshot'], $otraVez['snapshot']);

        // Y el propio modelo se niega a reescribirlo o borrarlo.
        $recibo = Receipt::where('uuid', $uuid)->firstOrFail();
        try {
            $recibo->update(['snapshot' => ['title' => 'falso']]);
            $this->fail('Un recibo emitido no puede modificarse.');
        } catch (ReceiptException $e) {
            $this->assertSame('receipt_immutable', $e->code_);
        }
        $this->expectException(ReceiptException::class);
        $recibo->delete();
    }

    // ── Mejora, plazos y comisión ───────────────────────────────────────────

    public function test_una_mejora_de_plan_imprime_de_donde_viene(): void
    {
        $this->abrir(CashShiftType::GYM);
        [$user, , $total] = $this->socioConMensual();

        $this->postJson("/api/users/{$user->id}/plan-upgrade", [
            'plan_id' => $total->id, 'method' => 'transfer',
        ], $this->h)->assertStatus(201);
        $pago = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();

        $s = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.snapshot');

        $this->assertSame('Recibo de pago · Mejora de plan', $s['title']);
        $this->assertSame('Mejora de plan: Mensual → Total Access', $s['lines'][0]['description']);
        $this->assertSame('Mensual', $s['membership']['upgraded_from']);
        $this->assertSame('Total Access', $s['membership']['plan']);
        $this->assertEquals((float) $pago->amount, $s['totals']['total']);
        $this->assertSame('Transferencia', $s['payments'][0]['method_label']);
        $this->assertSame(Receipt::KIND_PLAN_UPGRADE, Receipt::firstOrFail()->kind);
    }

    public function test_el_plan_a_plazos_imprime_el_primer_abono_y_no_el_devengo(): void
    {
        [$devengo, $primerAbono, $deudaId] = $this->venderAPlazos($this->socio('Pedro Plazos'), $this->plan('Anual', 200000), 80000);

        $this->emitir('payment', $devengo->id)->assertStatus(422)->assertJsonPath('reason', 'installment_accrual');

        $r = $this->emitir('receivable_payment', $primerAbono->id)->assertStatus(201)->json('data.0');
        $s = $r['snapshot'];

        $this->assertSame(Receipt::KIND_INSTALLMENT_FIRST_PAYMENT, $r['kind']);
        $this->assertSame('gym', $r['cash_type']);
        $this->assertNull($r['operation_ref']);
        $this->assertSame('Pedro Plazos', $s['customer']);
        $this->assertEquals(80000, $s['totals']['total']);
        $this->assertEquals(80000, $s['totals']['paid_now']);
        $this->assertEquals(120000, $s['totals']['balance_after']);
        $this->assertSame('Plan Anual', $s['debt']['concept']);
        $this->assertEquals(200000, $s['debt']['balance_before']);
        $this->assertEquals(120000, $s['debt']['balance_after']);
        $this->assertSame(Receivable::findOrFail($deudaId)->due_at->format('d/m/Y'), $s['debt']['due_at']);
        $devengo->refresh();
        $this->assertSame('Anual', $s['membership']['plan']);
        $this->assertSame($devengo->period_start?->format('d/m/Y'), $s['membership']['period_from']);
        $this->assertSame(['operation_status' => 'applied', 'invoice' => null], $r['live']);

        // El SEGUNDO abono es un abono a cuenta, sin membresía que activar.
        $this->postJson("/api/admin/receivables/{$deudaId}/payments", [
            'amount' => 50000, 'method' => 'card', 'client_request_id' => 'segundo-abono-1',
        ], $this->h)->assertStatus(201);
        $segundo = ReceivablePayment::where('client_request_id', 'segundo-abono-1')->firstOrFail();

        $r2 = $this->emitir('receivable_payment', $segundo->id)->assertStatus(201)->json('data.0');
        $this->assertSame(Receipt::KIND_RECEIVABLE_PAYMENT, $r2['kind']);
        $this->assertSame('RG-000002', $r2['number']);
        $this->assertNull($r2['snapshot']['membership']);
        $this->assertEquals(120000, $r2['snapshot']['debt']['balance_before']);
        $this->assertEquals(70000, $r2['snapshot']['debt']['balance_after']);
        $this->assertSame('Tarjeta', $r2['snapshot']['payments'][0]['method_label']);
    }

    public function test_el_cobro_del_piso_de_un_entrenador_imprime_su_abono(): void
    {
        $this->abrir(CashShiftType::GYM);
        $entrenador = Trainer::create([
            'full_name' => 'Carlos Entrenador', 'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'carlos-'.uniqid().'@ironbody.test', 'status' => 'active',
        ]);
        $cliente = $this->socio('Olga Cliente');

        // Pactar el piso es de dirección; cobrarlo, del mostrador.
        $acuerdo = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id, 'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'trainer',
        ], $this->adminHeaders())->assertStatus(201)->json('id');

        $this->postJson("/api/admin/commissions/agreements/{$acuerdo}/charge", ['method' => 'cash'], $this->h)
            ->assertStatus(201);

        $abono = ReceivablePayment::query()->latest('id')->firstOrFail();
        $r = $this->emitir('receivable_payment', $abono->id)->assertStatus(201)->json('data.0');

        $this->assertSame(Receipt::KIND_COMMISSION, $r['kind']);
        $this->assertSame('gym', $r['cash_type']);
        $this->assertSame('Recibo de pago · Piso de entrenador', $r['snapshot']['title']);
        $this->assertSame($abono->receivable->concept, $r['snapshot']['lines'][0]['description']);
        $this->assertEquals(50000, $r['snapshot']['totals']['total']);
        $this->assertSame('Carlos Entrenador', $r['snapshot']['customer']);
        $this->assertNotNull(TrainerCommissionAgreement::find($acuerdo));
    }

    // ── Productos ───────────────────────────────────────────────────────────

    public function test_una_venta_con_descuento_iva_y_dos_medios_cuadra_con_la_fila(): void
    {
        // Hoy el gimnasio no cobra IVA; el recibo tiene que seguir cuadrando el
        // día que lo cobre, así que esta prueba lo enciende.
        config(['tax_policy.issuer_is_vat_responsible' => true, 'tax_policy.vat_collection_enabled' => true]);
        $iva = TaxRate::create(['code' => 'IVA_19_INCL', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true, 'price_includes_tax' => true]);
        $proteina = $this->producto('Proteína', 11900, $iva);

        $venta = $this->vender([['product_id' => $proteina->id, 'quantity' => 2]], [
            'payment_method' => 'mixed',
            'discount' => 2000,
            'customer_name' => 'Mostrador',
            'splits' => [
                ['method' => 'cash', 'amount' => 10000],
                ['method' => 'nequi', 'amount' => 11800, 'reference' => 'NQ-8841'],
            ],
        ]);
        $venta->refresh();
        $this->assertSame('21800.00', $venta->total, 'la fila de origen: 23.800 - 2.000');

        $r = $this->emitir('product_sale', $venta->id)->assertStatus(201)->json('data.0');
        $s = $r['snapshot'];

        $this->assertSame('RP-000001', $r['number']);
        $this->assertSame(Receipt::KIND_PRODUCT_SALE, $r['kind']);
        $this->assertSame($venta->code, $r['operation_ref']);
        $this->assertSame('Caja de productos', $s['cash_label']);
        $this->assertSame('Mostrador', $s['customer']);
        $this->assertEquals([[
            'description' => 'Proteína', 'quantity' => 2,
            'unit_price' => 11900, 'discount' => 2000, 'total' => 21800,
        ]], $s['lines']);
        $this->assertEquals(23800, $s['totals']['subtotal']);
        $this->assertEquals(2000, $s['totals']['discount']);
        $this->assertEquals([['label' => 'IVA 19% (incluido)', 'amount' => 3800]], $s['totals']['taxes']);
        $this->assertEquals((float) $venta->total, $s['totals']['total']);
        $this->assertEquals(21800, $s['totals']['paid_now']);
        $this->assertEquals([
            ['method_label' => 'Efectivo', 'amount' => 10000, 'reference' => null],
            ['method_label' => 'Nequi', 'amount' => 11800, 'reference' => 'NQ-8841'],
        ], $s['payments']);
        $this->assertNull($s['debt']);
    }

    public function test_una_venta_a_credito_imprime_el_comprobante_de_entrega_con_su_saldo(): void
    {
        $socio = $this->socio('Óscar Fiado');
        $venta = $this->fiar($socio, $this->producto('Agua 600 ml', 3000), 2);
        $deuda = Receivable::where('source_type', $venta->getMorphClass())->where('source_id', $venta->id)->firstOrFail();

        $r = $this->emitir('product_sale', $venta->id)->assertStatus(201)->json('data.0');
        $s = $r['snapshot'];

        $this->assertSame(Receipt::KIND_PRODUCT_CREDIT_SALE, $r['kind']);
        $this->assertSame('Comprobante de entrega a crédito', $s['title']);
        $this->assertSame('Óscar Fiado', $s['customer']);
        $this->assertEquals(6000, $s['totals']['total']);
        $this->assertEquals(0, $s['totals']['paid_now'], 'a crédito no entró dinero');
        $this->assertEquals(6000, $s['totals']['balance_after']);
        $this->assertSame([], $s['payments']);
        $this->assertEquals([
            'concept' => $deuda->concept,
            'balance_before' => 0,
            'balance_after' => 6000,
            'due_at' => $deuda->due_at->format('d/m/Y'),
        ], $s['debt']);
        $this->assertSame('credit', $r['live']['operation_status']);
    }

    public function test_un_pedido_de_la_app_cobrado_en_mostrador_sin_turno_tambien_saca_tirilla(): void
    {
        // Toda venta confirmada saca tirilla. Un pedido de la app se cobra en el
        // mostrador por `pay()`, que NO le asigna turno: el recibo nace en la
        // caja de productos y sin turno, y aun así se entrega.
        $socio = $this->socio('Pedido App');
        $agua = $this->producto('Agua 600 ml', 3000);

        // El pedido, con los mismos campos con que lo crea AppStoreController
        // (su endpoint exige la sesión del socio en la app).
        $pedido = DB::transaction(function () use ($socio, $agua) {
            $p = ProductSale::create([
                'channel' => 'app', 'status' => 'pending', 'member_id' => $socio->id,
                'customer_name' => $socio->full_name, 'payment_method' => 'transfer', 'payment_status' => 'pending',
            ]);
            $p->items()->create([
                'product_id' => $agua->id, 'name' => $agua->name, 'unit_price' => 3000, 'quantity' => 2, 'subtotal' => 6000,
            ]);
            $p->update(['subtotal' => 6000, 'total' => 6000]);

            return $p;
        });

        // Y el cobro, por el endpoint REAL del mostrador.
        $this->postJson("/api/admin/caja/sales/{$pedido->id}/pay", ['payment_reference' => 'TRF-5531'], $this->h)->assertOk();
        $pedido->refresh();
        $this->assertSame('paid', $pedido->status);
        $this->assertNull($pedido->cash_shift_id, 'pay() no asigna turno: el hallazgo sigue ahí');

        $antes = $this->conteosDeNegocio();
        $r = $this->emitir('product_sale', $pedido->id)->assertStatus(201)->json('data.0');

        $this->assertSame('RP-000001', $r['number']);
        $this->assertSame('products', $r['cash_type']);
        $this->assertSame(Receipt::KIND_PRODUCT_SALE, $r['kind']);
        $this->assertSame($pedido->code, $r['operation_ref']);
        $this->assertNull(Receipt::where('uuid', $r['uuid'])->value('cash_shift_id'));
        $this->assertSame('Caja de productos', $r['snapshot']['cash_label']);
        $this->assertSame('Pedido App', $r['snapshot']['customer']);
        $this->assertEquals(6000, $r['snapshot']['totals']['total']);
        $this->assertEquals([['method_label' => 'Transferencia', 'amount' => 6000, 'reference' => 'TRF-5531']], $r['snapshot']['payments']);
        $this->assertEquals([[
            'description' => 'Agua 600 ml', 'quantity' => 2, 'unit_price' => 3000, 'discount' => 0, 'total' => 6000,
        ]], $r['snapshot']['lines']);
        $this->assertSame($antes, $this->conteosDeNegocio(), 'emitir no toca la venta ni le asigna turno');

        // Un cobro del GIMNASIO de mostrador sin turno sigue sin imprimirse:
        // ese caso no lo produce ningún flujo vivo y sería dinero fuera de caja.
        $huerfano = Payment::create([
            'user_id' => $socio->user_id, 'plan_id' => $this->plan()->id, 'amount' => 120000,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => now(), 'origin' => 'counter',
        ]);
        $this->emitir('payment', $huerfano->id)->assertStatus(422)->assertJsonPath('reason', 'no_shift');
    }

    // ── Cobrar todo ─────────────────────────────────────────────────────────

    public function test_cobrar_todo_con_deudas_de_las_dos_cajas_da_un_recibo_por_caja(): void
    {
        $socio = $this->socio('Marta Debe');
        $agua = $this->producto('Agua 600 ml', 3000);
        $this->fiar($socio, $agua, 1);
        $this->fiar($socio, $agua, 2);
        $this->abrir(CashShiftType::GYM);
        $this->postJson('/api/admin/receivables', [
            'member_id' => $socio->id, 'type' => 'gym', 'concept' => 'Saldo mensualidad octubre', 'total_amount' => 40000,
        ], $this->h)->assertStatus(201);

        $cobro = $this->postJson("/api/admin/receivables/people/member/{$socio->id}/settle", [
            'method' => 'cash', 'client_request_id' => 'barrido-7f3a',
        ], $this->h)->assertStatus(201)->json();
        $ids = array_column($cobro['payments'], 'id');
        $this->assertCount(3, $ids);
        $antes = $this->conteosDeNegocio();

        $res = $this->emitir('receivable_payment', $ids)->assertStatus(201);
        $recibos = collect($res->json('data'))->keyBy('cash_type');

        $this->assertCount(2, $recibos);
        $productos = $recibos['products'];
        $gimnasio = $recibos['gym'];

        $this->assertSame('RP-000001', $productos['number']);
        $this->assertSame(Receipt::KIND_SETTLEMENT, $productos['kind']);
        // La llave de un grupo es una huella de longitud fija: con 40 deudas la
        // lista de ids pasaba de 191 caracteres y PostgreSQL rechazaba el INSERT.
        $llave = Receipt::where('number', 'RP-000001')->value('origin_key');
        $this->assertMatchesRegularExpression('/^rp:[0-9a-f]{64}$/', $llave);
        $this->assertCount(2, $productos['snapshot']['lines']);
        $this->assertEquals(9000, $productos['snapshot']['totals']['total']);
        $this->assertEquals(0, $productos['snapshot']['totals']['balance_after']);
        $this->assertEquals([['method_label' => 'Efectivo', 'amount' => 9000, 'reference' => null]],
            $productos['snapshot']['payments'], 'un billete, una línea de medio');
        $this->assertNull($productos['snapshot']['debt']);

        $this->assertSame('RG-000001', $gimnasio['number']);
        $this->assertSame(Receipt::KIND_RECEIVABLE_PAYMENT, $gimnasio['kind']);
        $this->assertEquals(40000, $gimnasio['snapshot']['totals']['total']);
        $this->assertSame('Saldo mensualidad octubre', $gimnasio['snapshot']['debt']['concept']);

        // Cada caja imprime exactamente su dinero: la suma de sus abonos.
        foreach ($recibos as $caja => $r) {
            $suyos = ReceivablePayment::whereIn('id', $ids)->get()
                ->filter(fn (ReceivablePayment $a) => $a->receivable->type->value === $caja);
            $this->assertEquals($suyos->sum(fn ($a) => (float) $a->amount), $r['snapshot']['totals']['total'], $caja);
        }

        // Repetir la petición —o pedir un abono suelto que ya está dentro— da
        // los mismos recibos, sin número nuevo.
        $otra = $this->emitir('receivable_payment', $ids)->assertStatus(200)->json('data');
        $this->assertEqualsCanonicalizing(
            [$productos['uuid'], $gimnasio['uuid']],
            array_column($otra, 'uuid'),
        );
        $suelto = $this->emitir('receivable_payment', $ids[0])->assertStatus(200)->json('data');
        $this->assertCount(1, $suelto);
        $this->assertSame(2, Receipt::count());
        $this->assertSame(3, ReceiptSource::count());
        $this->assertSame($antes, $this->conteosDeNegocio());
    }

    public function test_source_ids_solo_vale_para_abonos(): void
    {
        $this->emitir('payment', [1, 2])->assertStatus(422);
        $this->postJson('/api/admin/receipts', ['source_type' => 'payment'], $this->h)->assertStatus(422);
        $this->postJson('/api/admin/receipts', ['source_type' => 'otra_cosa', 'source_id' => 1], $this->h)->assertStatus(422);
    }

    public function test_recepcion_emite_en_las_dos_cajas(): void
    {
        $this->assertSame(Admin::ROLE_RECEPCION, $this->cajero->role);

        $this->emitir('payment', $this->cobrar($this->socio(), $this->plan())->id)->assertStatus(201);
        $this->emitir('product_sale', $this->vender([['product_id' => $this->producto()->id, 'quantity' => 1]])->id)
            ->assertStatus(201);
    }
}
