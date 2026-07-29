<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/admin/billing/quote — la cotización oficial del CRM.
 *
 * El frontend muestra base/IVA/total, pero los tres los calcula el backend. Si
 * el frontend pudiera enviar tarifas o totales, volvería a existir la
 * posibilidad de que lo mostrado y lo cobrado divergieran.
 */
class BillingQuoteApiTest extends TestCase
{
    use AssumesVatResponsibleIssuer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Estas pruebas verifican el MOTOR de calculo, no la politica
        // vigente de Iron Body (no responsable de IVA). Ver el trait.
        $this->assumeVatResponsibleIssuer();
        config(['billing.pricing.tax_on_top' => true]);
    }

    private function rate(float $percent = 19): TaxRate
    {
        return TaxRate::create([
            'code' => 'IVA_'.(int) $percent, 'name' => "IVA {$percent}%", 'rate' => $percent,
            'active' => true, 'factus_tribute_id' => '01',
        ]);
    }

    public function test_requires_admin_authentication(): void
    {
        $this->postJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_quotes_a_plan_with_tax_on_top(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => $plan->id,
        ])
            ->assertOk()
            ->assertJson([
                'currency' => 'COP',
                'pricing_mode' => 'base_plus_tax',
                'base_amount' => '80000.00',
                'tax_rate' => '19.00',
                'tax_amount' => '15200.00',
                'gross_amount' => '95200.00',
                'display' => [
                    'base' => '$80.000',
                    'tax' => '$15.200',
                    'total' => '$95.200',
                ],
            ]);
    }

    public function test_quotes_a_legacy_plan_with_tax_included(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id,
        ]);

        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => $plan->id,
        ])
            ->assertOk()
            ->assertJson([
                'pricing_mode' => 'legacy_inclusive',
                'base_amount' => '67226.89',
                'tax_amount' => '12773.11',
                'gross_amount' => '80000.00',
            ]);
    }

    public function test_quotes_a_product_with_quantity(): void
    {
        $product = Product::create([
            'name' => 'Proteína', 'sale_price' => 80000, 'cost_price' => 40000, 'stock' => 10,
            'active' => true, 'tax_rate_id' => $this->rate()->id, 'pricing_mode' => 'base_plus_tax',
        ]);

        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'product', 'source_id' => $product->id, 'quantity' => 2,
        ])
            ->assertOk()
            ->assertJson([
                'base_amount' => '160000.00',
                'tax_amount' => '30400.00',
                'gross_amount' => '190400.00',
            ]);
    }

    public function test_rejects_unknown_source_type(): void
    {
        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'membership', 'source_id' => 1,
        ])->assertStatus(422);
    }

    public function test_rejects_invalid_quantity(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true, 'tax_rate_id' => $this->rate()->id,
        ]);

        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => $plan->id, 'quantity' => 0,
        ])->assertStatus(422);
    }

    /** Configuración fiscal incompleta → 422 con el motivo, no un total inventado. */
    public function test_billable_plan_without_tax_rate_returns_422(): void
    {
        $plan = Plan::create([
            'name' => 'Sin tarifa', 'price' => 80000, 'duration_days' => 30, 'benefits' => '',
            'active' => true,
        ]);

        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => $plan->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'tarifa de impuesto'));
    }

    public function test_unknown_source_id_returns_404(): void
    {
        $this->adminPostJson('/api/admin/billing/quote', [
            'source_type' => 'plan', 'source_id' => 999999,
        ])->assertNotFound();
    }
}
