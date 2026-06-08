<?php

namespace App\Http\Controllers\Api\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\Nutrition\Ai\NutritionAIEstimator;
use App\Services\Nutrition\Ai\NutritionAIInsightService;
use App\Services\Nutrition\Ai\NutritionAITextParser;
use App\Services\Nutrition\Ai\NutritionAIVisionLabelExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IA de Nutrición (asistencia, NO verdad certificada). Extrae desde imagen,
 * parsea texto OCR, estima alimentos (marcados) y genera insights. La key vive
 * en el backend; el usuario SIEMPRE confirma antes de guardar.
 */
class NutritionAiController extends Controller
{
    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** Código HTTP según el status controlado del flujo. */
    private function httpFor(array $r): int
    {
        return match ($r['status'] ?? '') {
            'rate_limited'      => 429,
            'validation_failed' => 422,
            default             => 200, // success | partial | ai_unavailable | timeout
        };
    }

    /** POST /api/nutrition/ai/label-image */
    public function labelImage(Request $request, NutritionAIVisionLabelExtractor $extractor): JsonResponse
    {
        $maxMb = (int) config('nutrition.ai.max_image_mb', 6);
        $image = $request->file('image');
        if ($image && $image->getSize() > $maxMb * 1024 * 1024) {
            return response()->json([
                'ok' => false, 'code' => 'ai_image_too_large',
                'message' => 'La imagen es demasiado pesada para el análisis con IA.',
            ], 422);
        }
        $request->validate([
            'image'   => "required|image|mimes:jpg,jpeg,png,webp|max:" . ($maxMb * 1024),
            'barcode' => 'nullable|string|max:32',
        ]);

        if (! config('nutrition.ai.enabled')) {
            return response()->json(['ok' => false, 'status' => 'ai_unavailable',
                'message' => 'Análisis con IA no disponible. Completa los datos manualmente.']);
        }

        $mime = $image->getMimeType();
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($image->getPathname()));
        $result = $extractor->extract($this->member($request), $dataUrl, $request->input('barcode'));
        return response()->json($result, $this->httpFor($result));
    }

    /** POST /api/nutrition/ai/parse-text */
    public function parseText(Request $request, NutritionAITextParser $parser): JsonResponse
    {
        $data = $request->validate([
            'text'         => 'required|string|max:6000',
            'barcode'      => 'nullable|string|max:32',
            'product_name' => 'nullable|string|max:160',
            'brand'        => 'nullable|string|max:120',
        ]);
        $result = $parser->parse($this->member($request), $data['text'], $data);
        return response()->json($result, $this->httpFor($result));
    }

    /** POST /api/nutrition/ai/estimate */
    public function estimate(Request $request, NutritionAIEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:200',
            'quantity'    => 'nullable|numeric|gt:0',
            'unit'        => 'nullable|string|max:20',
            'context'     => 'nullable|string|max:200',
        ]);
        $result = $estimator->estimate(
            $this->member($request), $data['description'],
            isset($data['quantity']) ? (float) $data['quantity'] : null,
            $data['unit'] ?? null, $data['context'] ?? null,
        );
        return response()->json($result, $this->httpFor($result));
    }

    /** GET /api/nutrition/ai/insights?range=week|month */
    public function insights(Request $request, NutritionAIInsightService $insights): JsonResponse
    {
        $request->validate(['range' => 'nullable|in:week,month']);
        $result = $insights->insights($this->member($request), (string) ($request->query('range') ?: 'week'));
        return response()->json($result);
    }
}
