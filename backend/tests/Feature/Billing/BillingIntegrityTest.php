<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\PaymentHasInvoiceException;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integridad histórica y tratamiento del plan Demo App Review.
 */
class BillingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ── Protección de pagos facturados (Fase 12) ────────────────────────────

    /**
     * En producción hay 6 facturas cuyo Payment fue eliminado y quedaron
     * huérfanas. No se reparan, pero el caso no vuelve a ocurrir.
     */
    public function test_payment_with_invoice_cannot_be_hard_deleted(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 80000, 'method' => 'wompi',
            'reference' => 'PROT-1', 'status' => 'paid', 'paid_at' => now(),
        ]);
        ElectronicInvoice::create([
            'source_type' => Payment::class, 'source_id' => $payment->id, 'type' => 'invoice',
            'status' => InvoiceStatus::VALIDATED->value,
            'subtotal' => 67226.89, 'tax_total' => 12773.11, 'discount' => 0, 'total' => 80000,
            'currency' => 'COP', 'cufe' => 'CUFE-1',
        ]);

        $this->expectException(PaymentHasInvoiceException::class);

        try {
            $payment->delete();
        } finally {
            // El pago sigue existiendo: la factura no queda huérfana.
            $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        }
    }

    public function test_payment_without_invoice_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'reference' => 'PROT-2', 'status' => 'pending',
        ]);

        $payment->delete();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    // ── Plan Demo App Review (Fase 11) ──────────────────────────────────────

    /** Un plan gratuito sin tarifa NO puede bloquear la activación de Factus. */
    public function test_free_demo_plan_does_not_block_factus_doctor(): void
    {
        $this->configureReadyFactus();

        Plan::create([
            'name' => 'Demo App Review', 'price' => 0, 'duration_days' => 30,
            'benefits' => '', 'active' => true, 'tax_rate_id' => null,
        ]);

        $this->artisan('billing:factus-doctor')->assertSuccessful();
    }

    /** Un plan facturable de pago SIN tarifa sí bloquea: no se asume IVA. */
    public function test_billable_plan_without_tax_rate_blocks_factus_doctor(): void
    {
        $this->configureReadyFactus();

        Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'benefits' => '', 'active' => true, 'tax_rate_id' => null,
        ]);

        $this->artisan('billing:factus-doctor')->assertFailed();
    }

    /** Marcar el plan como no facturable lo saca del bloqueo sin inventarle IVA. */
    public function test_non_billable_plan_does_not_block_factus_doctor(): void
    {
        $this->configureReadyFactus();

        Plan::create([
            'name' => 'Plan cortesía', 'price' => 80000, 'duration_days' => 30,
            'benefits' => '', 'active' => true, 'tax_rate_id' => null, 'billing_enabled' => false,
        ]);

        $this->artisan('billing:factus-doctor')->assertSuccessful();
    }

    public function test_zero_price_product_does_not_block_factus_doctor(): void
    {
        $this->configureReadyFactus();

        Product::create([
            'name' => 'Muestra gratis', 'sale_price' => 0, 'cost_price' => 0,
            'stock' => 5, 'active' => true,
        ]);

        $this->artisan('billing:factus-doctor')->assertSuccessful();
    }

    // ── billing:set-plan-billing-status (Fase 11) ───────────────────────────

    public function test_set_plan_billing_status_requires_confirm(): void
    {
        $plan = Plan::create([
            'name' => 'Demo App Review', 'price' => 0, 'duration_days' => 30,
            'benefits' => '', 'active' => true,
        ]);

        $this->artisan("billing:set-plan-billing-status {$plan->id} false")->assertSuccessful();

        // Sin --confirm no escribe.
        $this->assertNotFalse($plan->fresh()->billing_enabled);
    }

    public function test_set_plan_billing_status_applies_with_confirm(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        $plan = Plan::create([
            'name' => 'Demo App Review', 'price' => 0, 'duration_days' => 30,
            'benefits' => '', 'active' => true, 'tax_rate_id' => null,
            'features' => ['iron_ia' => true],
        ]);

        $this->artisan("billing:set-plan-billing-status {$plan->id} false --confirm")->assertSuccessful();

        $plan->refresh();
        $this->assertFalse((bool) $plan->billing_enabled);
        // NO toca acceso, precio, tarifa ni features.
        $this->assertTrue((bool) $plan->active);
        $this->assertSame(0.0, (float) $plan->price);
        $this->assertNull($plan->tax_rate_id);
        $this->assertTrue($plan->resolvedFeatures()['iron_ia']);
        $this->assertNotNull($rate); // la tarifa existente no se asignó sola
    }

    public function test_set_plan_billing_status_rejects_unknown_plan(): void
    {
        $this->artisan('billing:set-plan-billing-status 999999 false --confirm')->assertFailed();
    }

    /** Configuración mínima para que el doctor no falle por otras causas. */
    private function configureReadyFactus(): void
    {
        config([
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.tax_decision_confirmed' => true,
            'billing.credentials' => [
                'username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's',
            ],
            'billing.numbering.range_id' => '389',
            'billing.numbering.credit_range_id' => '390',
            'billing.defaults.municipality_code' => '41001',
            'billing.company.nit' => '123456789',
            'billing.company.dv' => '1',
            'billing.company.name' => 'IRON BODY NEIVA',
        ]);
    }
}
