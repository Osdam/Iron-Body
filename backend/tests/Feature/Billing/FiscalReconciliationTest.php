<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\InvoiceFiscalReconciliation;
use App\Services\Billing\FiscalReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La fuente de verdad fiscal de un documento validado es el proveedor
 * (Factus/DIAN), no las columnas locales.
 *
 * Origen de estas pruebas: `billing:tax-audit` auditaba
 * `electronic_invoices.tax_total` y daba «correcta» a la IBFE1, que figura
 * localmente con IVA 0,00 mientras el documento validado ante la DIAN
 * discrimina 12.773,11 (19 %). Un falso negativo sobre un documento legal.
 */
class FiscalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'billing.enabled' => true,
            'billing.base_url' => 'https://api-sandbox.factus.test',
            'billing.tax_decision_confirmed' => false, // La emisión sigue bloqueada.
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    private function invoice(array $attributes = []): ElectronicInvoice
    {
        return ElectronicInvoice::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'source_type' => 'payment',
            'source_id' => random_int(1, 999999),
            'type' => 'invoice',
            'status' => 'validated',
            'full_number' => 'IBFE1',
            'currency' => 'COP',
            'subtotal' => '80000.00',
            'discount' => '0.00',
            'tax_total' => '0.00',
            'total' => '80000.00',
        ], $attributes));
    }

    /** Respuesta del proveedor con la forma real observada en la API V2. */
    private function fakeProvider(array $overrides = []): void
    {
        $data = array_merge([
            'number' => 'IBFE1',
            'cufe' => str_repeat('a', 96),
            'is_validated' => true,
            'validated_at' => '26-06-2026 01:12:31 PM',
            'totals' => [
                'taxable_amount' => '67226.89',
                'tax_amount' => '12773.11',
                'total' => '80000.00',
            ],
            'taxes' => [[
                'tribute' => ['code' => '01', 'name' => 'IVA'],
                'is_excluded' => false,
                'rates' => [['taxable_amount' => '67226.89', 'tax_amount' => '12773.11', 'rate' => '19.00']],
            ]],
            // Datos que NO deben acabar almacenados.
            'customer' => ['names' => 'Persona Real', 'identification' => '1234567890', 'email' => 'x@y.co'],
            'company' => ['nit' => '1075265137'],
            'links' => ['public_url' => 'https://factus.test/x'],
        ], $overrides);

        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => $data], 200),
        ]);
    }

    // ── 1. Local 0 / proveedor positivo → mismatch ────────────────────────

    public function test_local_sin_iva_y_proveedor_con_iva_es_discrepancia(): void
    {
        $this->fakeProvider();
        $invoice = $this->invoice(['tax_total' => '0.00', 'subtotal' => '80000.00']);

        $r = app(FiscalReconciliationService::class)->reconcile($invoice);

        $this->assertSame(InvoiceFiscalReconciliation::STATUS_MISMATCH, $r->reconciliation_status);
        $this->assertTrue($r->isMismatch());
        $this->assertSame('12773.11', (string) $r->provider_tax_amount);
        $this->assertSame('0.00', (string) $r->local_tax_total);
        $this->assertSame('19.00', (string) $r->provider_rate);

        // La diferencia queda explícita y cuantificada.
        $this->assertArrayHasKey('iva', $r->differences);
        $this->assertSame('12773.11', $r->differences['iva']['diferencia']);
    }

    public function test_el_registro_local_no_se_modifica(): void
    {
        // Se documenta la divergencia; no se tocan los libros.
        $this->fakeProvider();
        $invoice = $this->invoice(['tax_total' => '0.00']);

        app(FiscalReconciliationService::class)->reconcile($invoice);

        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->tax_total);
        $this->assertSame('80000.00', (string) $invoice->subtotal);
    }

    // ── 2. Iguales → reconciled ───────────────────────────────────────────

    public function test_local_y_proveedor_coincidentes_es_conciliada(): void
    {
        $this->fakeProvider();
        $invoice = $this->invoice([
            'subtotal' => '67226.89',
            'tax_total' => '12773.11',
            'total' => '80000.00',
        ]);

        $r = app(FiscalReconciliationService::class)->reconcile($invoice);

        // Los importes casan; la tarifa positiva se sigue señalando como hallazgo.
        $this->assertNull($r->differences['iva'] ?? null);
        $this->assertArrayHasKey('tarifa_declarada', $r->differences ?? []);
    }

    public function test_sin_iva_en_ninguno_de_los_dos_lados_es_conciliada(): void
    {
        $this->fakeProvider([
            'totals' => ['taxable_amount' => '80000.00', 'tax_amount' => '0.00', 'total' => '80000.00'],
            'taxes' => [],
        ]);
        $invoice = $this->invoice(['subtotal' => '80000.00', 'tax_total' => '0.00', 'total' => '80000.00']);

        $r = app(FiscalReconciliationService::class)->reconcile($invoice);

        $this->assertSame(InvoiceFiscalReconciliation::STATUS_RECONCILED, $r->reconciliation_status);
        $this->assertTrue($r->isReconciled());
        $this->assertNull($r->differences);
    }

    // ── 3. Proveedor no disponible → unavailable, nunca «correcto» ────────

    public function test_si_el_proveedor_falla_queda_sin_evidencia(): void
    {
        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $r = app(FiscalReconciliationService::class)->reconcile($this->invoice());

        $this->assertSame(InvoiceFiscalReconciliation::STATUS_UNAVAILABLE, $r->reconciliation_status);
        $this->assertFalse($r->isReconciled());
        $this->assertStringContainsString('500', (string) $r->unavailable_reason);
        $this->assertNull($r->provider_tax_amount);
    }

    // ── 4. Pending: no se consulta como validada ──────────────────────────

    public function test_una_factura_pendiente_no_se_consulta_al_proveedor(): void
    {
        Http::fake();

        $invoice = $this->invoice(['status' => 'pending', 'full_number' => null]);
        $r = app(FiscalReconciliationService::class)->reconcile($invoice);

        $this->assertSame(InvoiceFiscalReconciliation::STATUS_UNAVAILABLE, $r->reconciliation_status);
        $this->assertStringContainsString('pending', (string) $r->unavailable_reason);
        Http::assertNothingSent();
    }

    // ── 5. Rejected sin CUFE: no se trata como emitida ────────────────────

    public function test_una_factura_rechazada_no_se_trata_como_emitida(): void
    {
        Http::fake();

        $invoice = $this->invoice(['status' => 'rejected', 'full_number' => null]);
        $r = app(FiscalReconciliationService::class)->reconcile($invoice);

        $this->assertSame(InvoiceFiscalReconciliation::STATUS_UNAVAILABLE, $r->reconciliation_status);
        $this->assertNull($r->provider_cufe);
        $this->assertNull($r->provider_is_validated);
        Http::assertNothingSent();
    }

    // ── 6. La suma del IVA se basa en el proveedor ────────────────────────

    public function test_la_suma_del_iva_sale_del_proveedor_no_del_local(): void
    {
        $this->fakeProvider();

        $total = 0;
        foreach ([['0.00', 'IBFE1'], ['12773.11', 'IBFE3']] as [$localTax, $number]) {
            $invoice = $this->invoice(['tax_total' => $localTax, 'full_number' => $number]);
            $total += app(FiscalReconciliationService::class)->reconcile($invoice)->providerTaxCents();
        }

        // Ambas discriminan 12.773,11 ante la DIAN, aunque una diga 0 en local.
        $this->assertSame(2554622, $total);
        $this->assertNotSame(1277311, $total, 'Sumar el local habría ocultado la IBFE1.');
    }

    // ── 7. Cero exposición de secretos ni datos personales ────────────────

    public function test_el_snapshot_no_guarda_secretos_ni_datos_personales(): void
    {
        $this->fakeProvider();

        $r = app(FiscalReconciliationService::class)->reconcile($this->invoice());
        $json = json_encode($r->provider_snapshot);

        foreach (['Persona Real', '1234567890', 'x@y.co', 'customer', 'company', 'links'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $json, "No debe almacenarse: {$forbidden}");
        }

        // Lo fiscal sí se conserva.
        $this->assertArrayHasKey('totals', $r->provider_snapshot);
        $this->assertArrayHasKey('taxes', $r->provider_snapshot);
        $this->assertSame(64, strlen((string) $r->provider_payload_hash));
    }

    public function test_la_reconciliacion_es_append_only(): void
    {
        $this->fakeProvider();
        $invoice = $this->invoice();

        $service = app(FiscalReconciliationService::class);
        $service->reconcile($invoice, actor: 'primera');
        $service->reconcile($invoice, actor: 'segunda');

        // Dos consultas, dos filas: la historia de la divergencia se conserva.
        $this->assertSame(2, InvoiceFiscalReconciliation::where('electronic_invoice_id', $invoice->id)->count());
        $this->assertSame(
            ['primera', 'segunda'],
            InvoiceFiscalReconciliation::orderBy('id')->pluck('actor')->all(),
        );
    }

    // ── El comando billing:tax-audit ──────────────────────────────────────

    public function test_el_comando_falla_cuando_hay_discrepancias(): void
    {
        $this->fakeProvider();
        $this->invoice(['tax_total' => '0.00']); // El caso IBFE1.

        // Código distinto de cero: una discrepancia fiscal no puede pasar por
        // ejecución correcta en un cron o en CI.
        $this->artisan('billing:tax-audit --audit')
            ->assertExitCode(1);
    }

    public function test_el_comando_termina_bien_cuando_todo_concilia(): void
    {
        $this->fakeProvider([
            'totals' => ['taxable_amount' => '80000.00', 'tax_amount' => '0.00', 'total' => '80000.00'],
            'taxes' => [],
        ]);
        $this->invoice(['subtotal' => '80000.00', 'tax_total' => '0.00', 'total' => '80000.00']);

        $this->artisan('billing:tax-audit --audit')->assertExitCode(0);
    }

    public function test_el_comando_no_altera_los_importes_locales(): void
    {
        $this->fakeProvider();
        $invoice = $this->invoice(['tax_total' => '0.00']);

        $this->artisan('billing:tax-audit --audit');

        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->tax_total);
        $this->assertSame('80000.00', (string) $invoice->subtotal);
        $this->assertSame('80000.00', (string) $invoice->total);
    }

    public function test_sincronizar_importes_requiere_aprobacion_del_contador(): void
    {
        // El camino de escritura existe pero está cerrado, y es una opción
        // DISTINTA de --audit para que nadie lo dispare por inercia.
        $this->artisan('billing:tax-audit --apply-provider-values')
            ->expectsOutputToContain('aprobación escrita del contador')
            ->assertExitCode(1);
    }

    public function test_la_lectura_no_emite_ningun_documento(): void
    {
        // La reconciliación corre con la emisión bloqueada y solo usa GET.
        $this->fakeProvider();

        app(FiscalReconciliationService::class)->reconcile($this->invoice());

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'bills/validate'));
    }
}
