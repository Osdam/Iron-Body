<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionAiRun;
use App\Models\NutritionFood;
use App\Models\User;
use App\Services\Nutrition\Ai\NutritionAiResponseValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Capa IA de Nutrición (OpenAI faked). Cubre: deshabilitado, extracción válida/
 * parcial/imposible, parser, estimación marcada, timeout/429, cost guard,
 * insights, validador y auditoría. La IA nunca verifica ni inventa.
 */
class NutritionAiTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function member(): Member
    {
        $this->seq++;
        $user = User::create([
            'name' => 'A' . $this->seq, 'email' => "a{$this->seq}@example.com", 'password' => 'secret',
            'document' => '30' . $this->seq, 'phone' => '300333' . $this->seq, 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'A' . $this->seq, 'email' => "a{$this->seq}@example.com",
            'document_number' => '30' . $this->seq, 'phone' => '300333' . $this->seq,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function enableAi(): void
    {
        config([
            'nutrition.ai.enabled' => true,
            'nutrition.ai.cache_enabled' => false,
            'nutrition.ai.daily_cost_guard' => 1000,
            'nutrition.ai.rate_limit_per_user' => 50,
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-test',
        ]);
    }

    private function fakeAi(array $contentJson, int $status = 200): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response([
            'model' => 'gpt-test',
            'choices' => [['message' => ['content' => json_encode($contentJson)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], $status)]);
    }

    private function img(): UploadedFile
    {
        return UploadedFile::fake()->create('label.jpg', 80, 'image/jpeg');
    }

    // ── Disabled ──────────────────────────────────────────────────────────────

    public function test_ai_disabled_returns_controlled_fallback(): void
    {
        $m = $this->member();
        config(['nutrition.ai.enabled' => false]);
        $this->postJson('/api/nutrition/ai/label-image', ['image' => $this->img()], $this->auth($m))
            ->assertOk()->assertJsonPath('status', 'ai_unavailable');
    }

    // ── Label image ─────────────────────────────────────────────────────────

    public function test_label_image_success_structured(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi([
            'product_name_detected' => 'Avena', 'serving_size_g' => 100, 'basis_detected' => 'per_100g',
            'calories_per_100g' => 350, 'protein_per_100g' => 12, 'carbs_per_100g' => 60, 'fat_per_100g' => 6,
            'sodium_per_100g' => 5, 'confidence_score' => 0.86,
        ]);
        $res = $this->postJson('/api/nutrition/ai/label-image',
            ['image' => $this->img(), 'barcode' => '7700000000123'], $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.source', 'ai_label_extraction')
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.badge', 'Extraído por IA');
        $this->assertEquals(350.0, $res->json('data.per_100g.calories'));
        // Auditoría registrada.
        $this->assertEquals(1, NutritionAiRun::where('mode', 'label_image')->where('status', 'success')->count());
    }

    public function test_label_image_missing_fields_are_null_partial(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi(['calories_per_100g' => 350, 'confidence_score' => 0.5]); // sin prot/carb/fat
        $res = $this->postJson('/api/nutrition/ai/label-image', ['image' => $this->img()], $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true)->assertJsonPath('status', 'partial');
        $this->assertNull($res->json('data.per_100g.protein'));
        $this->assertContains('protein', $res->json('data.missing_fields'));
    }

    public function test_label_image_impossible_values_fail_validation(): void
    {
        $m = $this->member();
        $this->enableAi();
        // Proteína 150 g por 100 g = físicamente imposible.
        $this->fakeAi(['calories_per_100g' => 100, 'protein_per_100g' => 150,
            'carbs_per_100g' => 10, 'fat_per_100g' => 1, 'confidence_score' => 0.9]);
        $this->postJson('/api/nutrition/ai/label-image', ['image' => $this->img()], $this->auth($m))
            ->assertStatus(422)->assertJsonPath('status', 'validation_failed');
        $this->assertEquals(1, NutritionAiRun::where('status', 'validation_failed')->count());
    }

    // ── Parse text ────────────────────────────────────────────────────────────

    public function test_parse_text_returns_valid_json(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi(['calories_per_100g' => 348, 'protein_per_100g' => 9.8,
            'carbs_per_100g' => 72, 'fat_per_100g' => 1.6, 'fiber_per_100g' => 5.4,
            'sodium_per_100g' => 6, 'confidence_score' => 0.82]);
        $res = $this->postJson('/api/nutrition/ai/parse-text',
            ['text' => 'Información Nutricional (100 g): Calorías 348...'], $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true);
        $this->assertEquals(72.0, $res->json('data.per_100g.carbs'));
    }

    public function test_parse_text_missing_not_zero(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi(['calories_per_100g' => 100, 'protein_per_100g' => 5,
            'carbs_per_100g' => 10, 'fat_per_100g' => 2, 'confidence_score' => 0.8]); // sin sugar
        $res = $this->postJson('/api/nutrition/ai/parse-text', ['text' => 'algo'], $this->auth($m))->assertOk();
        $this->assertNull($res->json('data.per_100g.sugar'));
    }

    // ── Estimate ──────────────────────────────────────────────────────────────

    public function test_estimate_marks_ai_estimated_unverified_private_no_barcode(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi(['product_name_detected' => 'Bandeja paisa', 'serving_size_g' => 500,
            'serving_unit' => 'g', 'calories_per_serving' => 1100, 'protein_per_serving' => 45,
            'carbs_per_serving' => 90, 'fat_per_serving' => 55, 'sodium_per_serving' => 800,
            'barcode' => '123', 'confidence_score' => 0.65]);
        $res = $this->postJson('/api/nutrition/ai/estimate',
            ['description' => 'bandeja paisa', 'quantity' => 500, 'unit' => 'g'], $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true)
            ->assertJsonPath('data.source', 'ai_estimated')
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.is_estimate', true);
        $this->assertNull($res->json('data.barcode')); // nunca inventa barcode
    }

    // ── Errores de proveedor ──────────────────────────────────────────────────

    public function test_openai_timeout_is_controlled(): void
    {
        $m = $this->member();
        $this->enableAi();
        Http::fake(['*/v1/chat/completions' => fn () => throw new ConnectionException('timeout')]);
        $res = $this->postJson('/api/nutrition/ai/parse-text', ['text' => 'x'], $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', false)->assertJsonPath('status', 'timeout');
        $this->assertEquals(1, NutritionAiRun::where('status', 'timeout')->count());
    }

    public function test_openai_rate_limit_is_controlled(): void
    {
        $m = $this->member();
        $this->enableAi();
        $this->fakeAi([], 429);
        $this->postJson('/api/nutrition/ai/parse-text', ['text' => 'x'], $this->auth($m))
            ->assertStatus(429)->assertJsonPath('status', 'rate_limited');
    }

    public function test_cost_guard_blocks_when_over_daily_limit(): void
    {
        $m = $this->member();
        $this->enableAi();
        config(['nutrition.ai.daily_cost_guard' => 1]);
        NutritionAiRun::create(['mode' => 'label_image', 'status' => 'success']); // 1 run hoy → tope
        $this->fakeAi(['calories_per_100g' => 100]);
        $this->postJson('/api/nutrition/ai/parse-text', ['text' => 'x'], $this->auth($m))
            ->assertStatus(429)->assertJsonPath('error_code', 'daily_cost_guard');
    }

    // ── Insights ──────────────────────────────────────────────────────────────

    public function test_insights_empty_for_user_without_records(): void
    {
        $m = $this->member();
        $this->enableAi();
        $res = $this->getJson('/api/nutrition/ai/insights?range=week', $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true)->assertJsonPath('ai_used', false);
        $this->assertNotEmpty($res->json('insights'));
    }

    public function test_insights_with_records_returns_messages(): void
    {
        $m = $this->member();
        $this->enableAi();
        $food = NutritionFood::create([
            'source' => 'iron_body', 'name' => 'Comida', 'is_public' => true,
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 500, 'protein_per_100g' => 30, 'carbs_per_100g' => 50, 'fat_per_100g' => 20,
            'calories_per_serving' => 500, 'protein_per_serving' => 30, 'carbs_per_serving' => 50, 'fat_per_serving' => 20,
        ]);
        $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'lunch', 'quantity' => 100, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);

        $this->fakeAi(['insights' => [
            ['title' => 'Adherencia', 'body' => 'Vas bien esta semana.', 'tone' => 'positive'],
        ]]);
        $res = $this->getJson('/api/nutrition/ai/insights?range=week', $this->auth($m))->assertOk();
        $res->assertJsonPath('ok', true)->assertJsonPath('ai_used', true);
        $this->assertNotEmpty($res->json('insights'));
    }

    // ── Admin review ──────────────────────────────────────────────────────────

    public function test_admin_review_suggests_but_does_not_merge(): void
    {
        $this->enableAi();
        // Dos alimentos con el mismo nombre normalizado → duplicado probable.
        $a = NutritionFood::create(['source' => 'community', 'name' => 'Arroz Diana', 'is_public' => true,
            'visibility' => 'community', 'verification_status' => 'community',
            'serving_size' => 100, 'serving_unit' => 'g', 'calories_per_100g' => 350,
            'protein_per_100g' => 7, 'carbs_per_100g' => 79, 'fat_per_100g' => 1]);
        NutritionFood::create(['source' => 'open_food_facts', 'name' => 'Arroz Diana', 'is_public' => true,
            'serving_size' => 100, 'serving_unit' => 'g', 'calories_per_100g' => 350,
            'protein_per_100g' => 7, 'carbs_per_100g' => 79, 'fat_per_100g' => 1]);

        $this->fakeAi(['suggested_status' => 'community', 'is_probable_duplicate' => true,
            'data_quality' => 'medium', 'notes' => 'Parece duplicado', 'confidence_score' => 0.7]);

        $res = $this->postJson("/api/admin/nutrition/foods/{$a->uuid}/ai-review")->assertOk();
        $res->assertJsonPath('ok', true)
            ->assertJsonPath('suggestions.is_probable_duplicate', true);
        // No ejecutó merge: el alimento sigue sin canonical_food_id.
        $this->assertNull($a->fresh()->canonical_food_id);
        $this->assertEquals(2, NutritionFood::count());
    }

    // ── Validator (unitario) ──────────────────────────────────────────────────

    public function test_validator_rejects_invalid_json(): void
    {
        $v = new NutritionAiResponseValidator();
        $this->assertFalse($v->validateExtraction(null, 'ai_label_extraction')['ok']);
        $this->assertFalse($v->validateExtraction(['calories_per_100g' => -5], 'ai_label_extraction')['ok']);
    }

    public function test_ai_does_not_overwrite_verified_food(): void
    {
        // La IA no escribe alimentos; el guardado pasa por store(), que ante un
        // barcode ya completo (verificado) devuelve el existente sin modificarlo.
        $m = $this->member();
        $verified = NutritionFood::create(['source' => 'iron_body', 'name' => 'Verificado', 'is_public' => true,
            'barcode' => '7700000000999', 'verification_status' => 'verified', 'verified' => true,
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 100, 'protein_per_100g' => 5, 'carbs_per_100g' => 10, 'fat_per_100g' => 2,
            'calories_per_serving' => 100, 'protein_per_serving' => 5, 'carbs_per_serving' => 10, 'fat_per_serving' => 2]);

        $this->postJson('/api/nutrition/foods', [
            'name' => 'Intento IA', 'barcode' => '7700000000999', 'serving_size' => 100,
            'calories' => 999, 'protein' => 99, 'carbs' => 99, 'fat' => 99,
        ], $this->auth($m))->assertStatus(200)->assertJsonPath('deduplicated', true);

        $fresh = $verified->fresh();
        $this->assertEquals(100.0, $fresh->calories_per_100g); // intacto
        $this->assertEquals('verified', $fresh->verification_status);
    }
}
