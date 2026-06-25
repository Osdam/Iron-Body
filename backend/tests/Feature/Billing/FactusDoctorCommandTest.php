<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactusDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Config "lista para producción" salvo lo que cada test rompa. */
    private function readyConfig(): void
    {
        config([
            'billing.env'      => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
            'billing.numbering.range_id' => 4,
            'billing.numbering.credit_range_id' => 5,
            'billing.defaults.municipality_code' => '41001',
            'billing.company' => ['nit' => '1075265137', 'dv' => '1', 'name' => 'PAJOY MEDINA FREDY ALBERTO'],
            'billing.tax_decision_confirmed' => true,
        ]);
    }

    public function test_blocks_when_tax_decision_not_confirmed(): void
    {
        $this->readyConfig();
        config(['billing.tax_decision_confirmed' => false]);

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('BLOQUEADO')
            ->assertExitCode(1);
    }

    public function test_blocks_when_missing_credit_range(): void
    {
        $this->readyConfig();
        config(['billing.numbering.credit_range_id' => null]);

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('nota crédito')
            ->assertExitCode(1);
    }

    public function test_blocks_when_missing_municipality(): void
    {
        $this->readyConfig();
        config(['billing.defaults.municipality_code' => null]);

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('MUNICIPALITY')
            ->assertExitCode(1);
    }

    public function test_blocks_when_active_plan_without_tax_rate(): void
    {
        $this->readyConfig();
        Plan::create(['name' => 'Premium', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']); // sin tax_rate_id

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('sin tax_rate_id')
            ->assertExitCode(1);
    }

    public function test_passes_when_everything_ready(): void
    {
        $this->readyConfig();
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        Plan::create([
            'name' => 'Premium', 'price' => 100000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id,
        ]);

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('LISTO PARA PRODUCCIÓN')
            ->assertExitCode(0);
    }

    public function test_blocks_when_active_product_without_tax_rate(): void
    {
        $this->readyConfig();
        Product::create(['name' => 'Proteína', 'sale_price' => 50000, 'stock' => 5, 'active' => true]); // sin tax_rate_id

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('sin tax_rate_id')
            ->assertExitCode(1);
    }

    public function test_blocks_on_production_server_with_sandbox_env(): void
    {
        $this->readyConfig();
        $this->app['env'] = 'production';
        config(['billing.env' => 'sandbox', 'billing.base_url' => 'https://api-sandbox.factus.com.co']);

        $this->artisan('billing:factus-doctor')
            ->expectsOutputToContain('FACTUS_ENV=sandbox')
            ->assertExitCode(1);
    }
}
