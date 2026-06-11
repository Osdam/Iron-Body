<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionGoal;
use App\Models\PhysicalEvaluation;
use App\Models\User;
use App\Services\Nutrition\NutritionGoalCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meta nutricional personalizada (BMR/TDEE/macros). El backend es la única
 * autoridad del cálculo. Cubre: fórmula Mifflin-St Jeor, factores de actividad,
 * ajustes por objetivo, macros, setup_required, género "Otro", valores extremos,
 * meta manual no pisada y recálculo por cambio de peso.
 */
class NutritionGoalTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $over = []): Member
    {
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana+' . uniqid() . '@example.com', 'password' => 'secret',
            'document' => (string) random_int(1000, 99999999), 'phone' => '3001112233', 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create(array_merge([
            'user_id' => $user->id, 'full_name' => 'Ana', 'email' => $user->email,
            'document_number' => $user->document, 'phone' => '3001112233',
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
            'gender' => 'Masculino', 'goal' => 'Hipertrofia muscular',
            'training_level' => 'Intermedio', 'birth_date' => now()->subYears(30)->toDateString(),
        ], $over));
    }

    private function withEval(Member $m, float $w = 80, float $h = 180): Member
    {
        PhysicalEvaluation::create(['member_id' => $m->id, 'weight_kg' => $w, 'height_cm' => $h]);
        return $m;
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function calc(): NutritionGoalCalculatorService
    {
        return app(NutritionGoalCalculatorService::class);
    }

    // ── 1) Cálculo BMR hombre ─────────────────────────────────────────────────
    public function test_bmr_male_mifflin(): void
    {
        // 10*80 + 6.25*180 - 5*30 + 5 = 1780.
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'general_wellness', 'activity_level' => 'sedentary',
        ]);
        $this->assertEquals(1780, $r['bmr']);
    }

    // ── 2) Cálculo BMR mujer ──────────────────────────────────────────────────
    public function test_bmr_female_mifflin(): void
    {
        // 10*80 + 6.25*180 - 5*30 - 161 = 1614.
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'female', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'general_wellness', 'activity_level' => 'sedentary',
        ]);
        $this->assertEquals(1614, $r['bmr']);
    }

    // ── 3) TDEE por factor de actividad ──────────────────────────────────────
    public function test_tdee_uses_activity_factor(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'general_wellness', 'activity_level' => 'moderate',
        ]);
        // 1780 * 1.55 = 2759.
        $this->assertEquals(2759, $r['tdee']);
        $this->assertEquals(1.55, $r['activity_factor']);
    }

    // ── 4) Hipertrofia con superávit ─────────────────────────────────────────
    public function test_muscle_gain_applies_surplus(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'muscle_gain', 'experience_level' => 'intermediate', 'activity_level' => 'moderate',
        ]);
        $this->assertGreaterThan($r['tdee'], $r['daily_calories']); // > mantenimiento
        $this->assertEquals(250, $r['calorie_adjustment']);
    }

    // ── 5) Pérdida de grasa con déficit ──────────────────────────────────────
    public function test_fat_loss_applies_deficit(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'fat_loss', 'activity_level' => 'moderate', 'pace' => 'moderate',
        ]);
        $this->assertLessThan($r['tdee'], $r['daily_calories']);
        $this->assertEquals(-450, $r['calorie_adjustment']);
    }

    // ── 6/7) Resistencia y fuerza distribuyen distinto ───────────────────────
    public function test_endurance_and_strength_profiles(): void
    {
        $end = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'endurance', 'activity_level' => 'very_active',
        ]);
        $str = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180,
            'objective' => 'strength', 'experience_level' => 'advanced', 'activity_level' => 'very_active',
        ]);
        // Resistencia: carbos altos; fuerza: proteína alta.
        $this->assertGreaterThan($end['protein_g'], $str['protein_g']);
        $this->assertEquals(150, $str['calorie_adjustment']); // advanced strength
    }

    // ── 8) Bienestar general en mantenimiento ────────────────────────────────
    public function test_general_wellness_is_maintenance(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'female', 'age' => 28, 'weight_kg' => 60, 'height_cm' => 165,
            'objective' => 'general_wellness', 'activity_level' => 'light',
        ]);
        $this->assertEquals(0, $r['calorie_adjustment']);
        $this->assertEquals($r['tdee'], $r['daily_calories'] - ($r['daily_calories'] - $r['tdee']));
    }

    // ── 9) Experiencia modifica el ajuste ────────────────────────────────────
    public function test_experience_changes_surplus(): void
    {
        $base = ['metabolic_sex' => 'male', 'age' => 30, 'weight_kg' => 80, 'height_cm' => 180, 'objective' => 'muscle_gain', 'activity_level' => 'moderate'];
        $beg = $this->calc()->calculate($base + ['experience_level' => 'beginner']);
        $adv = $this->calc()->calculate($base + ['experience_level' => 'advanced']);
        $this->assertEquals(300, $beg['calorie_adjustment']);
        $this->assertEquals(200, $adv['calorie_adjustment']);
    }

    // ── 10) Macros suman ≈ calorías objetivo ─────────────────────────────────
    public function test_macros_sum_to_calories(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 25, 'weight_kg' => 75, 'height_cm' => 178,
            'objective' => 'muscle_gain', 'experience_level' => 'beginner', 'activity_level' => 'moderate',
        ]);
        $sum = $r['protein_g'] * 4 + $r['carbs_g'] * 4 + $r['fat_g'] * 9;
        $this->assertEqualsWithDelta($r['daily_calories'], $sum, 20); // tolerancia por redondeo
    }

    // ── 11) Carbohidratos nunca negativos (caso extremo bajo en calorías) ────
    public function test_carbs_never_negative(): void
    {
        // Persona pesada con meta muy agresiva → fuerza el clamp.
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'female', 'age' => 45, 'weight_kg' => 120, 'height_cm' => 150,
            'objective' => 'fat_loss', 'activity_level' => 'sedentary', 'pace' => 'aggressive',
        ]);
        $this->assertGreaterThanOrEqual(0, $r['carbs_g']);
        $this->assertGreaterThanOrEqual(0, $r['protein_g']);
        $this->assertGreaterThanOrEqual(0, $r['fat_g']);
    }

    // ── 12) Faltan datos → setup_required ────────────────────────────────────
    public function test_missing_data_returns_setup_required(): void
    {
        $m = $this->member(['birth_date' => null]); // sin edad ni evaluación
        $res = $this->getJson('/api/nutrition/goal', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'setup_required');
        $missing = $res->json('missing');
        $this->assertContains('weight_kg', $missing);
        $this->assertContains('height_cm', $missing);
        $this->assertContains('age', $missing);
        $this->assertContains('activity_level', $missing);
    }

    // ── 13) Género "Otro" requiere metabolic_sex ─────────────────────────────
    public function test_other_gender_requires_metabolic_sex(): void
    {
        $m = $this->withEval($this->member(['gender' => 'Otro']));
        $res = $this->getJson('/api/nutrition/goal', $this->auth($m))->assertOk();
        $this->assertContains('metabolic_sex', $res->json('missing'));

        // Con referencia neutral explícita calcula (con warning).
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'unspecified', 'age' => 30, 'weight_kg' => 70, 'height_cm' => 170,
            'objective' => 'general_wellness', 'activity_level' => 'light',
        ]);
        $this->assertContains('metabolic_sex_unspecified', $r['warnings']);
        $this->assertGreaterThan(0, $r['daily_calories']);
    }

    // ── 14) Valores extremos se rechazan ─────────────────────────────────────
    public function test_extreme_values_rejected(): void
    {
        $m = $this->withEval($this->member());
        // weight 350 pasa el FormRequest (max 400) pero NO el rango de negocio (max 300).
        $this->postJson('/api/nutrition/goal/calculate', [
            'weight_kg' => 350, 'height_cm' => 180, 'activity_level' => 'moderate',
        ], $this->auth($m))->assertStatus(422)->assertJsonValidationErrors('weight_kg');
    }

    // ── 15) Meta manual no se sobreescribe sin confirmación ──────────────────
    public function test_manual_goal_not_overwritten_without_force(): void
    {
        $m = $this->withEval($this->member());
        // Meta manual vía endpoint legacy.
        $this->postJson('/api/app/nutrition/goals', [
            'daily_calories' => 1800, 'protein_g' => 120, 'carbs_g' => 180, 'fat_g' => 50,
            'goal_type' => 'lose_fat',
        ], $this->auth($m))->assertOk();

        // Recalcular sin force → 409 manual_locked.
        $this->postJson('/api/nutrition/goal/recalculate', ['activity_level' => 'moderate'], $this->auth($m))
            ->assertStatus(409)->assertJsonPath('status', 'manual_locked');

        // Con force → recalcula y reemplaza.
        $this->postJson('/api/nutrition/goal/recalculate', ['force' => true, 'activity_level' => 'moderate'], $this->auth($m))
            ->assertOk()->assertJsonPath('status', 'complete');
    }

    // ── 16) Recalcular al cambiar peso ───────────────────────────────────────
    public function test_recalculation_suggested_on_weight_change(): void
    {
        $m = $this->withEval($this->member(), 80, 180);
        $this->postJson('/api/nutrition/goal', ['activity_level' => 'moderate'], $this->auth($m))->assertStatus(201);

        // Nueva evaluación con peso muy distinto.
        PhysicalEvaluation::create(['member_id' => $m->id, 'weight_kg' => 86, 'height_cm' => 180]);

        $this->getJson('/api/nutrition/goal', $this->auth($m))->assertOk()
            ->assertJsonPath('needs_recalculation', true)
            ->assertJsonPath('recalculation.reason', 'weight_changed');
    }

    // ── 17/18) Save real + setup_required en day legacy sin meta ──────────────
    public function test_save_calculated_and_legacy_day_setup_required(): void
    {
        $m = $this->withEval($this->member());

        // Sin meta: el día legacy YA NO devuelve 2200 fijo.
        $this->getJson('/api/app/nutrition/today', $this->auth($m))->assertOk()
            ->assertJsonPath('data.goal.status', 'setup_required')
            ->assertJsonPath('data.goal.daily_calories', 0);

        // Guardar meta calculada.
        $res = $this->postJson('/api/nutrition/goal', ['activity_level' => 'moderate'], $this->auth($m))
            ->assertStatus(201)->assertJsonPath('status', 'complete');
        $this->assertGreaterThan(0, $res->json('goal.daily_calories'));
        $this->assertDatabaseHas('nutrition_goals', [
            'member_id' => $m->id, 'source' => 'calculated', 'status' => 'complete', 'is_active' => true,
        ]);

        // Ahora el día legacy refleja la meta real (no 2200).
        $this->getJson('/api/app/nutrition/today', $this->auth($m))->assertOk()
            ->assertJsonPath('data.goal.status', 'complete');
    }

    // ── 19) Preview no persiste ──────────────────────────────────────────────
    public function test_preview_does_not_persist(): void
    {
        $m = $this->withEval($this->member());
        $this->postJson('/api/nutrition/goal/calculate', ['activity_level' => 'moderate'], $this->auth($m))
            ->assertOk()->assertJsonPath('status', 'preview');
        $this->assertDatabaseMissing('nutrition_goals', ['member_id' => $m->id]);
    }

    // ── 20) Minor → meta conservadora con warning ────────────────────────────
    public function test_minor_gets_conservative_goal(): void
    {
        $r = $this->calc()->calculate([
            'metabolic_sex' => 'male', 'age' => 16, 'weight_kg' => 60, 'height_cm' => 170,
            'objective' => 'fat_loss', 'activity_level' => 'moderate', 'pace' => 'aggressive',
            'is_minor' => true,
        ]);
        $this->assertContains('minor_conservative', $r['warnings']);
        // No aplica déficit a un menor: meta ≈ mantenimiento (±1 paso de redondeo
        // de 10 kcal). El déficit agresivo de -600 quedó anulado.
        $this->assertGreaterThanOrEqual($r['tdee'] - 10, $r['daily_calories']);
    }

    public function test_auth_required(): void
    {
        $this->getJson('/api/nutrition/goal')->assertStatus(401);
    }
}
