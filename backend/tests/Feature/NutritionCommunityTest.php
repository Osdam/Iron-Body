<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Base comunitaria + moderación + OCR 413. Cubre: creación comunitaria por
 * barcode, anti-duplicado/idempotencia, visibilidad para otros usuarios,
 * reportes/ocultamiento, verificación/rechazo admin, USDA y errores controlados.
 */
class NutritionCommunityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function member(): Member
    {
        $this->seq++;
        $user = User::create([
            'name' => 'U' . $this->seq, 'email' => "u{$this->seq}@example.com", 'password' => 'secret',
            'document' => '10' . $this->seq, 'phone' => '300111' . $this->seq, 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'U' . $this->seq, 'email' => "u{$this->seq}@example.com",
            'document_number' => '10' . $this->seq, 'phone' => '300111' . $this->seq,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function createPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Arroz Diana', 'barcode' => '7700000000123',
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories' => 350, 'protein' => 7, 'carbs' => 79, 'fat' => 1,
        ], $over);
    }

    // ── Comunidad ───────────────────────────────────────────────────────────

    public function test_user_created_barcode_becomes_community_product(): void
    {
        $m = $this->member();
        $res = $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($m))
            ->assertStatus(201);
        $res->assertJsonPath('data.source', 'community')
            ->assertJsonPath('data.visibility', 'community')
            ->assertJsonPath('data.is_community', true)
            ->assertJsonPath('data.community_label', 'Aportado por la comunidad')
            ->assertJsonPath('data.is_verified_iron_body', false);
    }

    public function test_private_food_without_barcode_is_private(): void
    {
        $m = $this->member();
        $res = $this->postJson('/api/nutrition/foods', $this->createPayload(['barcode' => null]), $this->auth($m))
            ->assertStatus(201);
        $res->assertJsonPath('data.source', 'user')
            ->assertJsonPath('data.visibility', 'private');
    }

    public function test_duplicate_barcode_does_not_create_duplicate(): void
    {
        $m = $this->member();
        $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($m))->assertStatus(201);
        // Segundo intento del mismo barcode (otra sesión/usuario) → no duplica.
        $m2 = $this->member();
        $this->postJson('/api/nutrition/foods', $this->createPayload(['name' => 'Otro'], ), $this->auth($m2))
            ->assertStatus(200)
            ->assertJsonPath('deduplicated', true);

        $this->assertEquals(1, NutritionFood::where('barcode', '7700000000123')->count());
    }

    public function test_concurrent_same_barcode_is_idempotent(): void
    {
        $m = $this->member();
        // Dos llamadas seguidas (simula carrera): solo un alimento, segunda dedupe.
        $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($m))->assertStatus(201);
        $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($m))
            ->assertStatus(200)->assertJsonPath('deduplicated', true);
        $this->assertEquals(1, NutritionFood::where('barcode', '7700000000123')->count());
    }

    public function test_incomplete_barcode_food_is_completed_not_duplicated(): void
    {
        // Existe un OFF público incompleto con ese barcode.
        $incomplete = NutritionFood::create([
            'source' => 'open_food_facts', 'name' => 'Arroz X', 'is_public' => true,
            'barcode' => '7700000000999', 'serving_size' => 100, 'serving_unit' => 'g',
        ]);
        $this->assertFalse($incomplete->isMacroComplete());

        $m = $this->member();
        $this->postJson('/api/nutrition/foods', $this->createPayload(['barcode' => '7700000000999']), $this->auth($m))
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $incomplete->uuid)
            ->assertJsonPath('data.is_complete', true);
        $this->assertEquals(1, NutritionFood::where('barcode', '7700000000999')->count());
    }

    public function test_community_product_appears_in_search_for_another_user(): void
    {
        $author = $this->member();
        $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))->assertStatus(201);

        $other = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        $res = $this->getJson('/api/nutrition/foods/search?q=' . urlencode('arroz diana'), $this->auth($other))->assertOk();
        $this->assertEquals('Arroz Diana', $res->json('data.0.name'));
        $this->assertTrue($res->json('data.0.is_community'));
    }

    public function test_reported_product_hidden_after_threshold(): void
    {
        config(['nutrition.community.reports_hide_threshold' => 2]);
        $author = $this->member();
        $created = $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))
            ->assertStatus(201)->json('data.uuid');

        $reporter = $this->member();
        $this->postJson("/api/nutrition/foods/{$created}/report", [], $this->auth($reporter))->assertOk();
        $this->postJson("/api/nutrition/foods/{$created}/report", [], $this->auth($reporter))
            ->assertOk()->assertJsonPath('hidden', true);

        // Otro usuario ya no lo ve en búsqueda.
        $viewer = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        $res = $this->getJson('/api/nutrition/foods/search?q=' . urlencode('arroz diana'), $this->auth($viewer))->assertOk();
        $this->assertEmpty($res->json('data'));
    }

    public function test_community_confirmation_increments_on_other_user_use(): void
    {
        $author = $this->member();
        $uuid = $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))
            ->assertStatus(201)->json('data.uuid');

        $other = $this->member();
        $this->postJson('/api/nutrition/entries', [
            'food_uuid' => $uuid, 'meal_type' => 'breakfast', 'quantity' => 100, 'unit' => 'g',
        ], $this->auth($other))->assertStatus(201);

        $this->assertEquals(1, NutritionFood::where('uuid', $uuid)->first()->community_confirmations_count);
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    public function test_admin_can_verify_community_product(): void
    {
        $author = $this->member();
        $uuid = $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))
            ->assertStatus(201)->json('data.uuid');

        $this->postJson("/api/admin/nutrition/foods/{$uuid}/verify", [])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.is_verified_iron_body', true)
            ->assertJsonPath('data.community_label', 'Verificado Iron Body');
    }

    public function test_admin_can_reject_product(): void
    {
        $author = $this->member();
        $uuid = $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))
            ->assertStatus(201)->json('data.uuid');

        $this->postJson("/api/admin/nutrition/foods/{$uuid}/reject", ['reason' => 'datos falsos'])
            ->assertOk()->assertJsonPath('data.verification_status', 'rejected');

        $this->assertFalse(NutritionFood::where('uuid', $uuid)->first()->is_public);
        $this->getJson('/api/admin/nutrition/foods/pending')->assertOk()
            ->assertJsonMissing(['uuid' => $uuid]);
    }

    public function test_admin_pending_lists_community_foods(): void
    {
        $author = $this->member();
        $this->postJson('/api/nutrition/foods', $this->createPayload(), $this->auth($author))->assertStatus(201);
        $res = $this->getJson('/api/admin/nutrition/foods/pending')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    // ── Barcode not found / USDA / OCR 413 ──────────────────────────────────

    public function test_not_found_barcode_returns_actions(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => true, 'nutrition.openfoodfacts.enabled' => true]);
        Http::fake(['*' => Http::response(['status' => 0], 200)]);

        $res = $this->getJson('/api/nutrition/foods/barcode/7709999999999', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'not_found')
            ->assertJsonPath('code', 'food_barcode_not_found');
        $this->assertEquals(['create_manual', 'scan_label', 'search_by_name', 'scan_another'], $res->json('actions'));
    }

    public function test_barcode_resolves_by_variant_upca_to_ean13(): void
    {
        $m = $this->member();
        config(['nutrition.external_search_enabled' => false]);
        // Guardado como EAN-13; el usuario escanea el UPC-A (12) equivalente.
        NutritionFood::create([
            'source' => 'community', 'name' => 'Producto Variante', 'is_public' => true,
            'barcode' => '0036000291452', 'visibility' => 'community', 'verification_status' => 'community',
            'serving_size' => 100, 'serving_unit' => 'g',
            'calories_per_100g' => 100, 'protein_per_100g' => 5,
            'carbs_per_100g' => 10, 'fat_per_100g' => 2,
            'calories_per_serving' => 100, 'protein_per_serving' => 5,
            'carbs_per_serving' => 10, 'fat_per_serving' => 2,
        ]);

        $res = $this->getJson('/api/nutrition/foods/barcode/036000291452', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'found')
            ->assertJsonPath('food.name', 'Producto Variante');
    }

    public function test_barcode_bad_read_returns_invalid(): void
    {
        $m = $this->member();
        // Código demasiado corto → lectura mala controlada (no rompe el flujo).
        $res = $this->getJson('/api/nutrition/foods/barcode/123', $this->auth($m))->assertOk();
        $res->assertJsonPath('status', 'invalid')
            ->assertJsonPath('reason', 'bad_read');
    }

    public function test_usda_fallback_for_generic_query(): void
    {
        $m = $this->member();
        config([
            'nutrition.external_search_enabled' => true,
            'nutrition.openfoodfacts.enabled' => false,
            'nutrition.usda.enabled' => true,
            'nutrition.usda.api_key' => 'KEY',
        ]);
        Http::fake(['*/foods/search*' => Http::response(['foods' => [[
            'description' => 'Rice white cooked', 'fdcId' => 1234,
            'foodNutrients' => [
                ['nutrientId' => 1008, 'value' => 130], ['nutrientId' => 1003, 'value' => 2.7],
                ['nutrientId' => 1005, 'value' => 28], ['nutrientId' => 1004, 'value' => 0.3],
            ],
        ]]])]);

        $res = $this->getJson('/api/nutrition/foods/search?q=rice', $this->auth($m))->assertOk();
        $sources = array_column($res->json('data'), 'source');
        $this->assertContains('usda', $sources);
    }

    public function test_ocr_oversized_image_returns_json_controlled(): void
    {
        $m = $this->member();
        config(['nutrition.ocr.enabled' => true, 'nutrition.ocr.max_image_mb' => 8]);
        $big = UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg'); // 9 MB
        $this->postJson('/api/nutrition/ocr/scan', ['image' => $big], $this->auth($m))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ocr_image_too_large')
            ->assertJsonPath('ok', false);
    }

    public function test_post_too_large_exception_renders_json(): void
    {
        $handler = app(ExceptionHandler::class);
        $request = Request::create('/api/nutrition/ocr/scan', 'POST');
        $response = $handler->render($request, new PostTooLargeException());
        $this->assertEquals(413, $response->getStatusCode());
        $this->assertEquals('ocr_image_too_large', json_decode($response->getContent())->code);
    }
}
