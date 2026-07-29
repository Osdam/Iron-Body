<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\PricingService;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Recorrido completo del dinero con Pricing V2: cotización → firma → cobro →
 * pago → factura. Verifica la invariante del negocio:
 *
 *     total_cotizado = total_firmado = total_cobrado = total_facturado
 *
 * NUNCA llama a Wompi ni a Factus: Http::fake() bloquea toda salida de red y se
 * afirma explícitamente que no se envió nada.
 */
class PricingV2WompiFlowTest extends TestCase
{
    use AssumesVatResponsibleIssuer;
    use RefreshDatabase;

    private Plan $plan;

    private TaxRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas pruebas verifican el MOTOR de calculo, no la politica
        // vigente de Iron Body (no responsable de IVA). Ver el trait.
        $this->assumeVatResponsibleIssuer();
        Http::fake();   // ninguna llamada real sale de aquí
        Queue::fake();

        config([
            'billing.pricing.v2_enabled' => true,
            'billing.pricing.tax_on_top' => true,
            'billing.enabled' => false,
        ]);
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'integrity_secret' => 'test_integrity_xyz', 'currency' => 'COP',
        ]));

        $this->rate = TaxRate::create([
            'code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19,
            'active' => true, 'factus_tribute_id' => '01',
        ]);
        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate->id, 'pricing_mode' => 'base_plus_tax',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Cliente', 'email' => 'cliente@example.com', 'password' => bcrypt('x'),
            'document' => '1000000001', 'phone' => '3000000000', 'status' => 'pending',
        ]);
    }

    // ── Cotización y firma ──────────────────────────────────────────────────

    public function test_wompi_charges_gross_and_freezes_snapshot(): void
    {
        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'amount' => 80000,           // el cliente manda la base: se ignora
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        // Se cobra el BRUTO, no el precio configurado.
        $this->assertSame(95200.0, (float) $tx->amount);
        $this->assertSame(80000.0, (float) $tx->base_amount);
        $this->assertSame(15200.0, (float) $tx->tax_amount);
        $this->assertSame(95200.0, (float) $tx->gross_amount);
        $this->assertSame('base_plus_tax', $tx->pricing_mode);

        // Centavos exactos que viajan a la pasarela.
        $this->assertSame(9520000, WompiTransactionService::make()->amountInCents($tx));

        Http::assertNothingSent();
    }

    public function test_integrity_signature_uses_the_same_gross_cents(): void
    {
        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        $cents = WompiTransactionService::make()->amountInCents($tx);
        $signature = (new WompiSignatureService((array) config('wompi')))->integritySignature($tx->reference, $cents, 'COP');

        $expected = hash('sha256', $tx->reference.'9520000'.'COP'.'test_integrity_xyz');

        $this->assertSame(9520000, $cents);
        $this->assertSame($expected, $signature);
    }

    /**
     * Si el catálogo cambia entre la autorización y el webhook, la validación
     * usa el importe CONGELADO, no el precio nuevo.
     */
    public function test_webhook_validates_against_frozen_amount(): void
    {
        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        $this->plan->forceFill(['price' => 200000])->save();

        // El importe congelado sigue mandando.
        $this->assertSame(9520000, WompiTransactionService::make()->amountInCents($tx->fresh()));
    }

    // ── Pago y factura ──────────────────────────────────────────────────────

    public function test_payment_inherits_snapshot_and_invoice_matches_it(): void
    {
        $user = $this->user();

        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'user_id' => $user->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);
        $tx->forceFill(['status' => PaymentStateMachine::APPROVED, 'paid_at' => now()])->save();

        app(PaymentMembershipActivator::class)->activate($tx, 'wompi');

        $payment = Payment::where('reference', $tx->reference)->firstOrFail();

        // 1) El pago almacena el bruto y hereda el desglose.
        //    (comparación numérica: el driver puede devolver string o float)
        $this->assertSame(95200.0, (float) $payment->amount);
        $this->assertSame(80000.0, (float) $payment->base_amount);
        $this->assertSame(15200.0, (float) $payment->tax_amount);
        $this->assertSame(95200.0, (float) $payment->gross_amount);
        $this->assertTrue($payment->hasFinancialSnapshot());

        // 2) La factura se construye desde ese snapshot.
        $built = app(InvoiceDtoBuilder::class)->forPayment($payment, [
            'doc_type' => '13', 'doc_number' => '222222222222', 'name' => 'Consumidor final',
            'is_final_consumer' => true,
        ]);

        $this->assertSame('80000.00', $built['snapshot']['subtotal']);
        $this->assertSame('15200.00', $built['snapshot']['tax_total']);
        $this->assertSame('95200.00', $built['snapshot']['total']);

        // 3) invoice.total == payment.amount — la invariante del negocio.
        $this->assertSame((float) $payment->amount, (float) $built['snapshot']['total']);

        // 4) Factus recibe la base, no el bruto.
        $this->assertSame('80000.00', $built['payload']['items'][0]['price']);
        $this->assertSame('19.00', $built['payload']['items'][0]['taxes'][0]['rate']);
        $this->assertSame('95200.00', $built['payload']['payment_details'][0]['amount']);

        Http::assertNothingSent();
    }

    /**
     * Con Pricing V2 apagado el comportamiento es exactamente el anterior:
     * se cobra el precio configurado y no se congela snapshot.
     */
    public function test_v2_disabled_keeps_legacy_behaviour(): void
    {
        config(['billing.pricing.v2_enabled' => false]);

        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        $this->assertSame(80000.0, (float) $tx->amount);
        $this->assertNull($tx->gross_amount);
        $this->assertSame(8000000, WompiTransactionService::make()->amountInCents($tx));
    }

    public function test_pricing_rules_version_is_recorded(): void
    {
        $tx = WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        $this->assertSame(PricingService::RULES_VERSION, $tx->pricing_rules_version);
        $this->assertNotNull($tx->priced_at);
    }

    /** Ninguna transacción de prueba debe tocar la red. */
    public function test_no_external_http_calls_are_made(): void
    {
        WompiTransactionService::make()->createOrReuse([
            'plan_id' => $this->plan->id,
            'customer' => ['email' => 'cliente@example.com'],
        ]);

        Http::assertNothingSent();
    }
}
