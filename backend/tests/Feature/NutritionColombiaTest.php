<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\User;
use App\Services\Nutrition\NutritionColombiaClassifier;
use App\Services\Nutrition\NutritionFoodNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobertura Colombia: priorización en búsqueda, barcode local Colombia antes de
 * OFF, clasificación de cadenas/marcas y manejo de incompletos (nunca 0).
 */
class NutritionColombiaTest extends TestCase
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

    private function food(array $over = []): NutritionFood
    {
        return NutritionFood::create(array_merge([
            'source' => 'open_food_facts', 'name' => 'Producto', 'is_public' => true,
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 200, 'protein_per_100g' => 5,
            'carbs_per_100g' => 30, 'fat_per_100g' => 4,
            'calories_per_serving' => 200, 'protein_per_serving' => 5,
            'carbs_per_serving' => 30, 'fat_per_serving' => 4,
        ], $over));
    }

    // ── Clasificador ────────────────────────────────────────────────────────

    public function test_classifier_detects_country_stores_brand_prefix(): void
    {
        $c = app(NutritionColombiaClassifier::class)->classify([
            'countries' => 'en:colombia', 'stores' => 'Tiendas D1', 'brand' => 'D1', 'barcode' => '7700000000001',
        ]);
        $this->assertTrue($c['is_colombia']);
        $this->assertEquals('colombia', $c['country']);
        $this->assertContains('D1', $c['retailers']);
        $this->assertGreaterThanOrEqual(100, $c['imported_priority_score']);
    }

    public function test_classifier_imported_product_in_colombia_is_not_excluded(): void
    {
        // Importado (barcode 84… europeo) PERO vendido en Colombia → es Colombia.
        $c = app(NutritionColombiaClassifier::class)->classify([
            'countries' => 'en:colombia,en:spain', 'stores' => 'Éxito', 'brand' => 'Nestlé', 'barcode' => '8400000000001',
        ]);
        $this->assertTrue($c['is_colombia']);
        $this->assertContains('Éxito', $c['retailers']);
    }

    // ── Búsqueda ────────────────────────────────────────────────────────────

    public function test_search_prioritizes_colombian_products(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        // Genérico (sin señales Colombia) vs Colombia (cadena + score).
        $this->food(['name' => 'Arroz genérico', 'source' => 'open_food_facts']);
        $this->food([
            'name' => 'Arroz Diana', 'source' => 'open_food_facts',
            'stores' => 'Éxito', 'country' => 'colombia', 'imported_priority_score' => 80,
        ]);

        $res = $this->getJson('/api/nutrition/foods/search?q=arroz', $this->auth($m))->assertOk();
        $this->assertEquals('Arroz Diana', $res->json('data.0.name'));
        $this->assertTrue($res->json('data.0.is_colombia'));
    }

    public function test_search_arroz_d1_returns_d1_product_first(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        $this->food(['name' => 'Arroz Premium', 'brand' => 'Otra', 'source' => 'open_food_facts']);
        $this->food([
            'name' => 'Arroz Blanco', 'brand' => 'D1', 'normalized_brand' => 'd1',
            'stores' => 'Tiendas D1', 'normalized_store' => 'D1', 'country' => 'colombia',
            'imported_priority_score' => 100, 'source' => 'open_food_facts',
        ]);

        $res = $this->getJson('/api/nutrition/foods/search?q=' . urlencode('arroz d1'), $this->auth($m))->assertOk();
        $this->assertEquals('Arroz Blanco', $res->json('data.0.name'));
        $this->assertContains('D1', $res->json('data.0.retailers'));
    }

    public function test_incomplete_colombian_products_rank_last(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        // Incompleto pero Colombia → debe ir DESPUÉS del completo genérico.
        $this->food(['name' => 'Galleta completa', 'source' => 'open_food_facts']);
        $this->food([
            'name' => 'Galleta Colombia', 'source' => 'open_food_facts',
            'country' => 'colombia', 'imported_priority_score' => 90,
            'calories_per_100g' => null, 'protein_per_100g' => null,
            'carbs_per_100g' => null, 'fat_per_100g' => null,
            'calories_per_serving' => null,
        ]);

        $res = $this->getJson('/api/nutrition/foods/search?q=galleta', $this->auth($m))->assertOk();
        $names = array_column($res->json('data'), 'name');
        $this->assertEquals('Galleta completa', $names[0]);
        $this->assertEquals('Galleta Colombia', $names[array_key_last($names)]);
    }

    // ── Barcode ─────────────────────────────────────────────────────────────

    public function test_barcode_local_colombia_product_wins_before_off(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => true, 'nutrition.openfoodfacts.enabled' => true]);
        $this->food([
            'name' => 'Leche Alpina', 'barcode' => '7700000000123',
            'country' => 'colombia', 'stores' => 'Éxito', 'imported_priority_score' => 80,
        ]);
        // Si tocara OFF, devolvería otro nombre; al ganar el local NO debe ocurrir.
        Http::fake(['*' => Http::response(['status' => 1, 'product' => [
            'code' => '7700000000123', 'product_name' => 'NO DEBERIA',
            'nutriments' => ['energy-kcal_100g' => 999],
        ]])]);

        $res = $this->getJson('/api/nutrition/foods/barcode/7700000000123', $this->auth($m))->assertOk();
        $this->assertEquals('found', $res->json('status'));
        $this->assertEquals('Leche Alpina', $res->json('food.name'));
        Http::assertNothingSent();
    }

    public function test_not_found_barcode_returns_create_and_ocr_options(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => true, 'nutrition.openfoodfacts.enabled' => true]);
        Http::fake(['*' => Http::response(['status' => 0], 200)]); // OFF no lo tiene

        $res = $this->getJson('/api/nutrition/foods/barcode/7709999999999', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'not_found')
            ->assertJsonPath('action_required', 'create_or_scan');
        $this->assertContains('scan_label', $res->json('options'));
        $this->assertContains('create_manual', $res->json('options'));
        $this->assertContains('search_by_name', $res->json('options'));
    }

    // ── Importador ──────────────────────────────────────────────────────────

    public function test_off_import_filters_country_and_detects_stores(): void
    {
        $jsonl = tempnam(sys_get_temp_dir(), 'off') . '.jsonl';
        file_put_contents($jsonl, implode("\n", [
            // Colombia + D1 (completo)
            json_encode(['code' => '7700000000001', 'product_name_es' => 'Arroz D1',
                'brands' => 'D1', 'stores' => 'Tiendas D1', 'countries_tags' => 'en:colombia',
                'nutriments' => ['energy-kcal_100g' => 350, 'proteins_100g' => 7, 'carbohydrates_100g' => 79, 'fat_100g' => 1]]),
            // España, NO Colombia → se omite por --country=colombia
            json_encode(['code' => '8410000000002', 'product_name' => 'Producto España',
                'brands' => 'Marca', 'stores' => 'Mercadona', 'countries_tags' => 'en:spain',
                'nutriments' => ['energy-kcal_100g' => 100, 'proteins_100g' => 1, 'carbohydrates_100g' => 1, 'fat_100g' => 1]]),
            // Importado europeo PERO vendido en Colombia (Éxito) → NO se excluye
            json_encode(['code' => '8400000000003', 'product_name_es' => 'Chocolate Importado',
                'brands' => 'Nestlé', 'stores' => 'Éxito', 'countries_tags' => 'en:colombia,en:france',
                'nutriments' => ['energy-kcal_100g' => 500, 'proteins_100g' => 6, 'carbohydrates_100g' => 60, 'fat_100g' => 30]]),
        ]) . "\n");

        $this->artisan('nutrition:off-import', ['--file' => $jsonl, '--country' => 'colombia'])
            ->assertSuccessful();

        // España omitido; los dos de Colombia importados.
        $this->assertDatabaseHas('nutrition_foods', ['barcode' => '7700000000001', 'country' => 'colombia']);
        $this->assertDatabaseHas('nutrition_foods', ['barcode' => '8400000000003', 'country' => 'colombia']);
        $this->assertDatabaseMissing('nutrition_foods', ['barcode' => '8410000000002']);

        // D1 detectado en stores del importado nacional.
        $d1 = NutritionFood::where('barcode', '7700000000001')->first();
        $this->assertStringContainsStringIgnoringCase('d1', (string) $d1->stores);
        $this->assertGreaterThan(0, (int) $d1->imported_priority_score);

        @unlink($jsonl);
    }

    public function test_off_import_dry_run_writes_nothing(): void
    {
        $jsonl = tempnam(sys_get_temp_dir(), 'off') . '.jsonl';
        file_put_contents($jsonl, json_encode([
            'code' => '7700000000055', 'product_name_es' => 'Dry Run',
            'brands' => 'D1', 'stores' => 'D1', 'countries_tags' => 'en:colombia',
            'nutriments' => ['energy-kcal_100g' => 100, 'proteins_100g' => 1, 'carbohydrates_100g' => 1, 'fat_100g' => 1],
        ]) . "\n");

        $this->artisan('nutrition:off-import', ['--file' => $jsonl, '--country' => 'colombia', '--dry-run' => true])
            ->assertSuccessful();
        // Dry-run no escribe nada en BD.
        $this->assertEquals(0, NutritionFood::where('barcode', '7700000000055')->count());

        @unlink($jsonl);
    }

    public function test_off_import_stores_incomplete_as_incomplete_not_zero(): void
    {
        $jsonl = tempnam(sys_get_temp_dir(), 'off') . '.jsonl';
        file_put_contents($jsonl, json_encode([
            'code' => '7700000000777', 'product_name_es' => 'Producto Sin Macros',
            'brands' => 'Ara', 'stores' => 'Ara', 'countries_tags' => 'en:colombia',
            'nutriments' => [], // sin macros
        ]) . "\n");

        $this->artisan('nutrition:off-import', ['--file' => $jsonl, '--country' => 'colombia'])
            ->assertSuccessful();

        $food = NutritionFood::where('barcode', '7700000000777')->first();
        $this->assertNotNull($food);
        // Incompleto: NULL, nunca 0 falso.
        $this->assertNull($food->calories_per_100g);
        $this->assertFalse($food->isMacroComplete());

        @unlink($jsonl);
    }
}
