<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProgressSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumen de "Progreso" para la app (member autenticado).
 *
 *  GET /api/app/progress/summary
 *
 * Devuelve métricas REALES (peso/IMC de la última evaluación, entrenamientos de
 * routine_completions, racha de weekly-streak, historiales). Si no hay datos,
 * los campos vienen null (la app muestra empty states honestos, nunca NaN/0).
 */
class ProgressController extends Controller
{
    public function __construct(private readonly ProgressSummaryService $service)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->build($member),
        ]);
    }
}
