<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\NutritionOcrScan;
use App\Models\User;
use App\Services\Nutrition\NutritionLabelParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OCR real de etiqueta nutricional (Tesseract, modo seguro). Cubre: estado
 * deshabilitado, binario ausente, validación de imagen, parser ES/EN, y el
 * flujo de confirmación humana (crear/completar) sin inventar macros.
 */
class NutritionOcrTest extends TestCase
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

    private function enableOcr(string $provider = 'tesseract'): void
    {
        config([
            'nutrition.ocr.enabled' => true,
            'nutrition.ocr.provider' => $provider,
            'nutrition.ocr.require_user_confirmation' => true,
            'nutrition.ocr.store_original' => false,
        ]);
    }

    /** Crea un scan PROCESSED enviando texto ya extraído (OCR de cliente). */
    private function processedScan(Member $m, string $text): string
    {
        $res = $this->postJson('/api/nutrition/ocr/scan', ['text' => $text], $this->auth($m))
            ->assertOk()->assertJsonPath('status', NutritionOcrScan::STATUS_PROCESSED);
        return $res->json('data.uuid');
    }

    // ── Estados controlados ─────────────────────────────────────────────────

    public function test_ocr_disabled_returns_unavailable(): void
    {
        $m = $this->member();
        config(['nutrition.ocr.enabled' => false]);
        $this->postJson('/api/nutrition/ocr/scan', [], $this->auth($m))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_tesseract_binary_missing_returns_controlled_error(): void
    {
        $m = $this->member();
        $this->enableOcr('tesseract');
        config(['nutrition.ocr.tesseract_bin' => '/nonexistent/tesseract']);

        $img = UploadedFile::fake()->create('label.jpg', 100, 'image/jpeg');
        $this->postJson('/api/nutrition/ocr/scan', ['image' => $img], $this->auth($m))
            ->assertOk()
            ->assertJsonPath('status', NutritionOcrScan::STATUS_FAILED)
            ->assertJsonPath('ok', false);
        $this->assertNotNull(NutritionOcrScan::first()->error_message);
    }

    public function test_image_too_large_is_rejected(): void
    {
        $m = $this->member();
        $this->enableOcr();
        // > 8 MB (8192 KB) → falla la validación del request.
        $big = UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg');
        $this->postJson('/api/nutrition/ocr/scan', ['image' => $big], $this->auth($m))
            ->assertStatus(422);
    }

    public function test_invalid_mime_is_rejected(): void
    {
        $m = $this->member();
        $this->enableOcr();
        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
        $this->postJson('/api/nutrition/ocr/scan', ['image' => $txt], $this->auth($m))
            ->assertStatus(422);
    }

    public function test_no_image_stored_when_store_original_false(): void
    {
        Storage::fake('local');
        $m = $this->member();
        $this->enableOcr();
        config(['nutrition.ocr.store_original' => false]);

        $text = "Calorías 350 kcal\nProteínas 7\nCarbohidratos 79\nGrasas 0,8";
        $img = UploadedFile::fake()->create('label.jpg', 100, 'image/jpeg');
        $this->postJson('/api/nutrition/ocr/scan', ['image' => $img, 'text' => $text], $this->auth($m))
            ->assertOk()->assertJsonPath('status', NutritionOcrScan::STATUS_PROCESSED);

        $this->assertNull(NutritionOcrScan::first()->image_path);
    }

    // ── Parser (unitario, ES/EN) ────────────────────────────────────────────

    public function test_parser_extracts_core_macros_from_spanish_label(): void
    {
        $parser = new NutritionLabelParser();
        $text = "Tamaño de porción 30 g\nCalorías 120\nProteínas 4\n"
            . "Carbohidratos 22\nGrasa total 2\nAzúcares 1\nFibra 3\nSodio 50 mg";
        $r = $parser->parse($text);

        $this->assertEquals(30.0, $r['serving_size']);
        $this->assertEquals('g', $r['serving_unit']);
        $this->assertEquals(120.0, $r['macros']['calories']);
        $this->assertEquals(4.0, $r['macros']['protein']);
        $this->assertEquals(22.0, $r['macros']['carbs']);
        $this->assertEquals(2.0, $r['macros']['fat']);
        $this->assertEquals(50.0, $r['macros']['sodium']);
        $this->assertEquals(1.0, $r['confidence']);
    }

    public function test_parser_converts_kj_to_kcal(): void
    {
        $parser = new NutritionLabelParser();
        // 1465 kJ ≈ 350.1 kcal (sin kcal explícito en el texto).
        $r = $parser->parse("Valor energético 1465 kJ\nProteínas 7");
        $this->assertEqualsWithDelta(350.1, $r['macros']['calories'], 0.5);
    }

    public function test_parser_handles_comma_decimals(): void
    {
        $parser = new NutritionLabelParser();
        $r = $parser->parse("Grasas 0,8 g\nProteínas 7,5");
        $this->assertEquals(0.8, $r['macros']['fat']);
        $this->assertEquals(7.5, $r['macros']['protein']);
    }

    public function test_parser_returns_null_not_zero_when_missing(): void
    {
        $parser = new NutritionLabelParser();
        $r = $parser->parse("Calorías 100\nProteínas 5");
        $this->assertNull($r['macros']['sugar']);
        $this->assertNull($r['macros']['fiber']);
        $this->assertNull($r['macros']['sodium']);
    }

    public function test_parser_per_100_basis_when_no_serving(): void
    {
        $parser = new NutritionLabelParser();
        $r = $parser->parse("Por 100 g\nCalorías 350\nProteínas 7\nCarbohidratos 79\nGrasas 1");
        $this->assertEquals(100.0, $r['serving_size']);
        $this->assertEquals('g', $r['serving_unit']);
    }

    public function test_parser_inline_format_per_100g(): void
    {
        $parser = new NutritionLabelParser();
        // Texto plano de una sola línea (etiqueta sin tabla marcada).
        $r = $parser->parse(
            'Información Nutricional (100 g): Calorías 348, Proteína 9 g, '
            . 'Carbohidratos totales 79 g, Grasa total 1,6 g, Sodio 5 mg'
        );
        $this->assertEquals(100.0, $r['serving_size']);
        $this->assertEquals('100', $r['basis']);
        $this->assertEquals(348.0, $r['macros']['calories']);
        $this->assertEquals(9.0, $r['macros']['protein']);
        $this->assertEquals(79.0, $r['macros']['carbs']);
        $this->assertEquals(1.6, $r['macros']['fat']);
        $this->assertEquals(5.0, $r['macros']['sodium']);
    }

    public function test_parser_serving_from_parenthesized_weight(): void
    {
        $parser = new NutritionLabelParser();
        // No debe capturar el "1/3"; el peso real es 80 g.
        $r = $parser->parse("Tamaño de porción 1/3 de paquete (80 g)\nCalorías 120");
        $this->assertEquals(80.0, $r['serving_size']);
        $this->assertEquals('serving', $r['basis']);
    }

    public function test_parser_extras_and_portions_per_package(): void
    {
        $parser = new NutritionLabelParser();
        $r = $parser->parse(
            "Porciones por envase 4\nTamaño de porción 30 g\nCalorías 150\n"
            . "Grasa total 8 g\nGrasa saturada 3 g\nGrasa trans 0,2 g\n"
            . "Carbohidratos totales 18 g\nAzúcares totales 10 g\nAzúcares añadidos 6 g"
        );
        $this->assertEquals(4, $r['portions_per_package']);
        $this->assertEquals(3.0, $r['extras']['saturated_fat']);
        $this->assertEquals(0.2, $r['extras']['trans_fat']);
        $this->assertEquals(6.0, $r['extras']['added_sugar']);
        // El azúcar total no se confunde con el añadido.
        $this->assertEquals(10.0, $r['macros']['sugar']);
        // La grasa total no se confunde con la saturada/trans.
        $this->assertEquals(8.0, $r['macros']['fat']);
    }

    // ── Confirmación humana ─────────────────────────────────────────────────

    public function test_confirm_food_creates_new_user_food_when_no_food_uuid(): void
    {
        $m = $this->member();
        $this->enableOcr();
        $uuid = $this->processedScan($m, "Tamaño de porción 100 g\nCalorías 350\nProteínas 7\nCarbohidratos 79\nGrasas 1");

        $this->postJson("/api/nutrition/ocr/{$uuid}/confirm-food", [
            'confirmed' => true,
            'name' => 'Avena instantánea',
            'serving_size' => 100,
            'calories' => 350, 'protein' => 7, 'carbs' => 79, 'fat' => 1,
        ], $this->auth($m))
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Avena instantánea')
            ->assertJsonPath('data.is_complete', true)
            ->assertJsonPath('data.source', 'ocr');

        $this->assertEquals(NutritionOcrScan::STATUS_CONFIRMED, NutritionOcrScan::first()->status);
    }

    public function test_confirm_food_updates_incomplete_existing_food(): void
    {
        $m = $this->member();
        $this->enableOcr();
        // Alimento externo público SIN macros (incompleto).
        $food = NutritionFood::create([
            'source' => 'open_food_facts', 'name' => 'Galleta X', 'is_public' => true,
            'barcode' => '7700000000001', 'serving_size' => 100, 'serving_unit' => 'g',
        ]);
        $this->assertFalse($food->isMacroComplete());

        $uuid = $this->processedScan($m, "Calorías 480\nProteínas 6\nCarbohidratos 64\nGrasas 22");

        $this->postJson("/api/nutrition/ocr/{$uuid}/confirm-food", [
            'confirmed' => true,
            'food_uuid' => $food->uuid,
            'name' => 'Galleta X',
            'serving_size' => 100,
            'calories' => 480, 'protein' => 6, 'carbs' => 64, 'fat' => 22,
        ], $this->auth($m))
            ->assertStatus(201)
            ->assertJsonPath('data.uuid', $food->uuid)
            ->assertJsonPath('data.is_complete', true);

        // No se duplicó: sigue habiendo un solo alimento.
        $this->assertEquals(1, NutritionFood::count());
        $this->assertTrue($food->fresh()->isMacroComplete());
    }

    public function test_confirm_food_requires_user_confirmation(): void
    {
        $m = $this->member();
        $this->enableOcr(); // require_user_confirmation = true
        $uuid = $this->processedScan($m, "Calorías 350\nProteínas 7\nCarbohidratos 79\nGrasas 1");

        // Sin `confirmed` → rechazado, no se guarda nada.
        $this->postJson("/api/nutrition/ocr/{$uuid}/confirm-food", [
            'name' => 'Avena', 'serving_size' => 100,
            'calories' => 350, 'protein' => 7, 'carbs' => 79, 'fat' => 1,
        ], $this->auth($m))
            ->assertStatus(422);

        $this->assertEquals(0, NutritionFood::count());
    }
}
