<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Billing\Money;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingMode;
use App\Services\Billing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reglas de cotización: la fuente única de verdad del dinero.
 */
class PricingServiceTest extends TestCase
{
    use AssumesVatResponsibleIssuer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Estas pruebas verifican el MOTOR de calculo, no la politica
        // vigente de Iron Body (no responsable de IVA). Ver el trait.
        $this->assumeVatResponsibleIssuer();
        // El IVA adicional solo actúa con el interruptor global encendido.
        config(['billing.pricing.tax_on_top' => true]);
    }

    private function rate(float $percent = 19, string $code = 'IVA_19'): TaxRate
    {
        return TaxRate::create([
            'code' => $code, 'name' => "IVA {$percent}%", 'rate' => $percent,
            'active' => true, 'factus_tribute_id' => '01',
        ]);
    }

    private function plan(array $attrs = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true,
        ], $attrs));
    }

    private function service(): PricingService
    {
        return app(PricingService::class);
    }

    // ── Caso comercial central ──────────────────────────────────────────────

    public function test_plan_base_80000_plus_iva_19_totals_95200(): void
    {
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax']);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('80000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('15200.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('95200.00', $quote->grossAmount->toDecimalString());
        $this->assertSame('19.00', $quote->taxRateString());
        $this->assertTrue($quote->isBalanced());
    }

    public function test_product_base_80000_plus_iva_19_totals_95200(): void
    {
        $product = Product::create([
            'name' => 'Proteína', 'sale_price' => 80000, 'cost_price' => 40000, 'stock' => 5,
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $quote = $this->service()->quoteForProduct($product);

        $this->assertSame('80000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('15200.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('95200.00', $quote->grossAmount->toDecimalString());
    }

    public function test_two_units_of_80000_total_190400(): void
    {
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax']);

        $quote = $this->service()->quoteForPlan($plan, 2);

        $this->assertSame('160000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('30400.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('190400.00', $quote->grossAmount->toDecimalString());
        // La base unitaria reconstruye el agregado sin deriva.
        $this->assertSame('80000.00', $quote->unitBaseAmount->toDecimalString());
    }

    // ── Legacy ──────────────────────────────────────────────────────────────

    public function test_legacy_inclusive_splits_80000_backwards(): void
    {
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id]); // default legacy

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('67226.89', $quote->baseAmount->toDecimalString());
        $this->assertSame('12773.11', $quote->taxAmount->toDecimalString());
        $this->assertSame('80000.00', $quote->grossAmount->toDecimalString());
        $this->assertTrue($quote->isBalanced());
    }

    /**
     * Red de seguridad del despliegue: con el interruptor global apagado, un plan
     * marcado como base_plus_tax se cotiza como legacy y NO sube el cobro.
     */
    public function test_tax_on_top_flag_off_forces_legacy_behaviour(): void
    {
        config(['billing.pricing.tax_on_top' => false]);
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax']);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('80000.00', $quote->grossAmount->toDecimalString());
        $this->assertSame(PricingMode::LEGACY_INCLUSIVE, $quote->pricingMode);
    }

    // ── Tarifas especiales ──────────────────────────────────────────────────

    public function test_zero_rate_adds_no_tax(): void
    {
        $plan = $this->plan([
            'price' => 100000,
            'tax_rate_id' => $this->rate(0, 'EXCLUDED')->id,
            'pricing_mode' => 'base_plus_tax',
        ]);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('100000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('0.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('100000.00', $quote->grossAmount->toDecimalString());
        $this->assertFalse($quote->hasTax());
    }

    public function test_excluded_and_exempt_products_have_no_tax(): void
    {
        foreach ([['EXCLUDED', 'Excluido'], ['EXEMPT', 'Exento']] as [$code, $label]) {
            $product = Product::create([
                'name' => $label, 'sale_price' => 50000, 'cost_price' => 20000, 'stock' => 3,
                'active' => true, 'tax_rate_id' => $this->rate(0, $code)->id,
                'pricing_mode' => 'base_plus_tax',
            ]);

            $quote = $this->service()->quoteForProduct($product);

            $this->assertSame('0.00', $quote->taxAmount->toDecimalString(), $label);
            $this->assertSame('50000.00', $quote->grossAmount->toDecimalString(), $label);
        }
    }

    public function test_iva_5_percent(): void
    {
        $plan = $this->plan([
            'price' => 100000,
            'tax_rate_id' => $this->rate(5, 'IVA_5')->id,
            'pricing_mode' => 'base_plus_tax',
        ]);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('5000.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('105000.00', $quote->grossAmount->toDecimalString());
    }

    // ── Errores controlados ─────────────────────────────────────────────────

    public function test_billable_plan_without_tax_rate_is_rejected(): void
    {
        $plan = $this->plan(); // sin tarifa

        $this->expectException(PricingException::class);
        $this->service()->quoteForPlan($plan);
    }

    /** El plan Demo App Review: gratuito, sin tarifa, y debe seguir cotizando. */
    public function test_free_plan_without_tax_rate_is_allowed(): void
    {
        $plan = $this->plan(['name' => 'Demo App Review', 'price' => 0]);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('0.00', $quote->grossAmount->toDecimalString());
    }

    public function test_non_billable_plan_without_tax_rate_is_allowed(): void
    {
        $plan = $this->plan(['billing_enabled' => false]);

        $quote = $this->service()->quoteForPlan($plan);

        $this->assertSame('80000.00', $quote->grossAmount->toDecimalString());
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id]);

        $this->expectException(PricingException::class);
        $this->service()->quoteForPlan($plan, 0);
    }

    public function test_negative_price_is_rejected(): void
    {
        $plan = $this->plan(['price' => -1000, 'tax_rate_id' => $this->rate()->id]);

        $this->expectException(PricingException::class);
        $this->service()->quoteForPlan($plan);
    }

    public function test_coherent_discount_is_applied(): void
    {
        $product = Product::create([
            'name' => 'Barra', 'sale_price' => 80000, 'cost_price' => 30000, 'stock' => 9,
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $quote = $this->service()->quoteForProduct($product, 1, Money::fromAmount(5200));

        $this->assertSame('80000.00', $quote->baseAmount->toDecimalString());
        $this->assertSame('15200.00', $quote->taxAmount->toDecimalString());
        $this->assertSame('5200.00', $quote->discountAmount->toDecimalString());
        $this->assertSame('90000.00', $quote->grossAmount->toDecimalString());
        $this->assertTrue($quote->isBalanced());
    }

    public function test_excessive_discount_is_rejected(): void
    {
        $product = Product::create([
            'name' => 'Barra', 'sale_price' => 10000, 'cost_price' => 3000, 'stock' => 9,
            'active' => true, 'tax_rate_id' => $this->rate()->id,
        ]);

        $this->expectException(PricingException::class);
        $this->service()->quoteForProduct($product, 1, Money::fromAmount(50000));
    }

    public function test_snapshot_roundtrip_preserves_amounts(): void
    {
        $plan = $this->plan(['tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax']);
        $original = $this->service()->quoteForPlan($plan);

        $snapshot = $original->toSnapshot();
        $restored = $this->service()->fromSnapshot($snapshot);

        $this->assertSame('80000.00', $restored->baseAmount->toDecimalString());
        $this->assertSame('15200.00', $restored->taxAmount->toDecimalString());
        $this->assertSame('95200.00', $restored->grossAmount->toDecimalString());
        $this->assertSame('19.00', $restored->taxRateString());
        $this->assertTrue($restored->isBalanced());
    }
}
