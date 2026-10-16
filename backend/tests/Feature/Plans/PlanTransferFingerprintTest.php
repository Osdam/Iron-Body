<?php

namespace Tests\Feature\Plans;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La huella del escenario fijo, en el motor de la suite. La misma constante
 * se exige en PostgreSQL ({@see PlanTransferPostgresTest}). Ver
 * {@see PlanTransferFingerprintScenario}.
 */
class PlanTransferFingerprintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_huella_del_escenario_fijo_es_la_esperada(): void
    {
        $this->assertSame(PlanTransferFingerprintScenario::EXPECTED, PlanTransferFingerprintScenario::build());
    }
}
