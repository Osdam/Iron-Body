<?php

namespace Tests\Feature\Billing;

use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\InvoiceDtoBuilder;
use App\Services\Billing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Caja, pagos manuales y suscripciones bajo Pricing V2.
 */
class PricingV2SalesAndPaymentsTest extends TestCase
{
    use AssumesVatResponsibleIssuer;
    use RefreshDatabase;

    /**
     * Credencial de estas pruebas: una SESIÓN de administrador real.
     *
     * Estas rutas (`/admin/caja/*`, `/admin/products/*`) exigen ahora permiso
     * por ruta. El token compartido de automatizaciones es de SOLO LECTURA por
     * política —igual que en moderación—, así que cobrar con él ya no procede.
     * Autenticar como administrador refleja además cómo llama el CRM de verdad:
     * un cajero con sesión, no un secreto estático.
     */
    private ?array $adminSessionHeaders = null;

    protected function adminHeaders(array $headers = []): array
    {
        $this->adminSessionHeaders ??= $this->actingAsAdmin();

        return array_merge($this->adminSessionHeaders, $headers);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Cobrar exige turno de caja abierto.
        $this->openCashShift();
        // Cobrar una membresía desde el CRM es una operación de mostrador: exige
        // la caja del gimnasio abierta, igual que vender exige la de productos.
        $this->openCashShift(null, \App\Enums\CashShiftType::GYM);

        // Estas pruebas verifican el MOTOR de calculo, no la politica
        // vigente de Iron Body (no responsable de IVA). Ver el trait.
        $this->assumeVatResponsibleIssuer();
        Http::fake();
        Queue::fake();
        config([
            'billing.pricing.v2_enabled' => true,
            'billing.pricing.tax_on_top' => true,
            'billing.enabled' => false,
        ]);
    }

    private function rate(float $percent = 19): TaxRate
    {
        return TaxRate::create([
            'code' => 'IVA_'.(int) $percent, 'name' => "IVA {$percent}%", 'rate' => $percent,
            'active' => true, 'factus_tribute_id' => '01',
        ]);
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Proteína', 'sale_price' => 80000, 'cost_price' => 40000, 'stock' => 50,
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ], $attrs));
    }

    // ── Caja ────────────────────────────────────────────────────────────────

    public function test_caja_sale_stores_per_line_snapshot_and_charges_gross(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'paid' => true,
        ])->assertCreated();

        $sale = ProductSale::with('items')->find($res->json('data.id'));

        // Se cobra base + IVA.
        $this->assertSame(95200.0, (float) $sale->total);
        $this->assertSame(80000.0, (float) $sale->base_amount);
        $this->assertSame(15200.0, (float) $sale->tax_amount);
        $this->assertSame(95200.0, (float) $sale->gross_amount);

        // Snapshot congelado por línea.
        $item = $sale->items->first();
        $this->assertSame(80000.0, (float) $item->base_amount);
        $this->assertSame(15200.0, (float) $item->tax_amount);
        $this->assertSame(95200.0, (float) $item->gross_amount);
        $this->assertSame(19.0, (float) $item->tax_rate);
        $this->assertSame('base_plus_tax', $item->pricing_mode);
    }

    public function test_caja_two_units_have_no_rounding_drift(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'paid' => true,
        ])->assertCreated();

        $sale = ProductSale::find($res->json('data.id'));

        $this->assertSame(160000.0, (float) $sale->base_amount);
        $this->assertSame(30400.0, (float) $sale->tax_amount);
        $this->assertSame(190400.0, (float) $sale->total);
    }

    /** El descuento se representa en el payload, ya no se envía siempre 0.00. */
    public function test_caja_discount_is_represented_in_factus_payload(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'discount' => 8000, 'paid' => true,
        ])->assertCreated();

        $sale = ProductSale::with('items.product.taxRate')->find($res->json('data.id'));

        $this->assertSame(87200.0, (float) $sale->total);  // 80.000 + 15.200 - 8.000

        $built = app(InvoiceDtoBuilder::class)->forSale($sale, [
            'doc_type' => '13', 'doc_number' => '222222222222', 'name' => 'Consumidor final',
            'is_final_consumer' => true,
        ]);

        $this->assertSame('10.00', $built['payload']['items'][0]['discount_rate']); // 8.000/80.000
        $this->assertSame('87200.00', $built['payload']['payment_details'][0]['amount']);
        $this->assertSame(87200.0, (float) $built['snapshot']['total']);
    }

    public function test_caja_rejects_discount_larger_than_taxable_base(): void
    {
        $product = $this->product();

        $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'discount' => 500000, 'paid' => true,
        ])->assertStatus(422);

        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_caja_rejects_zero_and_negative_quantity(): void
    {
        $product = $this->product();

        foreach ([0, -3] as $qty) {
            $this->adminPostJson('/api/admin/caja/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => $qty]],
                'payment_method' => 'cash', 'paid' => true,
            ])->assertStatus(422);
        }
    }

    /** Una venta pagada no se recalcula desde el precio actual del producto. */
    public function test_paid_sale_is_not_recalculated_from_current_product_price(): void
    {
        $product = $this->product();

        $res = $this->adminPostJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ])->assertCreated();

        $product->forceFill(['sale_price' => 300000, 'tax_rate_id' => $this->rate(5)->id])->save();

        $sale = ProductSale::with('items.product.taxRate')->find($res->json('data.id'));
        $built = app(InvoiceDtoBuilder::class)->forSale($sale, [
            'doc_type' => '13', 'doc_number' => '222222222222', 'name' => 'Consumidor final',
            'is_final_consumer' => true,
        ]);

        $this->assertSame('95200.00', $built['snapshot']['total']);
        $this->assertSame('80000.00', $built['snapshot']['subtotal']);
    }

    // ── Pagos manuales ──────────────────────────────────────────────────────

    public function test_manual_payment_uses_backend_quote(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        // El admin envía el total correcto: se acepta y se congela el desglose.
        $res = $this->adminPostJson('/api/payments', [
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 95200,
            'method' => 'cash', 'status' => 'paid',
        ])->assertCreated();

        $payment = Payment::find($res->json('id'));

        $this->assertSame(95200.0, (float) $payment->amount);
        $this->assertSame(80000.0, (float) $payment->base_amount);
        $this->assertSame(15200.0, (float) $payment->tax_amount);
        $this->assertSame(95200.0, (float) $payment->gross_amount);
    }

    /** El admin ya no puede registrar en silencio un total incompatible. */
    public function test_manual_payment_rejects_incoherent_amount_without_override(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'cash', 'status' => 'paid',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manual_payment_override_requires_reason(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $this->adminPostJson('/api/payments', [
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 50000,
            'method' => 'cash', 'status' => 'paid', 'amount_override' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('override_reason');
    }

    /** Con override, base + IVA siguen sumando EXACTAMENTE lo cobrado. */
    public function test_manual_payment_override_keeps_snapshot_consistent(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $res = $this->adminPostJson('/api/payments', [
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 50000,
            'method' => 'cash', 'status' => 'paid',
            'amount_override' => true, 'override_reason' => 'Acuerdo comercial autorizado por gerencia',
        ])->assertCreated();

        $payment = Payment::find($res->json('id'));

        $this->assertSame(50000.0, (float) $payment->amount);
        $this->assertSame(50000.0, (float) $payment->gross_amount);
        $this->assertSame(
            (float) $payment->gross_amount,
            round((float) $payment->base_amount + (float) $payment->tax_amount, 2)
        );
        $this->assertDatabaseHas('audit_logs', ['module' => 'payments', 'entity' => 'payment']);
    }

    // ── Suscripciones ───────────────────────────────────────────────────────

    public function test_new_subscription_freezes_gross_snapshot(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);
        $user = User::factory()->create();

        $sub = new MembershipSubscription([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => MembershipSubscription::STATUS_PENDING_FIRST_PAYMENT,
            'price_snapshot' => 80000,
            'currency' => 'COP',
            'interval_days' => 30,
            'method' => 'card',
        ]);
        // Se congela el mismo desglose que usa MembershipSubscriptionService.
        $quote = app(PricingService::class)->quoteForPlan($plan);
        $sub->fill([
            'base_snapshot' => $quote->baseAmount->toDatabase(),
            'tax_amount_snapshot' => $quote->taxAmount->toDatabase(),
            'gross_snapshot' => $quote->grossAmount->toDatabase(),
            'tax_rate_id_snapshot' => $quote->taxRateId,
            'tax_rate_snapshot' => $quote->taxRateString(),
            'pricing_mode_snapshot' => $quote->pricingMode->value,
            'pricing_rules_version' => $quote->pricingRulesVersion,
            'priced_at' => $quote->pricedAt,
        ]);
        $sub->save();

        $this->assertSame(80000.0, (float) $sub->base_snapshot);
        $this->assertSame(15200.0, (float) $sub->tax_amount_snapshot);
        $this->assertSame(95200.0, (float) $sub->gross_snapshot);

        // El cobro recurrente usa el BRUTO, no price_snapshot.
        $this->assertSame(95200.0, $sub->chargeableGrossAmount());
    }

    /** Sin snapshot (suscripción legacy) se conserva el cobro anterior. */
    public function test_legacy_subscription_without_snapshot_charges_price_snapshot(): void
    {
        $sub = new MembershipSubscription([
            'uuid' => (string) Str::uuid(),
            'status' => MembershipSubscription::STATUS_CANCELLED,
            'price_snapshot' => 80000, 'currency' => 'COP', 'interval_days' => 30,
        ]);

        $this->assertFalse($sub->hasFinancialSnapshot());
        $this->assertSame(80000.0, $sub->chargeableGrossAmount());
    }

    /** Cambiar el catálogo NO altera una suscripción ya autorizada. */
    public function test_catalog_change_does_not_alter_existing_subscription(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);
        $sub = MembershipSubscription::create([
            'uuid' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'status' => MembershipSubscription::STATUS_ACTIVE,
            'price_snapshot' => 80000, 'currency' => 'COP', 'interval_days' => 30, 'method' => 'card',
            'base_snapshot' => 80000, 'tax_amount_snapshot' => 15200, 'gross_snapshot' => 95200,
        ]);

        $plan->forceFill(['price' => 500000])->save();

        $this->assertSame(95200.0, $sub->fresh()->chargeableGrossAmount());
    }
}
