<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhysicalEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evaluaciones físicas del miembro (member autenticado).
 *
 *  GET  /api/app/physical-evaluations         → historial (desc)
 *  GET  /api/app/physical-evaluations/latest  → última evaluación (o null)
 *  GET  /api/app/physical-evaluations/{id}    → detalle (solo propias)
 *  POST /api/app/physical-evaluations         → crear (nueva fila = historial)
 *
 * Decisión: cada POST crea una fila nueva (no se sobreescribe la del día). Así
 * el historial y la evolución de peso reflejan datos reales en el tiempo.
 *
 * member_id SIEMPRE de auth.member; la app nunca lo manda. No se pueden ver ni
 * editar evaluaciones de otro miembro.
 */
class PhysicalEvaluationController extends Controller
{
    /** GET /api/app/physical-evaluations */
    public function index(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $items = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PhysicalEvaluation $e) => $e->toPublicArray());

        return response()->json(['success' => true, 'data' => $items]);
    }

    /** GET /api/app/physical-evaluations/latest */
    public function latest(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $latest = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $latest?->toPublicArray(),
        ]);
    }

    /** GET /api/app/physical-evaluations/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $evaluation = PhysicalEvaluation::query()
            ->where('member_id', $member->id) // aislamiento por dueño
            ->find($id);

        if (! $evaluation) {
            return response()->json(['success' => false, 'message' => 'No encontrada.'], 404);
        }

        return response()->json(['success' => true, 'data' => $evaluation->toPublicArray()]);
    }

    /**
     * POST /api/app/physical-evaluations
     *
     * BLOQUEADO para el miembro: la evaluación física profesional ahora la crea
     * y gestiona el entrenador (valoraciones profesionales / CRM). El miembro
     * solo puede CONSULTAR su historial (index/latest/show). No se borra ningún
     * dato histórico; únicamente se impide la escritura desde la cuenta del
     * usuario, también ante una llamada directa al API.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'evaluation_member_readonly',
            'message' => 'La evaluación física profesional es realizada por tu entrenador.',
        ], 403);
    }
}
