<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionFood;
use App\Models\NutritionOcrScan;
use App\Services\Nutrition\NutritionOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OCR de etiqueta nutricional (motor Tesseract, modo seguro). Si está
 * deshabilitado responde `unavailable` controlado para que la app ofrezca
 * creación manual. NUNCA guarda un alimento sin que el usuario confirme el
 * borrador propuesto por el OCR.
 */
class NutritionOcrController extends Controller
{
    public function __construct(private NutritionOcrService $ocr)
    {
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** POST /api/nutrition/ocr/scan */
    public function scan(Request $request): JsonResponse
    {
        if (! $this->ocr->isEnabled()) {
            return response()->json([
                'ok'      => false,
                'status'  => 'unavailable',
                'message' => 'Lectura automática de etiqueta estará disponible pronto. '
                    . 'Puedes crear el alimento manualmente.',
            ]);
        }

        // Límite de tamaño configurable (NUTRITION_OCR_MAX_IMAGE_MB). Si la imagen
        // lo supera respondemos JSON 422 controlado con código estable (no el
        // error genérico de validación) para que la app muestre el mensaje útil.
        $maxMb = (int) config('nutrition.ocr.max_image_mb', 8);
        $image = $request->file('image');
        if ($image && $image->getSize() > $maxMb * 1024 * 1024) {
            return response()->json([
                'ok'      => false,
                'code'    => 'ocr_image_too_large',
                'message' => 'La imagen es demasiado pesada. Intenta con una foto más cercana o más clara.',
            ], 422);
        }

        $request->validate([
            'image'          => "nullable|image|mimes:jpg,jpeg,png,webp|max:" . ($maxMb * 1024),
            'text'           => 'nullable|string|max:8000', // OCR de cliente (opcional)
            'barcode'        => 'nullable|string|max:32',
            'food_uuid'      => 'nullable|string|max:64',
            'source_context' => 'nullable|string|max:60',
        ]);

        $scan = $this->ocr->createScan(
            $this->member($request),
            $request->file('image'),
            $request->input('text'),
            $request->input('barcode'),
        );

        return response()->json([
            'ok'     => $scan->status === NutritionOcrScan::STATUS_PROCESSED,
            'status' => $scan->status,
            'requires_confirmation' => $this->ocr->requiresConfirmation(),
            'data'   => $this->present($scan),
        ]);
    }

    /** GET /api/nutrition/ocr/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $scan = NutritionOcrScan::where('uuid', $uuid)->where('member_id', $member->id)->first();
        if (! $scan) {
            return response()->json(['ok' => false, 'message' => 'Escaneo no encontrado.'], 404);
        }
        return response()->json(['ok' => true, 'data' => $this->present($scan)]);
    }

    /** POST /api/nutrition/ocr/{uuid}/confirm-food — guarda el alimento revisado. */
    public function confirmFood(Request $request, string $uuid): JsonResponse
    {
        $member = $this->member($request);
        $scan = NutritionOcrScan::where('uuid', $uuid)->where('member_id', $member->id)->first();
        if (! $scan) {
            return response()->json(['ok' => false, 'message' => 'Escaneo no encontrado.'], 404);
        }

        $data = $request->validate([
            'confirmed'    => 'nullable|boolean',
            'food_uuid'    => 'nullable|string|max:64',
            'name'         => 'required|string|max:160',
            'brand'        => 'nullable|string|max:120',
            'barcode'      => 'nullable|string|max:32',
            'serving_size' => 'required|numeric|gt:0',
            'serving_unit' => 'nullable|string|max:20',
            'calories'     => 'required|numeric|min:0',
            'protein'      => 'required|numeric|min:0',
            'carbs'        => 'required|numeric|min:0',
            'fat'          => 'required|numeric|min:0',
            'sugar'        => 'nullable|numeric|min:0',
            'fiber'        => 'nullable|numeric|min:0',
            'sodium'       => 'nullable|numeric|min:0',
        ]);

        // El OCR SOLO propone: el guardado exige confirmación explícita del usuario.
        if ($this->ocr->requiresConfirmation() && ! $request->boolean('confirmed', false)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Debes revisar y confirmar los datos antes de guardar.',
            ], 422);
        }

        // Si se completa un alimento existente, resolver con control de permisos.
        $existing = null;
        if (! empty($data['food_uuid'])) {
            $existing = NutritionFood::where('uuid', $data['food_uuid'])->first();
            $canEdit = $existing
                && ($existing->created_by_member_id === $member->id
                    || ($existing->is_public && ! $existing->isMacroComplete()));
            if (! $canEdit) {
                return response()->json([
                    'ok' => false, 'message' => 'No puedes completar este alimento.',
                ], 404);
            }
        }

        $food = $this->ocr->confirmDraftFood($member, $scan, $data, $existing);
        return response()->json(['ok' => true, 'data' => $food->toApiArray()], 201);
    }

    private function present(NutritionOcrScan $scan): array
    {
        return [
            'uuid'             => $scan->uuid,
            'status'           => $scan->status,
            'provider'         => $scan->provider,
            'confidence_score' => $scan->confidence_score,
            'draft'            => $scan->parsed_payload,
            'error_message'    => $scan->error_message,
            'created_food_id'  => $scan->created_food_id,
        ];
    }
}
