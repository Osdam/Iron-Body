<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PhysicalEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración de evaluaciones físicas desde el CRM (Angular SPA).
 *
 * Permite a entrenadores/admins ver y crear evaluaciones por miembro y editar
 * las observaciones del entrenador. Sin auth.member (el panel tiene su propia
 * auth de admin, como el resto de rutas admin/*). El member llega por id.
 *
 * La app del miembro luego ve estos datos en Progreso/Evaluación.
 */
class PhysicalEvaluationAdminController extends Controller
{
    /**
     * GET /api/admin/physical-evaluations/members — buscador ligero de miembros
     * (id + nombre + documento) para el selector del CRM. Soporta ?q= y limita.
     */
    public function members(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $members = Member::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('full_name', 'ilike', "%$q%")
                        ->orWhere('document_number', 'ilike', "%$q%");
                });
            })
            ->orderBy('full_name')
            ->limit(30)
            ->get(['id', 'full_name', 'document_number'])
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'document' => $m->document_number,
            ]);

        return response()->json(['ok' => true, 'data' => $members]);
    }

    /** GET /api/admin/members/{member}/physical-evaluations — historial. */
    public function index(Member $member): JsonResponse
    {
        $items = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (PhysicalEvaluation $e) => $e->toPublicArray());

        return response()->json([
            'ok' => true,
            'member' => ['id' => $member->id, 'full_name' => $member->full_name],
            'data' => $items,
        ]);
    }

    /** POST /api/admin/members/{member}/physical-evaluations — crear. */
    public function store(Request $request, Member $member): JsonResponse
    {
        $data = $this->validateData($request);
        $evaluation = PhysicalEvaluation::create(array_merge($data, [
            'member_id' => $member->id,
        ]));

        return response()->json(['ok' => true, 'data' => $evaluation->toPublicArray()], 201);
    }

    /** PUT/PATCH /api/admin/physical-evaluations/{evaluation} — editar (notas/medidas). */
    public function update(Request $request, PhysicalEvaluation $evaluation): JsonResponse
    {
        $data = $this->validateData($request, partial: true);
        $evaluation->update($data);

        return response()->json(['ok' => true, 'data' => $evaluation->fresh()->toPublicArray()]);
    }

    /** DELETE /api/admin/physical-evaluations/{evaluation}. */
    public function destroy(PhysicalEvaluation $evaluation): JsonResponse
    {
        $evaluation->delete();

        return response()->json(['ok' => true]);
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'trainer_id' => 'nullable|integer|exists:trainers,id',
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
        ]);
    }
}
