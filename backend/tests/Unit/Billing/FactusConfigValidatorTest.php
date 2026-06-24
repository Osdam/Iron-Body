<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Factus\FactusConfigValidator;
use Tests\TestCase;

class FactusConfigValidatorTest extends TestCase
{
    public function test_disabled_module_has_no_issues_even_without_credentials(): void
    {
        config([
            'billing.enabled'     => false,
            'billing.env'         => 'sandbox',
            'billing.base_url'    => 'https://api-sandbox.factus.com.co',
            'billing.credentials' => ['username' => '', 'password' => '', 'client_id' => '', 'client_secret' => ''],
        ]);

        $this->assertSame([], FactusConfigValidator::fromConfig()->issues());
    }

    public function test_enabled_without_credentials_reports_issues(): void
    {
        config([
            'billing.enabled'     => true,
            'billing.env'         => 'sandbox',
            'billing.base_url'    => 'https://api-sandbox.factus.com.co',
            'billing.credentials' => ['username' => '', 'password' => '', 'client_id' => '', 'client_secret' => ''],
            'billing.company'     => ['nit' => '', 'name' => ''],
            'billing.numbering'   => ['range_id' => '', 'prefix' => ''],
            'billing.consumer_final' => ['document_type' => '', 'document_number' => '', 'name' => 'Consumidor final'],
        ]);

        $issues = FactusConfigValidator::fromConfig()->issues();

        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, "credencial 'username'")));
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'NUMBERING_RANGE_ID')));
    }

    public function test_production_must_not_point_to_sandbox_url(): void
    {
        config([
            'billing.enabled'  => false,
            'billing.env'      => 'production',
            'billing.base_url' => 'https://api-sandbox.factus.com.co',
        ]);

        $issues = FactusConfigValidator::fromConfig()->issues();

        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'no puede apuntar a sandbox')));
    }
}
