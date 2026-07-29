<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\SandboxProbe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Barreras que deciden QUÉ puede convertirse en documento fiscal.
 *
 * Cada regla nace de un hecho verificado, no de una hipótesis: se emitieron
 * documentos reales ante la DIAN (IBFE7, IBFE8) a partir de transacciones de
 * Wompi en ambiente SANDBOX con tarjeta de prueba 4242, y las siete solicitudes
 * pendientes eran del mismo tipo.
 *
 * Todas las pruebas afirman la ausencia de tráfico HTTP, no solo la excepción:
 * lo que importa es que nada llegó al proveedor.
 */
class ProductionEmissionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        SandboxProbe::reset();

        config([
            'billing.enabled' => true,
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            // La decisión tributaria se da por confirmada para que lo que se
            // esté probando aquí sean las barreras de ORIGEN, no el bloqueo
            // tributario (que tiene sus propias pruebas).
            'billing.tax_decision_confirmed' => true,
            // Lo que se está probando: la suite la apaga en su línea base.
            'tax_policy.emission_guard_enabled' => true,
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    // ── Utilidades de montaje ─────────────────────────────────────────────

    private function payment(array $attributes = []): Payment
    {
        $user = User::factory()->create();

        return Payment::create(array_merge([
            'user_id' => $user->id,
            'amount' => '80000.00',
            'status' => 'paid',
        ], $attributes));
    }

    /**
     * `retry_allowed`, `cancellation_reason` y compañía no están en el
     * `$fillable` del modelo, así que la asignación masiva los descartaría en
     * silencio. Se fijan con forceFill, igual que hace el comando de
     * cancelación, para que la prueba ejercite el valor real y no el
     * predeterminado de la columna.
     */
    private function invoiceFor(Payment $payment, array $attributes = []): ElectronicInvoice
    {
        $forced = array_intersect_key($attributes, array_flip([
            'retry_allowed', 'cancellation_reason', 'cancelled_at', 'cancelled_by',
        ]));
        $attributes = array_diff_key($attributes, $forced);

        $invoice = ElectronicInvoice::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
            'type' => 'invoice',
            'status' => 'pending',
            'currency' => 'COP',
            'subtotal' => '80000.00',
            'discount' => '0.00',
            'tax_total' => '0.00',
            'total' => '80000.00',
        ], $attributes));

        if ($forced !== []) {
            $invoice->forceFill($forced)->save();
        }

        return $invoice;
    }

    private function transaction(Payment $payment, array $attributes = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'reference' => $payment->reference,
            'idempotency_key' => (string) Str::uuid(),
            'amount' => '80000.00',
            'currency' => 'COP',
            'status' => 'approved',
            'provider' => 'wompi',
            'environment' => 'production',
            'card_last_four' => '1234',
            'metadata' => ['wants_invoice' => true],
        ], $attributes));
    }

    /** Payload mínimo que el cliente enviaría para esa factura. */
    private function payloadFor(ElectronicInvoice $invoice): array
    {
        return [
            'numbering_range_id' => 2076,
            'reference_code' => $invoice->uuid,
            'items' => [[
                'code_reference' => 'PLAN-1',
                'name' => 'Plan',
                'quantity' => '1',
                'price' => '80000.00',
            ]],
        ];
    }

    private function assertBlocked(array $payload, string $expectedFragment): void
    {
        Http::fake();

        try {
            app(FactusClient::class)->createInvoice($payload);
            $this->fail('La emisión debió bloquearse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
            Http::assertNothingSent();
        }
    }

    // ── 1. Sandbox nunca usa el endpoint de producción ────────────────────

    public function test_ambiente_sandbox_con_endpoint_de_produccion_se_bloquea(): void
    {
        config(['billing.env' => 'sandbox', 'billing.base_url' => 'https://api.factus.com.co']);

        $payment = $this->payment(['reference' => 'IRON-REAL-1']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'el ambiente declarado es «sandbox»');
    }

    public function test_ambiente_produccion_con_endpoint_sandbox_se_bloquea(): void
    {
        // El error simétrico: creer que se emite de verdad y estar en sandbox.
        config(['billing.base_url' => 'https://api-sandbox.factus.com.co']);

        $payment = $this->payment(['reference' => 'IRON-REAL-2']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'el ambiente declarado es «production»');
    }

    public function test_una_sonda_de_sandbox_no_corre_contra_produccion(): void
    {
        $this->assertBlocked(
            ['reference_code' => SandboxProbe::REFERENCE_PREFIX.'X', 'items' => []],
            'el ambiente activo es producción',
        );
    }

    // ── 2. Producción nunca acepta pagos de sandbox ───────────────────────

    public function test_un_pago_de_sandbox_no_se_factura(): void
    {
        $payment = $this->payment(['reference' => 'IRON-SUB-20260713-X13B']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment, ['environment' => 'sandbox']);

        $this->assertBlocked($this->payloadFor($invoice), 'ambiente «sandbox»');
    }

    public function test_una_tarjeta_de_prueba_no_se_factura(): void
    {
        $payment = $this->payment(['reference' => 'IRON-TEST-CARD']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment, ['card_last_four' => '4242']);

        $this->assertBlocked($this->payloadFor($invoice), 'tarjeta de prueba');
    }

    public function test_un_pago_no_cobrado_no_se_factura(): void
    {
        $payment = $this->payment(['reference' => 'IRON-PENDIENTE', 'status' => 'pending']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'no «paid»');
    }

    public function test_una_factura_no_solicitada_no_se_emite(): void
    {
        $payment = $this->payment(['reference' => 'IRON-SIN-SOLICITUD']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment, ['metadata' => ['wants_invoice' => false]]);

        $this->assertBlocked($this->payloadFor($invoice), 'no fue solicitada por el cliente');
    }

    public function test_un_pago_sin_referencia_verificable_no_se_factura(): void
    {
        // Pago de pasarela sin transacción que lo respalde.
        $payment = $this->payment(['reference' => 'IRON-FANTASMA']);
        $invoice = $this->invoiceFor($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'referencia verificable');
    }

    public function test_una_referencia_desconocida_no_se_factura(): void
    {
        $this->assertBlocked(
            ['reference_code' => 'no-existe', 'items' => []],
            'no corresponde a ninguna factura registrada',
        );
    }

    // ── 3. Un pago no puede facturarse dos veces ──────────────────────────

    public function test_una_solicitud_ya_facturada_no_se_reemite(): void
    {
        $payment = $this->payment(['reference' => 'IRON-YA-EMITIDA']);
        $invoice = $this->invoiceFor($payment, [
            'status' => 'validated',
            'full_number' => 'IBFE99',
            'cufe' => str_repeat('a', 96),
        ]);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'ya tiene documento fiscal');
    }

    public function test_la_base_impide_dos_facturas_para_el_mismo_pago(): void
    {
        // Idempotencia a nivel de esquema:
        // unique(source_type, source_id, type).
        $payment = $this->payment(['reference' => 'IRON-UNICA']);
        $this->invoiceFor($payment);

        $this->expectException(QueryException::class);
        $this->invoiceFor($payment);
    }

    // ── 4. Una solicitud cancelada no se reintenta ────────────────────────

    public function test_una_solicitud_cancelada_no_se_reintenta(): void
    {
        $payment = $this->payment(['reference' => 'IRON-CANCELADA']);
        $invoice = $this->invoiceFor($payment, [
            'status' => 'cancelled',
            'cancellation_reason' => 'sandbox_test',
            'retry_allowed' => false,
        ]);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'está cancelada');
    }

    public function test_retry_allowed_false_bloquea_aunque_siga_pendiente(): void
    {
        $payment = $this->payment(['reference' => 'IRON-SIN-REINTENTO']);
        $invoice = $this->invoiceFor($payment, ['retry_allowed' => false]);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'reintentos deshabilitados');
    }

    // ── 5. Rechazada sin CUFE: solo con autorización ──────────────────────

    public function test_una_rechazada_sin_cufe_no_se_reintenta_sin_autorizacion(): void
    {
        $payment = $this->payment(['reference' => 'IRON-RECHAZADA']);
        $invoice = $this->invoiceFor($payment, ['status' => 'rejected']);
        $this->transaction($payment);

        $this->assertBlocked($this->payloadFor($invoice), 'exige autorización explícita');
    }

    public function test_una_rechazada_sin_cufe_se_reintenta_con_autorizacion(): void
    {
        $payment = $this->payment(['reference' => 'IRON-RECHAZADA-OK']);
        $invoice = $this->invoiceFor($payment, ['status' => 'rejected']);
        $this->transaction($payment);

        SandboxProbe::authorizeRejectedRetry($invoice->id);

        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['number' => 'IBFE100']], 201),
        ]);

        $result = app(FactusClient::class)->createInvoice($this->payloadFor($invoice));

        $this->assertTrue($result['ok']);
    }

    public function test_la_autorizacion_no_es_configurable_desde_el_entorno(): void
    {
        // Deliberadamente no existe una variable que la active: todo lo que se
        // pueda encender desde .env acabará encendido en producción.
        $payment = $this->payment(['reference' => 'IRON-RECHAZADA-2']);
        $invoice = $this->invoiceFor($payment, ['status' => 'rejected']);
        $this->transaction($payment);

        config(['billing.allow_rejected_retry' => true]);

        $this->assertBlocked($this->payloadFor($invoice), 'exige autorización explícita');
    }

    // ── Un caso que SÍ debe pasar ─────────────────────────────────────────

    public function test_un_pago_real_solicitado_y_cobrado_se_emite(): void
    {
        $payment = $this->payment(['reference' => 'IRON-BUENA']);
        $invoice = $this->invoiceFor($payment);
        $this->transaction($payment);

        Http::fake([
            '*oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600], 200),
            '*' => Http::response(['data' => ['number' => 'IBFE101']], 201),
        ]);

        $result = app(FactusClient::class)->createInvoice($this->payloadFor($invoice));

        $this->assertTrue($result['ok']);
    }
}
