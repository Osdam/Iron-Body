<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProgressSummaryService;
use App\Services\WeeklyTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumen de "Progreso" para la app (member autenticado).
 *
 *  GET /api/app/progress/summary
 *  GET /api/app/progress/weekly?week_start=YYYY-MM-DD
 *
 * Devuelve métricas REALES (peso/IMC de la última evaluación, entrenamientos de
 * routine_completions, racha de weekly-streak, historiales). Si no hay datos,
 * los campos vienen null (la app muestra empty states honestos, nunca NaN/0).
 *
 * El socio se resuelve SIEMPRE desde la sesión autenticada. No hay ningún
 * parámetro para pedir las estadísticas de otro: no es que se validen, es que
 * no existen.
 */
class ProgressController extends Controller
{
    public function __construct(
        private readonly ProgressSummaryService $service,
        private readonly WeeklyTrainingService $weekly,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->build($member),
        ]);
    }

    /**
     * Una semana concreta del historial, con la anterior para comparar.
     *
     * Endpoint aparte del resumen a propósito: navegar de una semana a otra no
     * tiene por qué recalcular evaluaciones físicas, récords y racha. Sin
     * `week_start` devuelve la semana en curso, así que la app puede usarlo
     * también para refrescar tras terminar un entrenamiento.
     */
    public function weekly(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->weekly->forMember($member, $data['week_start'] ?? null),
        ]);
    }
}
