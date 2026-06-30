<?php

namespace Tests\Feature\Marketing;

use App\Models\Plan;
use App\Services\Marketing\MarketingAgentDoctorService;
use App\Services\Marketing\SalesPaymentReadinessService;
use Database\Seeders\MarketingKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * marketing:agent-doctor — diagnóstico integral del agente comercial. Valida
 * cerebro, Meta, conocimiento, plan mensual, Wompi y auto-execute SIN secretos.
 */
class AgentDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox',
            'public_key' => 'pub_test', 'integrity_secret' => 'int_test',
            'checkout' => ['base_url' => 'https://checkout.wompi.co/p/'],
        ]));
    }

    public function test_report_flags_missing_knowledge_and_plan(): void
    {
        $report = app(MarketingAgentDoctorService::class)->report();

        $this->assertFalse($report['checks']['knowledge']['ok']);
        $this->assertFalse($report['checks']['monthly_plan']['ok']);
        $this->assertFalse($report['ready']);
        // Meta off → dry_run; no expone secretos.
        $this->assertSame('dry_run', $report['checks']['meta']['status']);
    }

    public function test_report_ready_when_knowledge_and_plan_present(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);

        $report = app(MarketingAgentDoctorService::class)->report();

        $this->assertTrue($report['checks']['knowledge']['ok']);
        $this->assertTrue($report['checks']['monthly_plan']['ok']);
        // Wompi sandbox configurado → NO_PRODUCTIVO: no entrega links sandbox como reales.
        $wompi = $report['checks']['wompi_payment'];
        $this->assertFalse($wompi['productive']);
        $this->assertTrue($wompi['sandbox']);
        $this->assertTrue($wompi['sandbox_links_blocked']);
        $this->assertStringContainsString('NO_PRODUCTIVO', $wompi['status']);
        // Garantía explícita en el reporte.
        $this->assertStringContainsString('sandbox', strtolower(implode(' ', $report['safety'])));
    }

    public function test_command_runs_without_secrets(): void
    {
        (new MarketingKnowledgeSeeder())->run();
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        config()->set('services.openai.api_key', 'sk-super-secret-value');

        $this->artisan('marketing:agent-doctor')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('sk-super-secret-value');
    }
}
