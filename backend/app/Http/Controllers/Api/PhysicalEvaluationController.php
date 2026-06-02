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
        if (!$member) {
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
        if (!$member) {
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
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $evaluation = PhysicalEvaluation::query()
            ->where('member_id', $member->id) // aislamiento por dueño
            ->find($id);

        if (!$evaluation) {
            return response()->json(['success' => false, 'message' => 'No encontrada.'], 404);
        }

        return response()->json(['success' => true, 'data' => $evaluation->toPublicArray()]);
    }

    /** POST /api/app/physical-evaluations */
    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        // Rangos para adultos. Las medidas corporales 10-250 cm. La estatura DEBE
        // ir en centímetros (100-230): así 70 cm o 1.70 se rechazan con un
        // mensaje guía en vez de calcular un IMC absurdo.
        $data = $request->validate([
            'weight_kg' => 'nullable|numeric|min:25|max:300',
            'height_cm' => 'nullable|numeric|min:100|max:230',
            'body_fat_pct' => 'nullable|numeric|min:2|max:75',
            'muscle_mass_pct' => 'nullable|numeric|min:10|max:80',
            'waist_cm' => 'nullable|numeric|min:10|max:250',
            'hip_cm' => 'nullable|numeric|min:10|max:250',
            'chest_cm' => 'nullable|numeric|min:10|max:250',
            'arm_cm' => 'nullable|numeric|min:10|max:250',
            'leg_cm' => 'nullable|numeric|min:10|max:250',
            'injuries' => 'nullable|string|max:1000',
            'trainer_notes' => 'nullable|string|max:1000',
        ], [
            'height_cm.min' => 'Ingresa tu estatura en centímetros. Ejemplo: 170 para 1.70 m.',
            'height_cm.max' => 'Ingresa tu estatura en centímetros. Ejemplo: 170 para 1.70 m.',
            'weight_kg.min' => 'El peso debe estar entre 25 y 300 kg.',
            'weight_kg.max' => 'El peso debe estar entre 25 y 300 kg.',
        ]);

        // Una evaluación necesita al menos peso y estatura válidos.
        if (!isset($data['weight_kg']) || !isset($data['height_cm'])) {
            $errors = [];
            if (!isset($data['weight_kg'])) {
                $errors['weight_kg'] = ['Ingresa tu peso.'];
            }
            if (!isset($data['height_cm'])) {
                $errors['height_cm'] = ['Ingresa tu estatura en centímetros. Ejemplo: 170 para 1.70 m.'];
            }
            return response()->json([
                'success' => false,
                'message' => 'Completa al menos peso y estatura.',
                'errors' => $errors,
            ], 422);
        }

        $evaluation = PhysicalEvaluation::create(array_merge($data, [
            'member_id' => $member->id,
            // trainer_notes desde la app quedan como notas propias; el trainer_id
            // solo lo setea el CRM cuando un entrenador crea la evaluación.
        ]));

        return response()->json([
            'success' => true,
            'data' => $evaluation->toPublicArray(),
        ], 201);
    }
}
