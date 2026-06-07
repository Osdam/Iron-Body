<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\NutritionOcrScan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OCR de etiqueta nutricional (funcionalidad AVANZADA, modo seguro).
 *
 * - Si NUTRITION_OCR_ENABLED=false → no disponible (la app ofrece creación manual).
 * - Con OCR habilitado, extrae texto y arma un DRAFT que el usuario DEBE revisar
 *   antes de guardar. Nunca inventa macros ni guarda un alimento sin confirmación.
 *
 * El motor de extracción server-side queda como adapter: si el cliente ya hizo
 * OCR (ML Kit) y envía `text`, el backend lo parsea; si no hay motor server, el
 * scan queda `failed` controlado (sin fingir resultados).
 */
class NutritionOcrService
{
    public function isEnabled(): bool
    {
        return (bool) config('nutrition.ocr.enabled');
    }

    /** Crea el scan, guarda la imagen en disco privado y procesa si hay texto. */
    public function createScan(Member $member, ?UploadedFile $image, ?string $providedText = null): NutritionOcrScan
    {
        $path = null;
        if ($image) {
            $path = $image->store("members/{$member->member_uuid}/nutrition_ocr", 'local');
        }

        $scan = NutritionOcrScan::create([
            'member_id'  => $member->id,
            'image_path' => $path,
            'status'     => NutritionOcrScan::STATUS_PENDING,
        ]);

        Log::info('nutrition.ocr.scan', ['member_id' => $member->id, 'scan' => $scan->uuid]);

        $text = $providedText ?? $this->extractTextFromImage($path);
        if (! $text || trim($text) === '') {
            $scan->update([
                'status'        => NutritionOcrScan::STATUS_FAILED,
                'error_message' => 'No pudimos leer la etiqueta automáticamente. Crea el alimento manualmente.',
            ]);
            return $scan;
        }

        $parsed = $this->parseNutritionLabelText($text);
        $scan->update([
            'status'           => NutritionOcrScan::STATUS_PROCESSED,
            'extracted_text'   => mb_substr($text, 0, 4000),
            'parsed_payload'   => $this->buildDraftFoodFromParsedLabel($parsed),
            'confidence_score' => $parsed['confidence'],
        ]);
        return $scan;
    }

    /**
     * Extrae texto de la imagen (adapter). Sin motor OCR server-side configurado
     * devuelve null → scan failed controlado. Aquí se integraría Tesseract o un
     * proveedor de visión cuando se habilite.
     */
    public function extractTextFromImage(?string $path): ?string
    {
        // No hay motor OCR en el servidor todavía. No se finge resultado.
        return null;
    }

    /** Parsea campos de una tabla nutricional desde texto plano (ES/EN). */
    public function parseNutritionLabelText(string $text): array
    {
        $t = mb_strtolower($text);
        $grab = function (array $labels) use ($t): ?float {
            foreach ($labels as $label) {
                if (preg_match('/' . $label . '\D{0,15}([\d]+[\.,]?[\d]*)/u', $t, $m)) {
                    return (float) str_replace(',', '.', $m[1]);
                }
            }
            return null;
        };

        $values = [
            'serving_size' => $grab(['porci[oó]n', 'serving size', 'tama[nñ]o de porci[oó]n']),
            'calories'     => $grab(['calor[ií]as', 'energ[ií]a', 'calories', 'kcal']),
            'protein'      => $grab(['prote[ií]nas?', 'protein']),
            'carbs'        => $grab(['carbohidratos?', 'carbohydrate', 'carbs']),
            'fat'          => $grab(['grasas? totales?', 'grasas?', 'total fat', 'fat']),
            'sugar'        => $grab(['az[uú]cares?', 'sugars?']),
            'fiber'        => $grab(['fibra', 'fiber']),
            'sodium'       => $grab(['sodio', 'sodium']),
        ];

        $core = array_filter(
            [$values['calories'], $values['protein'], $values['carbs'], $values['fat']],
            fn ($v) => $v !== null
        );
        $values['confidence'] = round(count($core) / 4, 3);
        return $values;
    }

    /** Arma un borrador de alimento (para revisión) desde los campos parseados. */
    public function buildDraftFoodFromParsedLabel(array $parsed): array
    {
        return [
            'name'         => null, // el usuario lo completa/confirma
            'brand'        => null,
            'serving_size' => $parsed['serving_size'],
            'serving_unit' => 'g',
            'per_serving'  => [
                'calories' => $parsed['calories'],
                'protein'  => $parsed['protein'],
                'carbs'    => $parsed['carbs'],
                'fat'      => $parsed['fat'],
                'sugar'    => $parsed['sugar'],
                'fiber'    => $parsed['fiber'],
                'sodium'   => $parsed['sodium'],
            ],
            'confidence_score' => $parsed['confidence'],
            'needs_review'     => true,
        ];
    }

    /**
     * Confirma el borrador (ya revisado por el usuario) creando un alimento real
     * privado del miembro (source=ocr). Valida valores; nunca negativos.
     */
    public function confirmDraftFood(Member $member, NutritionOcrScan $scan, array $data): NutritionFood
    {
        $servingSize = isset($data['serving_size']) ? max(0.01, (float) $data['serving_size']) : null;
        $perServing = [
            'calories' => $this->pos($data['calories'] ?? null),
            'protein'  => $this->pos($data['protein'] ?? null),
            'carbs'    => $this->pos($data['carbs'] ?? null),
            'fat'      => $this->pos($data['fat'] ?? null),
            'sugar'    => $this->pos($data['sugar'] ?? null),
            'fiber'    => $this->pos($data['fiber'] ?? null),
            'sodium'   => $this->pos($data['sodium'] ?? null),
        ];
        // Deriva per_100g desde la porción para guardar coherente.
        $per100 = [];
        if ($servingSize && $servingSize > 0) {
            $f = 100 / $servingSize;
            foreach ($perServing as $k => $v) {
                $per100[$k] = $v === null ? null : round($v * $f, 2);
            }
        }

        $food = NutritionFood::create(array_merge(
            [
                'source'              => 'ocr',
                'name'                => trim((string) ($data['name'] ?? 'Alimento (OCR)')),
                'brand'               => $data['brand'] ?? null,
                'barcode'             => $data['barcode'] ?? null,
                'serving_size'        => $servingSize,
                'serving_unit'        => $data['serving_unit'] ?? 'g',
                'created_by_member_id' => $member->id,
                'is_public'           => false,
                'verified'            => false,
                'confidence_score'    => $scan->confidence_score,
            ],
            $this->columns($per100, '_per_100g'),
            $this->columns($perServing, '_per_serving'),
        ));

        $scan->update(['created_food_id' => $food->id]);
        return $food;
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
}
