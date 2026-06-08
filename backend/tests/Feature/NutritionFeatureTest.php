<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionDailySummary;
use App\Models\NutritionEntry;
use App\Models\NutritionFood;
use App\Models\NutritionOcrScan;
use App\Models\NutritionRecentFood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Módulo nutricional premium: búsqueda (local + Open Food Facts), barcode,
 * creación manual, tracking, resumen, favoritos/recientes y OCR seguro.
 */
class NutritionFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret',
            'document' => '1010', 'phone' => '3001112233', 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Ana', 'email' => 'ana@example.com',
            'document_number' => '1010', 'phone' => '3001112233',
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function localFood(array $over = []): NutritionFood
    {
        return NutritionFood::create(array_merge([
            'source' => 'iron_body', 'name' => 'Pollo asado', 'is_public' => true,
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 165, 'protein_per_100g' => 31,
            'carbs_per_100g' => 0, 'fat_per_100g' => 3.6,
            'calories_per_serving' => 165, 'protein_per_serving' => 31,
            'carbs_per_serving' => 0, 'fat_per_serving' => 3.6,
        ], $over));
    }

    public function test_auth_required(): void
    {
        $this->getJson('/api/nutrition/foods/search?q=pollo')->assertStatus(401);
    }

    public function test_search_returns_local_foods(): void
    {
        $m = $this->member();
        $this->localFood();
        config(['nutrition.external_search_enabled' => false]);

        $this->getJson('/api/nutrition/foods/search?q=pollo', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.0.name', 'Pollo asado');
    }

    public function test_search_prioritizes_user_foods(): void
    {
        $m = $this->member();
        $this->localFood(['name' => 'Pollo genérico']);
        $this->localFood(['name' => 'Pollo casero', 'is_public' => false, 'source' => 'user', 'created_by_member_id' => $m->id]);
        config(['nutrition.external_search_enabled' => false]);

        $this->getJson('/api/nutrition/foods/search?q=pollo', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Pollo casero'); // del usuario primero
    }

    public function test_search_falls_back_to_open_food_facts_when_local_empty(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => true, 'nutrition.openfoodfacts.enabled' => true]);
        Http::fake([
            '*/cgi/search.pl*' => Http::response(['products' => [[
                'code' => '111', 'product_name' => 'Avena Quaker', 'brands' => 'Quaker',
                'nutriments' => ['energy-kcal_100g' => 370, 'proteins_100g' => 13, 'carbohydrates_100g' => 60, 'fat_100g' => 7],
            ]]]),
        ]);

        $res = $this->getJson('/api/nutrition/foods/search?q=avena', $this->auth($m))->assertOk();
        $res->assertJsonPath('data.0.name', 'Avena Quaker');
        $this->assertDatabaseHas('nutrition_foods', ['source' => 'open_food_facts', 'barcode' => '111']);
    }

    public function test_external_search_failure_returns_controlled_response(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => true]);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->getJson('/api/nutrition/foods/search?q=xyzzy', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data', []);
    }

    public function test_barcode_lookup_uses_local_cache_first(): void
    {
        $m = $this->member();
        $this->localFood(['barcode' => '7702011123', 'name' => 'Producto Cacheado']);
        Http::fake(['*' => Http::response('should-not-be-called', 500)]);

        $this->getJson('/api/nutrition/foods/barcode/7702011123', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('food.name', 'Producto Cacheado');
        Http::assertNothingSent();
    }

    public function test_barcode_lookup_calls_open_food_facts_when_not_cached(): void
    {
        $m = $this->member();
        Http::fake([
            '*/api/v2/product/*' => Http::response([
                'status' => 1,
                'product' => [
                    'code' => '7700001234', 'product_name' => 'Galletas Festival',
                    'brands' => 'Noel',
                    'nutriments' => ['energy-kcal_100g' => 480, 'proteins_100g' => 6, 'carbohydrates_100g' => 70, 'fat_100g' => 20],
                ],
            ]),
        ]);

        $this->getJson('/api/nutrition/foods/barcode/7700001234', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('food.barcode', '7700001234');
        $this->assertDatabaseHas('nutrition_foods', ['barcode' => '7700001234']);
    }

    public function test_barcode_not_found_returns_controlled(): void
    {
        $m = $this->member();
        Http::fake(['*/api/v2/product/*' => Http::response(['status' => 0])]);

        $this->getJson('/api/nutrition/foods/barcode/7709999123', $this->auth($m))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'not_found');
    }

    public function test_create_custom_food_validates_required_and_macros(): void
    {
        $m = $this->member();
        // Falta name + macros negativos.
        $this->postJson('/api/nutrition/foods', [
            'serving_size' => 0, 'calories' => -5,
        ], $this->auth($m))->assertStatus(422);
    }

    public function test_create_custom_food_is_private_and_owned(): void
    {
        $m = $this->member();
        $res = $this->postJson('/api/nutrition/foods', [
            'name' => 'Arepa de la casa', 'serving_size' => 80, 'serving_unit' => 'g',
            'calories' => 200, 'protein' => 5, 'carbs' => 40, 'fat' => 3,
        ], $this->auth($m))->assertStatus(201);

        $uuid = $res->json('data.uuid');
        $this->assertDatabaseHas('nutrition_foods', [
            'uuid' => $uuid, 'source' => 'user', 'is_public' => false,
            'created_by_member_id' => $m->id,
        ]);
        // per_100g derivado: 200 kcal / 80g * 100 = 250.
        $this->assertEqualsWithDelta(250, $res->json('data.per_100g.calories'), 0.5);
    }

    public function test_add_entry_calculates_macros_and_updates_summary(): void
    {
        $m = $this->member();
        $food = $this->localFood([
            'calories_per_100g' => 100, 'protein_per_100g' => 10,
            'carbs_per_100g' => 20, 'fat_per_100g' => 5,
        ]);

        $res = $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'breakfast',
            'entry_date' => '2026-06-07', 'quantity' => 200, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);

        // 200 g → factor 2 sobre per_100g.
        $this->assertEquals(200.0, $res->json('data.macros.calories'));
        $this->assertEquals(20.0, $res->json('data.macros.protein'));
        $this->assertEquals(40.0, $res->json('data.macros.carbs'));
        $this->assertEquals(10.0, $res->json('data.macros.fat'));

        $this->assertDatabaseHas('nutrition_daily_summaries', [
            'member_id' => $m->id, 'summary_date' => '2026-06-07',
            'calories' => 200, 'entry_count' => 1,
        ]);
    }

    public function test_add_entry_ignores_client_macros(): void
    {
        $m = $this->member();
        $food = $this->localFood(['calories_per_100g' => 100]);
        // Aunque el cliente mande calorías falsas, el backend recalcula.
        $res = $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'lunch',
            'quantity' => 100, 'unit' => 'g', 'calories' => 99999, 'macros' => ['calories' => 99999],
        ], $this->auth($m))->assertStatus(201);
        $this->assertEquals(100.0, $res->json('data.macros.calories'));
    }

    public function test_delete_entry_recalculates_summary(): void
    {
        $m = $this->member();
        $food = $this->localFood(['calories_per_100g' => 100]);
        $res = $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'dinner',
            'entry_date' => '2026-06-07', 'quantity' => 100, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);
        $uuid = $res->json('data.uuid');

        $this->deleteJson("/api/nutrition/entries/{$uuid}", [], $this->auth($m))->assertOk();
        $summary = NutritionDailySummary::where('member_id', $m->id)->first();
        $this->assertEquals(0, $summary->entry_count);
        $this->assertEquals(0, $summary->calories);
    }

    public function test_favorite_add_and_remove(): void
    {
        $m = $this->member();
        $food = $this->localFood();
        $this->postJson("/api/nutrition/foods/{$food->uuid}/favorite", [], $this->auth($m))->assertOk();
        $this->assertDatabaseHas('nutrition_favorites', ['member_id' => $m->id, 'food_id' => $food->id]);
        $this->getJson('/api/nutrition/favorites', $this->auth($m))
            ->assertOk()->assertJsonPath('data.0.uuid', $food->uuid);

        $this->deleteJson("/api/nutrition/foods/{$food->uuid}/favorite", [], $this->auth($m))->assertOk();
        $this->assertDatabaseMissing('nutrition_favorites', ['member_id' => $m->id, 'food_id' => $food->id]);
    }

    public function test_recent_food_updated_after_entry(): void
    {
        $m = $this->member();
        $food = $this->localFood();
        $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'snack', 'quantity' => 50, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);

        $this->assertDatabaseHas('nutrition_recent_foods', ['member_id' => $m->id, 'food_id' => $food->id]);
        $this->getJson('/api/nutrition/recent', $this->auth($m))
            ->assertOk()->assertJsonPath('data.0.uuid', $food->uuid);
    }

    public function test_ocr_disabled_returns_unavailable(): void
    {
        $m = $this->member();
        config(['nutrition.ocr.enabled' => false]);
        $this->postJson('/api/nutrition/ocr/scan', [], $this->auth($m))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_ocr_scan_creates_failed_controlled_state_without_engine(): void
    {
        $m = $this->member();
        config(['nutrition.ocr.enabled' => true]);
        // Sin texto ni motor server-side → estado controlado 'failed', no inventa.
        $this->postJson('/api/nutrition/ocr/scan', [], $this->auth($m))
            ->assertOk()
            ->assertJsonPath('status', NutritionOcrScan::STATUS_FAILED);
        $this->assertDatabaseCount('nutrition_ocr_scans', 1);
    }

    public function test_ocr_parses_provided_text_into_draft(): void
    {
        $m = $this->member();
        config(['nutrition.ocr.enabled' => true]);
        $text = "Tamaño de porción 30 g\nCalorías 120\nProteínas 4\nCarbohidratos 22\nGrasas 2\nAzúcares 1\nFibra 3\nSodio 50";
        $res = $this->postJson('/api/nutrition/ocr/scan', ['text' => $text], $this->auth($m))->assertOk();
        $res->assertJsonPath('status', NutritionOcrScan::STATUS_PROCESSED);
        $this->assertEquals(120.0, $res->json('data.draft.per_serving.calories'));
        $this->assertEquals(4.0, $res->json('data.draft.per_serving.protein'));
    }

    public function test_no_external_api_key_exposed(): void
    {
        $m = $this->member();
        config([
            'nutrition.external_search_enabled' => true,
            'nutrition.usda.enabled' => true,
            'nutrition.usda.api_key' => 'SUPERSECRETKEY',
            'nutrition.openfoodfacts.enabled' => false,
        ]);
        Http::fake([
            '*/foods/search*' => Http::response(['foods' => [[
                'description' => 'Banana raw', 'fdcId' => 9999,
                'foodNutrients' => [['nutrientId' => 1008, 'value' => 89]],
            ]]]),
        ]);

        $res = $this->getJson('/api/nutrition/foods/search?q=banana', $this->auth($m))->assertOk();
        $this->assertStringNotContainsString('SUPERSECRETKEY', $res->getContent());
    }

    // ── Macros reales / completitud (regresión macros en 0) ──────────────────

    public function test_barcode_complete_returns_found_with_macros(): void
    {
        $m = $this->member();
        Http::fake([
            '*/api/v2/product/*' => Http::response(['status' => 1, 'product' => [
                'code' => '7700112233', 'product_name_es' => 'Arroz Blanco', 'brands' => 'Florhuila',
                'serving_size' => '50 g',
                'nutriments' => [
                    'energy-kcal_100g' => 350, 'proteins_100g' => 7, 'carbohydrates_100g' => 79, 'fat_100g' => 0.8,
                ],
            ]]),
        ]);

        $res = $this->getJson('/api/nutrition/foods/barcode/7700112233', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'found')
            ->assertJsonPath('food.is_complete', true)
            ->assertJsonPath('food.name', 'Arroz Blanco');
        $this->assertEquals(350.0, $res->json('food.per_100g.calories'));
        $this->assertEquals(79.0, $res->json('food.per_100g.carbs'));
    }

    public function test_energy_in_kj_converts_to_kcal(): void
    {
        $m = $this->member();
        // Solo energía en kJ (1465 kJ ≈ 350 kcal).
        Http::fake([
            '*/api/v2/product/*' => Http::response(['status' => 1, 'product' => [
                'code' => '7700445566', 'product_name' => 'Cereal',
                'nutriments' => [
                    'energy_100g' => 1465, 'proteins_100g' => 7, 'carbohydrates_100g' => 79, 'fat_100g' => 1,
                ],
            ]]),
        ]);

        $res = $this->getJson('/api/nutrition/foods/barcode/7700445566', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'found');
        $this->assertEqualsWithDelta(350, $res->json('food.per_100g.calories'), 2);
    }

    public function test_missing_nutriments_returns_incomplete_not_zero(): void
    {
        $m = $this->member();
        Http::fake([
            '*/api/v2/product/*' => Http::response(['status' => 1, 'product' => [
                'code' => '7700778899', 'product_name' => 'Producto Sin Datos', 'brands' => 'X',
                // sin nutriments
            ]]),
        ]);

        $res = $this->getJson('/api/nutrition/foods/barcode/7700778899', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'incomplete')
            ->assertJsonPath('action_required', 'complete_macros')
            ->assertJsonPath('food.is_complete', false);
        // NO se presentan ceros como válidos: calorías null, no 0.
        $this->assertNull($res->json('food.per_100g.calories'));
        $this->assertContains('calories', $res->json('food.missing_macros'));
    }

    public function test_add_entry_with_incomplete_food_returns_422(): void
    {
        $m = $this->member();
        $food = NutritionFood::create([
            'source' => 'open_food_facts', 'name' => 'Incompleto', 'is_public' => true,
            'barcode' => '7700000001', // sin macros → incompleto
        ]);

        $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'breakfast', 'quantity' => 1, 'unit' => 'serving',
        ], $this->auth($m))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'food_macros_incomplete');
        $this->assertSame(0, NutritionEntry::count());
    }

    public function test_add_entry_complete_returns_summary(): void
    {
        $m = $this->member();
        $food = $this->localFood(['calories_per_100g' => 100, 'protein_per_100g' => 10, 'carbs_per_100g' => 20, 'fat_per_100g' => 5]);

        $res = $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $food->uuid, 'meal_type' => 'breakfast',
            'entry_date' => '2026-06-08', 'quantity' => 100, 'unit' => 'g',
        ], $this->auth($m))->assertStatus(201);

        $res->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Alimento agregado a desayuno.');
        $this->assertEquals(100.0, $res->json('summary.totals.calories'));
        $this->assertCount(1, $res->json('summary.meals.breakfast'));
    }

    public function test_complete_external_food_via_put(): void
    {
        $m = $this->member();
        $food = NutritionFood::create([
            'source' => 'open_food_facts', 'name' => 'Arroz X', 'is_public' => true,
            'barcode' => '7700000002', 'serving_size' => 50,
        ]);
        $this->assertFalse($food->isMacroComplete());

        $this->putJson("/api/nutrition/foods/{$food->uuid}", [
            'calories' => 175, 'protein' => 3.5, 'carbs' => 39.5, 'fat' => 0.4, 'serving_size' => 50,
        ], $this->auth($m))
            ->assertOk()
            ->assertJsonPath('data.is_complete', true);

        $this->assertTrue($food->fresh()->isMacroComplete());
    }

    public function test_search_spanish_normalized_finds_by_name_and_brand(): void
    {
        $m = $this->member();
        $this->localFood(['name' => 'Pechuga de Pollo', 'brand' => 'Pietrán']);
        config(['nutrition.external_search_enabled' => false]);

        // con tildes / mayúsculas → normalizado encuentra igual.
        $this->getJson('/api/nutrition/foods/search?q=POLLO', $this->auth($m))
            ->assertOk()->assertJsonPath('data.0.name', 'Pechuga de Pollo');
    }
}
