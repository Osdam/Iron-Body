<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalConfigTest extends TestCase
{
    use RefreshDatabase;

    private function ivaIncl(): TaxRate
    {
        return TaxRate::create([
            'code' => 'IVA_19_INCL', 'name' => 'IVA 19% incluido', 'rate' => 19,
            'price_includes_tax' => true, 'active' => true, 'factus_tribute_id' => '01',
        ]);
    }

    public function test_tax_rates_requires_admin_auth(): void
    {
        $this->getJson('/api/admin/billing/tax-rates')->assertStatus(401);
    }

    public function test_lists_tax_rate_catalog(): void
    {
        $this->ivaIncl();

        $this->adminGetJson('/api/admin/billing/tax-rates')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'IVA_19_INCL');
    }

    public function test_bulk_assigns_iva_to_active_products_only(): void
    {
        $rate = $this->ivaIncl();
        $a = Product::create(['name' => 'A', 'sale_price' => 119000, 'stock' => 5, 'active' => true]);
        $b = Product::create(['name' => 'B', 'sale_price' => 59500, 'stock' => 5, 'active' => true]);
        $inactive = Product::create(['name' => 'C', 'sale_price' => 10000, 'stock' => 5, 'active' => false]);

        $this->adminPostJson('/api/admin/billing/products/bulk-tax', ['tax_rate_id' => $rate->id])
            ->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame($rate->id, $a->fresh()->tax_rate_id);
        $this->assertTrue((bool) $a->fresh()->price_includes_tax);   // sincronizado desde la tarifa
        $this->assertSame($rate->id, $b->fresh()->tax_rate_id);
        $this->assertNull($inactive->fresh()->tax_rate_id);          // inactivo no se toca
    }

    public function test_assign_plan_syncs_price_includes_tax(): void
    {
        $excl = TaxRate::create([
            'code' => 'IVA_19_EXCL', 'name' => 'IVA 19% no incluido', 'rate' => 19,
            'price_includes_tax' => false, 'active' => true, 'factus_tribute_id' => '01',
        ]);
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '', 'price_includes_tax' => true]);

        $this->adminPutJson("/api/admin/billing/plans/{$plan->id}/tax-rate", ['tax_rate_id' => $excl->id])
            ->assertOk()->assertJsonPath('data.tax_rate_id', $excl->id);

        $this->assertSame($excl->id, $plan->fresh()->tax_rate_id);
        $this->assertFalse((bool) $plan->fresh()->price_includes_tax); // sincronizado (no incluido)
    }

    public function test_assignments_marks_pending_without_rate(): void
    {
        Plan::create(['name' => 'Lite', 'price' => 50000, 'duration_days' => 30, 'benefits' => '']);

        $res = $this->adminGetJson('/api/admin/billing/fiscal-assignments')->assertOk();
        $this->assertTrue($res->json('plans.0.pending'));
    }
}
