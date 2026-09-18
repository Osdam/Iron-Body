<?php

namespace Tests\Feature\Commercial;

use App\Models\Plan;
use App\Services\Commercial\Tools\Commercial\ListPlansTool;
use App\Services\Commercial\Tools\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Plan::sellable()` es la única autoridad sobre qué se vende. Un plan activo
 * pero no vendible (una comisión interna, una campaña cerrada) no puede salir
 * por la herramienta que alimenta al asesor: el CRM cotizaría lo que el negocio
 * decidió no vender.
 */
class ListPlansToolSellableTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_plans_only_returns_what_the_business_sells(): void
    {
        $ok = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        Plan::create(['name' => 'PLAN SEPTIEMBRE', 'price' => 50000, 'duration_days' => 30, 'active' => true, 'sellable' => false, 'benefits' => json_encode([])]);
        Plan::create(['name' => 'Comisión de personalizados', 'price' => 0, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode([])]);
        Plan::create(['name' => 'Plan Viejo', 'price' => 60000, 'duration_days' => 30, 'active' => false, 'benefits' => json_encode([])]);

        $r = app(ListPlansTool::class)->execute([], new ToolContext(requestedBy: 'test'));

        $this->assertNull($r->errorCode, json_encode($r->toArray()));
        $this->assertSame([$ok->id], array_column($r->data['plans'], 'plan_id'), 'ni el no vendible, ni el de precio 0, ni el inactivo');
    }

    public function test_without_sellable_plans_the_tool_fails_instead_of_offering_the_unsellable(): void
    {
        Plan::create(['name' => 'PLAN SEPTIEMBRE', 'price' => 50000, 'duration_days' => 30, 'active' => true, 'sellable' => false, 'benefits' => json_encode([])]);

        $r = app(ListPlansTool::class)->execute([], new ToolContext(requestedBy: 'test'));

        $this->assertNotNull($r->errorCode, 'sin catálogo vendible no hay nada que listar');
        $this->assertSame([], $r->data);
    }
}
