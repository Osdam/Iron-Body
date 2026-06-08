<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\NutritionOcrScan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * OCR de etiqueta nutricional (motor real Tesseract en el VPS, modo seguro).
 *
 * Flujo: foto → Tesseract extrae texto → NutritionLabelParser propone macros →
 * el usuario REVISA y confirma → se guarda el alimento en la base propia.
 *
 * Garantías:
 *  - Si NUTRITION_OCR_ENABLED=false → no disponible (creación manual).
 *  - El OCR SOLO propone: nunca guarda un alimento sin confirmación del usuario.
 *  - Nunca inventa macros: campo no detectado = null (no 0).
 *  - No persiste la imagen original salvo NUTRITION_OCR_STORE_ORIGINAL=true
 *    (y entonces en disco privado).
 */
class NutritionOcrService
{
    /** MIME de imagen aceptados para OCR. */
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
    ];

    public function __construct(
        private TesseractNutritionOcrProvider $tesseract,
        private NutritionLabelParser $parser,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('nutrition.ocr.enabled');
    }

    public function requiresConfirmation(): bool
    {
        return (bool) config('nutrition.ocr.require_user_confirmation', true);
    }

    /**
     * Crea el scan, ejecuta el motor OCR y arma un DRAFT para revisión.
     * Si `providedText` viene (OCR de cliente), se parsea directamente.
     */
    public function createScan(
        Member $member,
        ?UploadedFile $image,
        ?string $providedText = null,
        ?string $barcode = null,
    ): NutritionOcrScan {
        $provider = (string) config('nutrition.ocr.provider', 'disabled');

        $scan = NutritionOcrScan::create([
            'member_id' => $member->id,
            'barcode'   => $barcode,
            'provider'  => $provider,
            'status'    => NutritionOcrScan::STATUS_PENDING,
        ]);

        Log::info('nutrition.ocr.scan', [
            'member_id' => $member->id, 'scan' => $scan->uuid, 'provider' => $provider,
        ]);

        // Validación de imagen (tamaño / MIME) antes de tocar el motor.
        if ($image) {
            if ($err = $this->validateImage($image)) {
                return $this->fail($scan, $err);
            }
        }

        // 1) Texto OCR: del cliente o del motor server-side (Tesseract).
        $text = ($providedText !== null && trim($providedText) !== '')
            ? $providedText
            : ($image ? $this->runEngine($image) : null);

        if ($text === null || trim($text) === '') {
            return $this->fail(
                $scan,
                'No pudimos leer la tabla nutricional. Intenta con una foto más clara o '
                . 'completa los datos manualmente.'
            );
        }

        // 2) Persistir la imagen SOLO si está permitido (disco privado).
        $imagePath = null;
        if ($image && config('nutrition.ocr.store_original')) {
            $imagePath = $image->store("members/{$member->member_uuid}/nutrition_ocr", 'local');
        }

        // 3) Parsear la tabla nutricional.
        $parsed = $this->parser->parse($text);
        if (! $this->parser->isReadable($parsed)) {
            return $this->fail(
                $scan,
                'No pudimos leer la tabla nutricional. Intenta con una foto más clara o '
                . 'completa los datos manualmente.',
                $imagePath,
                $text,
            );
        }

        $scan->update([
            'status'           => NutritionOcrScan::STATUS_PROCESSED,
            'image_path'       => $imagePath,
            'extracted_text'   => mb_substr($text, 0, 4000),
            'parsed_payload'   => $this->buildDraftFoodFromParsedLabel($parsed),
            'confidence_score' => $parsed['confidence'],
        ]);

        return $scan;
    }

    /** Ejecuta el motor OCR configurado sobre el archivo temporal subido. */
    private function runEngine(UploadedFile $image): ?string
    {
        $provider = (string) config('nutrition.ocr.provider', 'disabled');
        if ($provider !== 'tesseract') {
            // 'local'/'disabled': sin motor server-side. No se finge resultado.
            return null;
        }
        // Se procesa sobre la ruta temporal del upload (no requiere persistirla).
        return $this->tesseract->extractText($image->getPathname());
    }

    /** Valida tamaño y tipo MIME. Devuelve mensaje de error o null si OK. */
    private function validateImage(UploadedFile $image): ?string
    {
        $maxMb = (int) config('nutrition.ocr.max_image_mb', 8);
        if ($image->getSize() > $maxMb * 1024 * 1024) {
            return "La imagen supera el máximo de {$maxMb} MB. Toma una foto más liviana.";
        }
        $mime = (string) $image->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            return 'Formato de imagen no soportado. Usa una foto JPG, PNG o WEBP.';
        }
        return null;
    }

    /** Marca el scan como fallido controlado (sin inventar nada). */
    private function fail(
        NutritionOcrScan $scan,
        string $message,
        ?string $imagePath = null,
        ?string $text = null,
    ): NutritionOcrScan {
        $scan->update([
            'status'         => NutritionOcrScan::STATUS_FAILED,
            'image_path'     => $imagePath,
            'extracted_text' => $text !== null ? mb_substr($text, 0, 4000) : null,
            'error_message'  => $message,
        ]);
        return $scan;
    }

    /** Arma un borrador de alimento (para revisión) desde los campos parseados. */
    public function buildDraftFoodFromParsedLabel(array $parsed): array
    {
        $m = $parsed['macros'] ?? [];
        return [
            'name'         => null, // el usuario lo completa/confirma
            'brand'        => null,
            'serving_size' => $parsed['serving_size'] ?? null,
            'serving_unit' => $parsed['serving_unit'] ?? 'g',
            'per_serving'  => [
                'calories' => $m['calories'] ?? null,
                'protein'  => $m['protein'] ?? null,
                'carbs'    => $m['carbs'] ?? null,
                'fat'      => $m['fat'] ?? null,
                'sugar'    => $m['sugar'] ?? null,
                'fiber'    => $m['fiber'] ?? null,
                'sodium'   => $m['sodium'] ?? null,
            ],
            'confidence_score' => $parsed['confidence'] ?? 0,
            'warnings'         => $parsed['warnings'] ?? [],
            'needs_review'     => true,
        ];
    }

    /**
     * Confirma el borrador (ya revisado por el usuario). Si se indica
     * `$existingFood`, COMPLETA ese alimento (no duplica); si no, crea uno nuevo
     * privado del miembro (source=ocr). Valida valores; nunca negativos.
     */
    public function confirmDraftFood(
        Member $member,
        NutritionOcrScan $scan,
        array $data,
        ?NutritionFood $existingFood = null,
    ): NutritionFood {
        $servingSize = isset($data['serving_size']) ? max(0.01, (float) $data['serving_size']) : 100.0;
        $perServing = [
            'calories' => $this->pos($data['calories'] ?? null),
            'protein'  => $this->pos($data['protein'] ?? null),
            'carbs'    => $this->pos($data['carbs'] ?? null),
            'fat'      => $this->pos($data['fat'] ?? null),
            'sugar'    => $this->pos($data['sugar'] ?? null),
            'fiber'    => $this->pos($data['fiber'] ?? null),
            'sodium'   => $this->pos($data['sodium'] ?? null),
        ];
        $factor = $servingSize > 0 ? 100 / $servingSize : 1;
        $per100 = [];
        foreach ($perServing as $k => $v) {
            $per100[$k] = $v === null ? null : round($v * $factor, 2);
        }

        if ($existingFood) {
            $food = $this->updateExistingFood($member, $existingFood, $data, $servingSize, $perServing, $per100);
        } else {
            $food = NutritionFood::create(array_merge(
                [
                    'source'               => 'ocr',
                    'name'                 => trim((string) ($data['name'] ?? 'Alimento (OCR)')),
                    'brand'                => $data['brand'] ?? null,
                    'barcode'              => $data['barcode'] ?? $scan->barcode,
                    'serving_size'         => $servingSize,
                    'serving_unit'         => $data['serving_unit'] ?? 'g',
                    'created_by_member_id' => $member->id,
                    'is_public'            => false,
                    'verified'             => false,
                    'confidence_score'     => $scan->confidence_score,
                ],
                $this->columns($per100, '_per_100g'),
                $this->columns($perServing, '_per_serving'),
            ));
        }

        $scan->update([
            'created_food_id' => $food->id,
            'status'          => NutritionOcrScan::STATUS_CONFIRMED,
        ]);
        return $food;
    }

    /** Completa un alimento existente (propio o externo público incompleto). */
    private function updateExistingFood(
        Member $member,
        NutritionFood $food,
        array $data,
        float $servingSize,
        array $perServing,
        array $per100,
    ): NutritionFood {
        if (! empty($data['name'])) {
            $food->name = trim((string) $data['name']);
        }
        if (array_key_exists('brand', $data)) {
            $food->brand = $data['brand'];
        }
        $food->serving_size = $servingSize;
        $food->serving_unit = $data['serving_unit'] ?? ($food->serving_unit ?: 'g');
        foreach ($perServing as $k => $v) {
            $food->{$k . '_per_serving'} = $v;
            $food->{$k . '_per_100g'} = $per100[$k];
        }
        // Completar con datos del usuario sube la confianza del externo.
        if ($food->created_by_member_id !== $member->id && $food->isMacroComplete()) {
            $food->confidence_score = max((float) ($food->confidence_score ?? 0), 0.9);
        }
        $food->save();
        return $food->fresh();
    }

    private function columns(array $macros, string $suffix): array
    {
        $out = [];
        foreach ($macros as $k => $v) {
            $out[$k . $suffix] = $v;
        }
        return $out;
    }

    private function pos($v): ?float
    {
        return ($v === null || ! is_numeric($v)) ? null : max(0.0, round((float) $v, 2));
    }

    /**
     * Parser legado expuesto para compatibilidad. Devuelve el shape plano
     * (serving_size/calories/... + confidence) usado por tests previos.
     */
    public function parseNutritionLabelText(string $text): array
    {
        $parsed = $this->parser->parse($text);
        $m = $parsed['macros'];
        return [
            'serving_size' => $parsed['serving_size'],
            'calories'     => $m['calories'],
            'protein'      => $m['protein'],
            'carbs'        => $m['carbs'],
            'fat'          => $m['fat'],
            'sugar'        => $m['sugar'],
            'fiber'        => $m['fiber'],
            'sodium'       => $m['sodium'],
            'confidence'   => $parsed['confidence'],
        ];
    }
}
