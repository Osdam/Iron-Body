<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estadísticas de constancia (server-side, datos reales): adherencia, racha,
 * cumplimiento y estados por día. Maneja usuarios sin registros sin inventar.
 */
class NutritionStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function member(): Member
    {
        $this->seq++;
        $user = User::create([
            'name' => 'S' . $this->seq, 'email' => "s{$this->seq}@example.com", 'password' => 'secret',
            'document' => '20' . $this->seq, 'phone' => '300222' . $this->seq, 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'S' . $this->seq, 'email' => "s{$this->seq}@example.com",
            'document_number' => '20' . $this->seq, 'phone' => '300222' . $this->seq,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    public function test_stats_empty_for_user_without_records(): void
    {
        $m = $this->member();
        $res = $this->getJson('/api/nutrition/stats?range=week', $this->auth($m))->assertOk();
        $res->assertJsonPath('has_data', false)
            ->assertJsonPath('summary.days_registered', 0)
            ->assertJsonPath('summary.current_streak', 0);
        $this->assertCount(7, $res->json('table'));
        $this->assertEquals('no_record', $res->json('table.0.state'));
        // No inventa cumplimiento cuando no hay registro.
        $this->assertNull($res->json('table.6.compliance'));
    }

    public function test_stats_reflect_a_registered_day(): void
    {
        $m = $this->member();
        // Alimento que, en 100 g, alcanza justo la meta diaria (~perfecto).
        $food = NutritionFood::create([
            'source' => 'iron_body', 'name' => 'Comida del día', 'is_public' => true,
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 2200, 'protein_per_100g' => 150,
            'carbs_per_100g' => 250, 'fat_per_100g' => 70,
            'calories_per_serving' => 2200, 'protein_per_serving' => 150,
            'carbs_per_serving' => 250, 'fat_per_serving' => 70,
        ]);
        $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'lunch', 'quantity' => 100, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);

        $res = $this->getJson('/api/nutrition/stats?range=week', $this->auth($m))->assertOk();
        $res->assertJsonPath('has_data', true)
            ->assertJsonPath('summary.days_registered', 1)
            ->assertJsonPath('summary.current_streak', 1)
            ->assertJsonPath('summary.best_streak', 1);
        // Último día (hoy) registrado y en su meta.
        $today = $res->json('table.6');
        $this->assertEquals('perfect', $today['state']);
        $this->assertEquals(2200.0, $today['calories']);
        $this->assertEquals(1, $res->json('summary.days_in_range'));
        $this->assertEquals(100, $res->json('compliance.calories.percent'));
    }

    public function test_stats_month_range(): void
    {
        $m = $this->member();
        $res = $this->getJson('/api/nutrition/stats?range=month', $this->auth($m))->assertOk();
        $res->assertJsonPath('range', 'month');
        $this->assertCount(30, $res->json('table'));
    }
}
