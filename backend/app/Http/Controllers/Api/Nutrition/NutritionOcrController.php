<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NutritionOcrScan;
use App\Services\Nutrition\NutritionOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OCR de etiqueta nutricional (modo seguro). Si está deshabilitado responde
 * `unavailable` controlado para que la app ofrezca creación manual. Nunca guarda
 * un alimento sin que el usuario confirme el borrador.
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

        $request->validate([
            'image' => 'nullable|image|max:8192', // jpg/png/heic ≤ 8MB
            'text'  => 'nullable|string|max:8000', // OCR de cliente (opcional)
            'barcode' => 'nullable|string|max:20',
            'name'  => 'nullable|string|max:160',
        ]);

        $scan = $this->ocr->createScan(
            $this->member($request),
            $request->file('image'),
            $request->input('text'),
        );

        return response()->json([
            'ok'     => $scan->status === NutritionOcrScan::STATUS_PROCESSED,
            'status' => $scan->status,
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
            'name'         => 'required|string|max:160',
            'brand'        => 'nullable|string|max:120',
            'barcode'      => 'nullable|string|max:20',
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

        $food = $this->ocr->confirmDraftFood($member, $scan, $data);
        return response()->json(['ok' => true, 'data' => $food->toApiArray()], 201);
    }

    private function present(NutritionOcrScan $scan): array
    {
        return [
            'uuid'             => $scan->uuid,
            'status'           => $scan->status,
            'confidence_score' => $scan->confidence_score,
            'draft'            => $scan->parsed_payload,
            'error_message'    => $scan->error_message,
            'created_food_id'  => $scan->created_food_id,
        ];
    }
}
