<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Ninguna solicitud puede quedarse atascada en `processing`.
 *
 * Caso real que originó estas pruebas: la solicitud #18 (venta V-000003, $3.000)
 * quedó en `processing` para siempre. El job marcaba `processing` y sólo entonces
 * la barrera de emisión rechazaba el documento —la venta no tenía
 * `invoice_requested`—. Al reintentar, la guarda de idempotencia veía
 * `processing`, que no está en `canRetry()`, y hacía `return`. La cola daba el
 * job por bueno, `failed()` no se llamaba nunca y el estado terminal no se
 * escribía jamás: ni número, ni error, ni motivo. Los dos barridos existentes
 * tampoco la alcanzaban.
 *
 * El invariante que se fija aquí: si `processing` está escrito, o llega una
 * respuesta del proveedor, o queda un estado terminal con motivo. Nunca silencio.
 */
class StuckProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Configuración COMPLETA de producción: sin ella el job sale antes por
        // `FactusConfigValidator::isReadyForProduction()` y la solicitud se queda
        // en `pending`, que no es lo que estas pruebas quieren ejercitar.
        config([
            'billing.enabled' => true,
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
            'billing.numbering.range_id' => 2076,
            'billing.numbering.credit_range_id' => 2077,
            'billing.defaults.municipality_code' => '41001',
            'billing.company' => ['nit' => '1075265137', 'dv' => '1', 'name' => 'PAJOY MEDINA FREDY ALBERTO'],
            'billing.tax_decision_confirmed' => true,
            'tax_policy.emission_guard_enabled' => true,
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    private function sale(array $attrs = []): ProductSale
    {
        $rate = TaxRate::create([
            'code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido', 'rate' => 19,
            'price_includes_tax' => true, 'active' => true, 'factus_tribute_id' => '01',
        ]);
        $product = Product::create([
            'name' => 'Agua 600 ml', 'sale_price' => 3000, 'cost_price' => 1000,
            'stock' => 50, 'min_stock' => 1, 'active' => true,
            'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);

        $sale = ProductSale::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => 'V-'.Str::random(6),
            'channel' => 'pos',
            'status' => 'paid',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal' => '3000.00', 'discount' => '0.00', 'total' => '3000.00',
            'paid_at' => now(),
        ], $attrs));

        $sale->items()->create([
            'product_id' => $product->id, 'name' => $product->name,
            'unit_price' => 3000, 'quantity' => 1, 'subtotal' => 3000,
        ]);

        return $sale->fresh('items');
    }

    private function payment(array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'amount' => '3000.00',
            'status' => 'paid',
            'method' => 'efectivo',
            'paid_at' => now(),
        ], $attrs));
    }

    private function pendingInvoiceFor(ProductSale|Payment $source): ElectronicInvoice
    {
        return ElectronicInvoice::create([
            'uuid' => (string) Str::uuid(),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->id,
            'type' => 'invoice',
            'status' => 'pending',
            'currency' => 'COP',
            'subtotal' => '3000.00', 'discount' => '0.00',
            'tax_total' => '0.00', 'total' => '3000.00',
        ]);
    }

    // ── El botón no encola lo que está condenado ──────────────────────────

    public function test_una_venta_sin_solicitud_no_encola_nada(): void
    {
        Queue::fake();
        $sale = $this->sale(); // sin invoice_requested

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no fue creada con solicitud de factura/');

        try {
            app(InvoicingService::class)->manualEmit('product_sale', $sale->id);
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame(0, ElectronicInvoice::count());
        }
    }

    public function test_una_venta_con_solicitud_crea_una_sola_solicitud(): void
    {
        Queue::fake();
        $sale = $this->sale();
        $sale->marcarFacturaSolicitada('cliente.real@correo.com');

        app(InvoicingService::class)->manualEmit('product_sale', $sale->id);
        app(InvoicingService::class)->manualEmit('product_sale', $sale->id); // doble clic

        $this->assertSame(1, ElectronicInvoice::where('source_id', $sale->id)->count());
    }

    // ── La barrera actúa ANTES de marcar processing ───────────────────────

    public function test_la_barrera_rechaza_sin_dejar_la_solicitud_en_processing(): void
    {
        // Exactamente el caso #18: venta cobrada, real, pero sin solicitud.
        // La solicitud ya existe (la crea el hook de caja como evidencia) y el
        // job se ejecuta; debe terminar en `error` con el motivo, no en
        // `processing`.
        Http::fake();
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('no fue solicitada por el cliente', (string) $invoice->failure_reason);
        // Nada salió al proveedor y no se consumió consecutivo.
        Http::assertNothingSent();
        $this->assertNull($invoice->full_number);
        $this->assertNull($invoice->cufe);
    }

    public function test_desde_error_el_estado_sigue_siendo_reintentable(): void
    {
        // Es la diferencia que desatasca: `error` sí está en canRetry(), así que
        // el barrido y el reintento manual pueden retomarla cuando se corrija la
        // causa. `processing` no lo estaba, y ahí se quedaba.
        Http::fake();
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);

        app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);

        $this->assertTrue($invoice->fresh()->status->canRetry());
    }

    // ── Una excepción DESPUÉS de processing acaba en error ────────────────

    public function test_un_fallo_de_red_tras_marcar_processing_deja_error_y_relanza(): void
    {
        $sale = $this->sale();
        $sale->marcarFacturaSolicitada('cliente.real@correo.com');
        $invoice = $this->pendingInvoiceFor($sale);

        // Token OK y luego la red se cae en el POST.
        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*bills*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        try {
            app()->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);
            $this->fail('Debió relanzar la excepción técnica para que la cola reintente.');
        } catch (\Throwable) {
            // Esperado: un fallo técnico sí merece backoff.
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status, 'nunca debe quedarse en processing');
        $this->assertStringContainsString('Fallo al emitir', (string) $invoice->failure_reason);
        $this->assertNull($invoice->full_number);
    }

    public function test_failed_saca_la_solicitud_de_processing(): void
    {
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);
        $invoice->markProcessing();
        $this->assertSame(InvoiceStatus::PROCESSING, $invoice->fresh()->status);

        (new EmitElectronicInvoiceJob($invoice->id))->failed(new RuntimeException('proceso muerto'));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('Reintentos agotados', (string) $invoice->failure_reason);
    }

    public function test_failed_no_pisa_una_factura_ya_validada(): void
    {
        // Si el fallo ocurrió después de que Factus la aceptara, el documento
        // fiscal existe y su estado manda.
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);
        $invoice->markValidated(['full_number' => 'IBFE99', 'cufe' => 'abc123']);

        (new EmitElectronicInvoiceJob($invoice->id))->failed(new RuntimeException('ruido posterior'));

        $this->assertSame(InvoiceStatus::VALIDATED, $invoice->fresh()->status);
        $this->assertSame('IBFE99', $invoice->fresh()->full_number);
    }

    // ── Recuperación de processing huérfanos ──────────────────────────────

    public function test_el_comando_detecta_un_processing_antiguo_sin_numero(): void
    {
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);
        $invoice->markProcessing();
        $invoice->forceFill(['last_attempt_at' => now()->subHour()])->save();

        // Simulación: informa pero no escribe.
        $this->artisan('billing:recover-stuck-processing --minutes=15')
            ->expectsOutputToContain('SIMULACIÓN')
            ->assertExitCode(0);
        $this->assertSame(InvoiceStatus::PROCESSING, $invoice->fresh()->status);

        // Con --confirm pasa a error con motivo.
        $this->artisan('billing:recover-stuck-processing --minutes=15 --confirm')
            ->assertExitCode(0);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('sin respuesta del proveedor', (string) $invoice->failure_reason);
        $this->assertTrue($invoice->status->canRetry());
    }

    public function test_el_comando_no_toca_un_processing_reciente(): void
    {
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);
        $invoice->markProcessing(); // last_attempt_at = ahora

        $this->artisan('billing:recover-stuck-processing --minutes=15 --confirm')
            ->expectsOutputToContain('No hay solicitudes colgadas')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PROCESSING, $invoice->fresh()->status);
    }

    public function test_el_comando_nunca_toca_una_solicitud_con_numero_o_cufe(): void
    {
        // Con número o CUFE puede haber un documento fiscal REAL detrás. Eso se
        // reconcilia contra Factus, no se reescribe a mano.
        $sale = $this->sale();
        $conNumero = $this->pendingInvoiceFor($sale);
        $conNumero->markProcessing();
        $conNumero->forceFill([
            'last_attempt_at' => now()->subDay(),
            'full_number' => 'IBFE9',
            'cufe' => 'cufe-real',
        ])->save();

        $this->artisan('billing:recover-stuck-processing --minutes=15 --confirm')
            ->expectsOutputToContain('No hay solicitudes colgadas')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PROCESSING, $conNumero->fresh()->status);
        $this->assertSame('IBFE9', $conNumero->fresh()->full_number);
    }

    public function test_el_comando_puede_rescatar_una_sola_por_id(): void
    {
        $a = $this->pendingInvoiceFor($this->sale());
        $b = $this->pendingInvoiceFor($this->payment());
        foreach ([$a, $b] as $i) {
            $i->markProcessing();
            $i->forceFill(['last_attempt_at' => now()->subHour()])->save();
        }

        $this->artisan("billing:recover-stuck-processing --id={$a->id} --confirm")->assertExitCode(0);

        $this->assertSame(InvoiceStatus::ERROR, $a->fresh()->status);
        $this->assertSame(InvoiceStatus::PROCESSING, $b->fresh()->status, 'sólo la indicada');
    }

    public function test_el_rescate_queda_registrado_en_la_bitacora(): void
    {
        $invoice = $this->pendingInvoiceFor($this->sale());
        $invoice->markProcessing();
        $invoice->forceFill(['last_attempt_at' => now()->subHour()])->save();

        $this->artisan('billing:recover-stuck-processing --minutes=15 --confirm')->assertExitCode(0);

        $this->assertDatabaseHas('electronic_invoice_logs', [
            'electronic_invoice_id' => $invoice->id,
            'result' => 'error',
        ]);
        $log = \Illuminate\Support\Facades\DB::table('electronic_invoice_logs')
            ->where('electronic_invoice_id', $invoice->id)->latest('id')->first();
        $this->assertStringContainsString('recover-stuck-processing', (string) $log->message);
    }

    // ── Cancelación de una venta real que nadie pidió facturar ────────────

    public function test_una_pendiente_de_venta_real_sin_solicitud_exige_el_flag(): void
    {
        // Sin --not-requested el comando se niega: no debe poder cancelar
        // documentos reales por descuido.
        $invoice = $this->pendingInvoiceFor($this->sale()); // invoice_requested=false

        $this->artisan("billing:cancel-test-requests --ids={$invoice->id}")
            ->expectsOutputToContain('venta real sin solicitud del cliente')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PENDING, $invoice->fresh()->status);
    }

    public function test_con_el_flag_la_pendiente_sin_solicitud_queda_cancelada(): void
    {
        Http::fake();
        Queue::fake();
        $sale = $this->sale();
        $invoice = $this->pendingInvoiceFor($sale);

        $this->artisan("billing:cancel-test-requests --ids={$invoice->id} --not-requested"
            .' --reason="La venta no fue creada con solicitud de factura electronica"')
            ->assertExitCode(0);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::CANCELLED, $invoice->status);
        $this->assertFalse((bool) $invoice->retry_allowed, 'el reintento debe quedar deshabilitado');
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertStringContainsString('no fue creada con solicitud', (string) $invoice->cancellation_reason);
        // Nada se emitió y la venta no se tocó.
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertFalse((bool) $sale->fresh()->invoice_requested);
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_la_bitacora_de_la_cancelacion_no_dice_que_fue_de_prueba(): void
    {
        // Una venta real sin solicitud NO es un pago de sandbox: confundirlo en
        // la bitácora engañaría a quien audite el documento después.
        $invoice = $this->pendingInvoiceFor($this->sale());

        $this->artisan("billing:cancel-test-requests --ids={$invoice->id} --not-requested")
            ->assertExitCode(0);

        $log = \Illuminate\Support\Facades\DB::table('electronic_invoice_logs')
            ->where('electronic_invoice_id', $invoice->id)->where('action', 'cancel')->latest('id')->first();

        $this->assertStringContainsString('NO solicitó factura', (string) $log->message);
        $this->assertStringNotContainsString('sandbox', (string) $log->message);
    }

    public function test_el_flag_no_alcanza_a_una_venta_que_si_pidio_factura(): void
    {
        // La condición es verificable en los datos, no un override: si el
        // cliente sí la pidió, el flag no la vuelve cancelable por esta vía.
        $sale = $this->sale();
        $sale->marcarFacturaSolicitada('cliente.real@correo.com');
        $invoice = $this->pendingInvoiceFor($sale->fresh());

        $this->artisan("billing:cancel-test-requests --ids={$invoice->id} --not-requested")
            ->expectsOutputToContain('no es de sandbox: requiere decisión manual')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PENDING, $invoice->fresh()->status);
    }

    public function test_nunca_cancela_una_solicitud_con_numero_o_cufe(): void
    {
        // Con número asignado: el documento fiscal puede existir de verdad.
        $conNumero = $this->pendingInvoiceFor($this->sale());
        $conNumero->forceFill(['full_number' => 'IBFE9'])->save();

        $this->artisan("billing:cancel-test-requests --ids={$conNumero->id} --not-requested")
            ->expectsOutputToContain('ya tiene número')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PENDING, $conNumero->fresh()->status);
        $this->assertSame('IBFE9', $conNumero->fresh()->full_number);

        // Con CUFE: idem, y se avisa por el CUFE, que es la prueba más fuerte.
        $conCufe = $this->pendingInvoiceFor($this->payment());
        $conCufe->forceFill(['cufe' => 'cufe-real'])->save();

        $this->artisan("billing:cancel-test-requests --ids={$conCufe->id} --not-requested")
            ->expectsOutputToContain('ya tiene CUFE')
            ->assertExitCode(0);

        $this->assertSame(InvoiceStatus::PENDING, $conCufe->fresh()->status);
    }

    public function test_el_comando_no_reintenta_ni_emite(): void
    {
        Queue::fake();
        Http::fake();
        $invoice = $this->pendingInvoiceFor($this->sale());
        $invoice->markProcessing();
        $invoice->forceFill(['last_attempt_at' => now()->subHour()])->save();

        $this->artisan('billing:recover-stuck-processing --minutes=15 --confirm')->assertExitCode(0);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
