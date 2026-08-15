<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\ManualEmissionRejectedException;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use App\Services\Billing\PaymentOriginInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Emisión ADMINISTRATIVA posterior: facturar un pago ya cobrado que en su día
 * no pidió factura.
 *
 * Existe porque la regla anterior —«sin `invoice_requested` no se emite
 * nunca»— mezclaba un hecho histórico («el cliente pidió factura al comprar»)
 * con una decisión revisable («debe emitirse este documento»). En `/payments`
 * ni siquiera había casilla que marcar, así que ningún pago de membresía podía
 * facturarse jamás: el mensaje de error pedía algo que la interfaz no ofrecía.
 *
 * Lo que estas pruebas fijan es la frontera: la autorización administrativa
 * sustituye a la solicitud del cliente y NADA MÁS. Cobro real, duplicados,
 * ambiente, trazabilidad y conciliación siguen bloqueando igual.
 */
class AdministrativeEmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.auto_emit.memberships' => false,
            'billing.credentials' => [
                'username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's',
            ],
            // Consumidor final tal y como está configurado en producción.
            'billing.consumer_final' => [
                'document_type' => '3',
                'document_number' => '222222222222',
                'name' => 'CONSUMIDOR FINAL',
            ],
        ]);
    }

    // ── Montaje ───────────────────────────────────────────────────────────

    private function payment(array $attrs = []): Payment
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return Payment::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount' => 80000,
            // Cobro de mostrador con recibo interno: exactamente el caso del
            // pago #1015 en producción.
            'method' => 'cash',
            'reference' => 'REC-'.random_int(1000, 9999),
            'status' => 'paid',
            'paid_at' => now(),
        ], $attrs));
    }

    private function fiscalProfileFor(Payment $payment, array $attrs = []): FiscalProfile
    {
        return FiscalProfile::create(array_merge([
            'user_id' => $payment->user_id,
            'doc_type' => 'CC',
            'doc_number' => '1075265137',
            'legal_name' => 'CLIENTE NOMINATIVO SAS',
            'email' => 'cliente.nominativo@correo.com',
        ], $attrs));
    }

    private function transactionFor(Payment $payment, array $attrs = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'reference' => $payment->reference,
            'idempotency_key' => (string) Str::uuid(),
            'amount' => $payment->amount,
            'currency' => 'COP',
            'status' => 'approved',
            'provider' => 'wompi',
            'environment' => 'production',
            'card_last_four' => '1234',
            'metadata' => ['wants_invoice' => false],
        ], $attrs));
    }

    /** @return array<string,mixed> */
    private function inspect(Payment $payment): array
    {
        return app(PaymentOriginInspector::class)->inspectSource($payment->fresh());
    }

    // ── 1-2. La vía administrativa abre lo que estaba cerrado ─────────────

    /** Escenario 1: PAGADO + invoice_requested=false + sin factura → emite. */
    public function test_1_pago_pagado_sin_solicitud_puede_emitirse(): void
    {
        Queue::fake();
        $payment = $this->payment();

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, adminId: 7);

        $this->assertNotNull($invoice->manual_authorization_at);
        $this->assertSame(7, $invoice->created_by_admin_id);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);

        // El dato histórico no se falsea: el cliente NO la pidió al comprar.
        $this->assertFalse((bool) $payment->fresh()->invoice_requested);
        $this->assertNull($payment->fresh()->invoice_requested_at);
    }

    /** Escenario 2: PAGADO + invoice_requested=true + sin factura → emite igual. */
    public function test_2_pago_pagado_con_solicitud_puede_emitirse(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $payment->marcarFacturaSolicitada('cliente.real@correo.com');

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id);

        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
        $this->assertTrue((bool) $payment->fresh()->invoice_requested);
    }

    // ── 3-6. Lo que sigue bloqueado ───────────────────────────────────────

    /** Escenario 3: con documento fiscal ya emitido no se reemite (409). */
    public function test_3_un_pago_ya_facturado_no_se_reemite(): void
    {
        Queue::fake();
        $payment = $this->payment();
        ElectronicInvoice::create([
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => 'validated',
            'full_number' => 'IBFE42',
            'cufe' => 'CUFE-REAL',
            'total' => '80000.00',
        ]);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString('IBFE42', $e->getMessage());
        Queue::assertNothingPushed();
    }

    /** Escenario 4: un pago cancelado no se factura. */
    public function test_4_un_pago_cancelado_no_se_factura(): void
    {
        Queue::fake();
        $payment = $this->payment(['status' => 'cancelled']);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertSame(422, $e->status);
        $this->assertStringContainsString('cancelled', $e->getMessage());
        $this->assertSame(0, ElectronicInvoice::count(), 'no debe dejar una solicitud huérfana');
        Queue::assertNothingPushed();
    }

    /** Escenario 5: un pago fallido tampoco. */
    public function test_5_un_pago_fallido_no_se_factura(): void
    {
        Queue::fake();
        $payment = $this->payment(['status' => 'failed']);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertSame(422, $e->status);
        $this->assertSame(0, ElectronicInvoice::count());
        Queue::assertNothingPushed();
    }

    /** Escenario 6: con una emisión en curso no se lanza otra (409). */
    public function test_6_una_emision_en_curso_bloquea_otra(): void
    {
        Queue::fake();
        $payment = $this->payment();
        ElectronicInvoice::create([
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => 'processing',
            'total' => '80000.00',
        ]);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertSame(409, $e->status);
        $this->assertStringContainsString('en curso', $e->getMessage());
        Queue::assertNothingPushed();
    }

    // ── 7-9. Adquiriente: nominativa vs consumidor final ──────────────────

    /** Escenario 7: con perfil fiscal utilizable, la factura es NOMINATIVA. */
    public function test_7_perfil_fiscal_completo_produce_factura_nominativa(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $this->fiscalProfileFor($payment);

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: false);

        $this->assertFalse((bool) $invoice->is_final_consumer);
        $this->assertSame('1075265137', $invoice->customer_doc_number);
        $this->assertSame('CLIENTE NOMINATIVO SAS', $invoice->customer_name);
    }

    /** Escenario 8: sin perfil + consumidor final → el tercero configurado. */
    public function test_8_sin_perfil_fiscal_con_consumidor_final_usa_la_configuracion(): void
    {
        Queue::fake();
        $payment = $this->payment();

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true);

        $this->assertTrue((bool) $invoice->is_final_consumer);
        $this->assertSame('3', $invoice->customer_doc_type);
        $this->assertSame('222222222222', $invoice->customer_doc_number);
        $this->assertSame('CONSUMIDOR FINAL', $invoice->customer_name);
    }

    /** Escenario 9: sin perfil y sin consumidor final → error con el detalle. */
    public function test_9_sin_perfil_fiscal_y_sin_consumidor_final_falla_con_detalle(): void
    {
        Queue::fake();
        Http::fake();
        $payment = $this->payment();

        $e = $this->assertRechaza(
            fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: false)
        );

        $this->assertSame(422, $e->status);
        $this->assertStringContainsString('no tiene perfil fiscal', $e->getMessage());
        $this->assertSame(0, ElectronicInvoice::count(), 'no se crea nada con datos fiscales incompletos');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /**
     * Y el caso que motivó todo esto: un perfil que `isComplete()` daba por
     * bueno pero cuyo NIT está guardado con espacios. En producción existe uno
     * así («9 0 1 4 9 9 7 4 2») y su payload congelado apuntaba a él.
     */
    public function test_9b_un_documento_con_espacios_no_se_da_por_valido(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $this->fiscalProfileFor($payment, ['doc_type' => 'NIT', 'doc_number' => '9 0 1 4 9 9 7 4 2']);

        $e = $this->assertRechaza(
            fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: false)
        );

        $this->assertStringContainsString('espacios', $e->getMessage());

        // Sin decisión expresa, la política automática degrada a consumidor
        // final en vez de mandar el documento roto a la DIAN.
        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id);
        $this->assertTrue((bool) $invoice->is_final_consumer);
    }

    // ── 10-12. Trazabilidad del origen ────────────────────────────────────

    /** Escenario 10: pago manual con recibo interno y sin pasarela → trazable. */
    public function test_10_pago_manual_con_recibo_interno_es_trazable(): void
    {
        Queue::fake();
        $payment = $this->payment(['method' => 'cash', 'reference' => 'REC-1001']);

        $this->assertTrue(
            $this->inspect($payment)['has_verifiable_reference'],
            'un REC-* es el registro de caja, no una referencia de pasarela huérfana'
        );

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
    }

    /** Escenario 11: método de pasarela sin transacción → sigue bloqueado. */
    public function test_11_pago_de_pasarela_sin_transaccion_sigue_bloqueado(): void
    {
        Queue::fake();
        $payment = $this->payment(['method' => 'wompi', 'reference' => 'WOMPI-FANTASMA']);

        $this->assertFalse($this->inspect($payment)['has_verifiable_reference']);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertSame(422, $e->status);
        $this->assertStringContainsString('referencia verificable', $e->getMessage());
        $this->assertSame(0, ElectronicInvoice::count());
        Queue::assertNothingPushed();
    }

    /** Escenario 12: pago de pasarela con su transacción → permitido. */
    public function test_12_pago_de_pasarela_con_transaccion_es_trazable(): void
    {
        Queue::fake();
        $payment = $this->payment(['method' => 'wompi', 'reference' => 'WOMPI-REAL-1']);
        $this->transactionFor($payment);

        $this->assertTrue($this->inspect($payment)['has_verifiable_reference']);

        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
    }

    /** Las importaciones del sistema anterior siguen sin ser verificables. */
    public function test_12b_una_referencia_migrada_nunca_es_trazable(): void
    {
        Queue::fake();
        $payment = $this->payment(['method' => 'cash', 'reference' => 'MIGR-000123']);

        $this->assertFalse($this->inspect($payment)['has_verifiable_reference']);

        $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));
        Queue::assertNothingPushed();
    }

    /** Un pago de sandbox no movió dinero: no se factura ni autorizado. */
    public function test_12c_un_pago_de_sandbox_no_se_factura(): void
    {
        Queue::fake();
        $payment = $this->payment(['method' => 'wompi', 'reference' => 'WOMPI-SANDBOX-1']);
        $this->transactionFor($payment, ['environment' => 'sandbox', 'card_last_four' => '4242']);

        $e = $this->assertRechaza(fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id));

        $this->assertStringContainsString('sandbox', $e->getMessage());
        Queue::assertNothingPushed();
    }

    // ── 13. Idempotencia ──────────────────────────────────────────────────

    /** Escenario 13: dos clics seguidos → una solicitud y un solo despacho. */
    public function test_13_doble_clic_no_duplica_la_emision(): void
    {
        Queue::fake();
        $payment = $this->payment();

        $a = app(InvoicingService::class)->manualEmit('payment', $payment->id);
        $b = app(InvoicingService::class)->manualEmit('payment', $payment->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);

        // La fecha de autorización es la de la PRIMERA: un segundo clic no la mueve.
        $this->assertTrue($a->manual_authorization_at->equalTo($b->fresh()->manual_authorization_at));
    }

    // ── 16-17. El flujo automático no se contagia ─────────────────────────

    /** Escenario 16: con solicitud del cliente, el automático sigue igual. */
    public function test_16_el_hook_automatico_sigue_emitiendo_con_solicitud(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $payment->marcarFacturaSolicitada('cliente.real@correo.com');

        app(InvoicingService::class)->enqueueForPayment($payment->fresh(), force: true);

        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
    }

    /** Escenario 17: sin solicitud, el automático sigue SIN emitir. */
    public function test_17_el_hook_automatico_no_emite_sin_solicitud(): void
    {
        Queue::fake();
        $payment = $this->payment();

        $invoice = app(InvoicingService::class)->enqueueForPayment($payment, force: false);

        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertNull($invoice->manual_authorization_at);
        Queue::assertNothingPushed();
    }

    // ── 18. Snapshot congelado y decisión actual ──────────────────────────

    /**
     * Escenario 18: una solicitud pendiente creada en su día con el adquiriente
     * equivocado debe emitirse con la decisión de AHORA, no con la de entonces.
     *
     * Es literalmente el caso del pago #1015: su solicitud pendiente apunta a
     * una empresa cuyo NIT está guardado con espacios. Cambiar la casilla del
     * modal no bastaba: el payload estaba congelado.
     */
    public function test_18_el_adquiriente_congelado_se_corrige_antes_de_emitir(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $this->fiscalProfileFor($payment, ['doc_type' => 'NIT', 'doc_number' => '901499742', 'legal_name' => 'EMPRESA VIEJA SAS']);

        // El hook de cobro congela la factura a nombre de la empresa.
        $congelada = app(InvoicingService::class)->enqueueForPayment($payment, force: false);
        $this->assertFalse((bool) $congelada->is_final_consumer);
        $this->assertSame('901499742', $congelada->customer_doc_number);

        // El operador decide consumidor final en el modal.
        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true);

        $this->assertSame($congelada->id, $invoice->id, 'no se crea una segunda solicitud');
        $this->assertTrue((bool) $invoice->is_final_consumer);
        $this->assertSame('222222222222', $invoice->customer_doc_number);
        // El payload que saldrá a Factus lleva la decisión nueva, no la vieja.
        $this->assertSame('222222222222', $invoice->payload_snapshot['customer']['identification'] ?? null);
        // Y el importe no se movió ni un peso.
        $this->assertSame('80000.00', (string) $invoice->total);
        $this->assertSame('80000.00', (string) $invoice->source_amount_snapshot);
    }

    /** Un documento ya transmitido NUNCA se reescribe, decida lo que decida nadie. */
    public function test_18b_un_documento_ya_emitido_no_se_reescribe(): void
    {
        Queue::fake();
        $payment = $this->payment();
        ElectronicInvoice::create([
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => 'validated',
            'full_number' => 'IBFE50',
            'cufe' => 'CUFE-EMITIDO',
            'is_final_consumer' => false,
            'customer_doc_number' => '901499742',
            'customer_name' => 'EMPRESA VIEJA SAS',
            'total' => '80000.00',
        ]);

        $this->assertRechaza(
            fn () => app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true)
        );

        $emitida = ElectronicInvoice::where('source_id', $payment->id)->sole();
        $this->assertSame('901499742', $emitida->customer_doc_number);
        $this->assertSame('EMPRESA VIEJA SAS', $emitida->customer_name);
    }

    // ── 14-15. Extremo a extremo, con la barrera de producción ENCENDIDA ──

    /**
     * Escenario 15: el camino completo del pago #1015 con la barrera activa.
     *
     * Es la prueba que importa: pago en efectivo con recibo interno, sin
     * solicitud del cliente, autorizado por un administrador. Antes moría tres
     * veces (422 del servicio, `wants_invoice` del guard y trazabilidad). Aquí
     * llega a Factus y persiste lo que un documento fiscal debe dejar escrito.
     */
    public function test_15_exito_de_factus_persiste_cufe_numero_y_archivos(): void
    {
        $this->conBarreraDeProduccion();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            // Forma real de la respuesta productiva: `number` ya trae el número
            // fiscal completo (así se guardaron IBFE1…IBFE9), no el consecutivo suelto.
            '*/v2/bills/validate' => Http::response(['data' => ['bill' => [
                'id' => 'F-1015', 'number' => 'IBFE10', 'prefix' => 'IBFE',
                'cufe' => 'cufe-1015-demo', 'status' => 'Validada',
            ]]], 201),
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
            '*' => Http::response([], 200),
        ]);
        Queue::fake(); // el job se ejecuta a mano, sin cola

        $payment = $this->payment(['method' => 'cash', 'reference' => 'REC-1001']);
        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, adminId: 1, finalConsumer: true);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::VALIDATED, $invoice->status);
        $this->assertSame('cufe-1015-demo', $invoice->cufe);
        $this->assertSame('IBFE10', $invoice->number);
        $this->assertSame('IBFE10', $invoice->full_number);
        $this->assertNotNull($invoice->validated_at);
        $this->assertNotNull($invoice->pdf_path);
        $this->assertNotNull($invoice->xml_path);
        // Y la constancia de por qué se pudo emitir sigue ahí.
        $this->assertNotNull($invoice->manual_authorization_at);
        $this->assertSame(1, $invoice->created_by_admin_id);
    }

    /** Escenario 14: si Factus falla, la factura NO queda marcada como emitida. */
    public function test_14_un_error_de_factus_no_marca_la_factura_como_emitida(): void
    {
        $this->conBarreraDeProduccion();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'Datos inválidos'], 422),
            '*' => Http::response([], 200),
        ]);
        Queue::fake();

        $payment = $this->payment(['method' => 'cash', 'reference' => 'REC-1002']);
        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REJECTED, $invoice->status);
        $this->assertNull($invoice->cufe);
        $this->assertNull($invoice->full_number);
        $this->assertNotNull($invoice->failure_reason);
    }

    /**
     * Y la barrera sigue parando lo que debe: el mismo montaje, pero sin
     * autorización administrativa ni solicitud del cliente.
     */
    public function test_14b_sin_autorizacion_la_barrera_sigue_parando_la_emision(): void
    {
        $this->conBarreraDeProduccion();
        Http::fake();
        Queue::fake();

        $payment = $this->payment(['method' => 'cash', 'reference' => 'REC-1003']);
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment, force: false);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('ni autorizada por un administrador', (string) $invoice->failure_reason);
        Http::assertNothingSent();
    }

    /**
     * El guardarraíl tributario sobre el TOTAL debe seguir vivo en la ruta del
     * payload congelado, que ahora es la normal.
     *
     * Leía `$built['snapshot']['tax_total'] ?? 0`, y `$built` sólo se define en
     * la rama que reconstruye el payload. Con snapshot congelado la variable no
     * existía, el operando caía al `?? 0` y la comprobación pasaba siempre.
     * Nunca dio error: simplemente dejó de comprobar.
     */
    public function test_el_guardarrail_tributario_ve_el_impuesto_congelado(): void
    {
        $this->conBarreraDeProduccion();
        Http::preventStrayRequests();
        Queue::fake();

        $payment = $this->payment(['method' => 'cash', 'reference' => 'REC-1004']);
        $invoice = app(InvoicingService::class)->manualEmit('payment', $payment->id, finalConsumer: true);

        // Un comprobante con IVA, emisor no responsable: debe abortar antes de
        // tocar la red, no colarse por una variable indefinida.
        $invoice->forceFill(['tax_total' => '15200.00'])->save();

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('IVA', (string) $invoice->failure_reason);
        $this->assertNull($invoice->cufe);
    }

    // ── Utilidad ──────────────────────────────────────────────────────────

    /**
     * Misma configuración que el servidor productivo: barrera encendida y
     * `isReadyForProduction()` satisfecho, porque si no el job se planta antes
     * de llegar a las barreras y la prueba no probaría nada.
     */
    private function conBarreraDeProduccion(): void
    {
        config([
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.numbering.prefix' => 'IBFE',
            'billing.numbering.range_id' => 2076,
            'billing.numbering.credit_range_id' => 2077,
            'billing.defaults.municipality_code' => '41001',
            'billing.company.nit' => '1075265137',
            'billing.company.dv' => '1',
            'billing.company.name' => 'EMISOR DE PRUEBA',
            'billing.tax_decision_confirmed' => true,
            'tax_policy.emission_guard_enabled' => true,
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    private function assertRechaza(callable $accion): ManualEmissionRejectedException
    {
        try {
            $accion();
        } catch (ManualEmissionRejectedException $e) {
            return $e;
        }

        $this->fail('Se esperaba ManualEmissionRejectedException y no se lanzó.');
    }
}
