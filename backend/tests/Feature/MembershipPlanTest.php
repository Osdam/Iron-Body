<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_plans_returns_only_active_plans_for_flutter(): void
    {
        Plan::create([
            'name' => 'Mensual',
            'price' => 120000,
            'duration_days' => 30,
            'benefits' => json_encode([
                'Acceso ilimitado al gimnasio',
                'Evaluacion fisica inicial',
            ]),
            'is_recommended' => false,
            'active' => true,
        ]);

        Plan::create([
            'name' => 'Inactivo',
            'price' => 90000,
            'duration_days' => 30,
            'benefits' => 'No debe salir',
            'active' => false,
        ]);

        $response = $this->getJson('/api/membership-plans');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mensual')
            ->assertJsonPath('data.0.period', '1 mes')
            ->assertJsonPath('data.0.months', 1)
            ->assertJsonPath('data.0.price', 120000)
            ->assertJsonPath('data.0.benefits.0', 'Acceso ilimitado al gimnasio')
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_membership_plan_detail_hides_inactive_plans(): void
    {
        $plan = Plan::create([
            'name' => 'Pausado',
            'price' => 120000,
            'duration_days' => 30,
            'active' => false,
        ]);

        $this->getJson("/api/membership-plans/{$plan->id}")
            ->assertNotFound();
    }
}
