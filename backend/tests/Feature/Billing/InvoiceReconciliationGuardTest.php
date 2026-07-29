<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\FactusPayloadSanitizer;
use App\Services\Billing\FactusResponseMapper;
use App\Services\Billing\FiscalProfileResolver;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\InvoicePdfStorageService;
use App\Services\Billing\InvoiceReconciler;
use App\Services\Billing\InvoicingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GUARDARRAÍL: una factura nunca sale a Factus por un importe distinto al cobrado.
 *
 * Es la barrera que impide el escenario prohibido del negocio (cobrar 80.000 y
 * facturar 95.200). Todas las pruebas afirman explícitamente que NO se hizo
 * ninguna llamada HTTP cuando el descuadre existe.
 */
class InvoiceReconciliationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config([
            'billing.enabled' => true,
            'billing.env' => 'sandbox',
            'billing.reconciliation_guard.enabled' => true,
            'billing.reconciliation_guard.tolerance' => 1,
        ]);
    }

    private function paymentWithInvoice(float $paid, float $invoiceTotal): array
    {
        $rate = TaxRate::create([
            'code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19,
            'active' => true, 'factus_tribute_id' => '01',
        ]);
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $rate->id,
        ]);
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => $paid,
            'method' => 'cash', 'reference' => 'GUARD-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ]);

        $invoice = ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => InvoiceStatus::PENDING->value,
            'subtotal' => round($invoiceTotal / 1.19, 2),
            'tax_total' => round($invoiceTotal - round($invoiceTotal / 1.19, 2), 2),
            'discount' => 0, 'total' => $invoiceTotal, 'currency' => 'COP',
            'payload_snapshot' => ['document' => '01', 'items' => []],
        ]);

        return [$payment, $invoice];
    }

    public function test_mismatch_blocks_emission_and_never_calls_factus(): void
    {
        // Cobrado 80.000, comprobante por 95.200: el caso prohibido.
        [, $invoice] = $this->paymentWithInvoice(80000, 95200);

        app(EmitElectronicInvoiceJob::class, ['invoiceId' => $invoice->id]);
        (new EmitElectronicInvoiceJob($invoice->id))->handle(
            app(FactusClient::class),
            app(InvoiceDtoBuilder::class),
            app(FiscalProfileResolver::class),
            app(FactusResponseMapper::class),
            app(FactusPayloadSanitizer::class),
            app(InvoicePdfStorageService::class),
            app(InvoicingService::class),
            app(InvoiceReconciler::class),
        );

        $invoice->refresh();

        Http::assertNothingSent();                                   // NO se llamó a Factus
        $this->assertSame(ElectronicInvoice::RECONCILIATION_FAILED, $invoice->reconciliation_status);
        $this->assertSame(15200.0, (float) $invoice->reconciliation_difference);
        // Error técnico, NO 'rejected': la DIAN nunca vio este documento.
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertStringContainsString('Descuadre', (string) $invoice->failure_reason);
    }

    public function test_matching_amounts_pass_the_guard(): void
    {
        [, $invoice] = $this->paymentWithInvoice(80000, 80000);

        $result = app(InvoiceReconciler::class)->check($invoice, $invoice->source);

        $this->assertTrue($result['ok']);
        $this->assertSame(0.0, $result['difference']);
    }

    public function test_rounding_within_tolerance_is_accepted(): void
    {
        [, $invoice] = $this->paymentWithInvoice(80000, 80000.50);

        $result = app(InvoiceReconciler::class)->check($invoice, $invoice->source);

        $this->assertTrue($result['ok']);
    }

    /** Las 6 facturas históricas sin Payment no se pueden conciliar: no se bloquean. */
    public function test_orphan_invoice_is_skipped_not_blocked(): void
    {
        $invoice = ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => 999999, 'type' => 'invoice',
            'status' => InvoiceStatus::PENDING->value,
            'subtotal' => 67226.89, 'tax_total' => 12773.11, 'discount' => 0,
            'total' => 80000, 'currency' => 'COP',
        ]);

        $result = app(InvoiceReconciler::class)->check($invoice, null);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
    }

    public function test_guard_can_be_disabled_by_flag(): void
    {
        config(['billing.reconciliation_guard.enabled' => false]);
        [, $invoice] = $this->paymentWithInvoice(80000, 95200);

        $result = app(InvoiceReconciler::class)->check($invoice, $invoice->source);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
    }

    /** Un descuadre no se reintenta a ciegas: volvería a fallar igual. */
    public function test_retry_is_blocked_after_reconciliation_failure(): void
    {
        [, $invoice] = $this->paymentWithInvoice(80000, 95200);
        $invoice->markReconciliationFailed(80000, 15200, 'Descuadre pago/factura.');

        $this->assertFalse(app(InvoicingService::class)->retry($invoice->fresh()));
        Http::assertNothingSent();
    }
}
