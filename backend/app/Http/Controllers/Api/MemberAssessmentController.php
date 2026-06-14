<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalAssessmentResource;
use App\Models\Member;
use App\Models\ProfessionalAssessment;
use App\Services\Trainer\ProfessionalAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vista de SOLO LECTURA de las valoraciones profesionales para el miembro. El
 * miembro puede listarlas, abrir una y marcarla como leída; NUNCA editarlas,
 * borrarlas ni alterar medidas (no existen endpoints de escritura de contenido).
 * Solo se exponen las versiones enviadas/corregidas (no borradores ni anuladas)
 * y siempre acotadas al propio miembro (anti-IDOR).
 */
class MemberAssessmentController extends Controller
{
    public function __construct(private readonly ProfessionalAssessmentService $assessments) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $items = ProfessionalAssessment::forMember($member->getKey())
            ->visibleToMember()
            ->with('trainer')
            ->orderByDesc('submitted_at')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => ProfessionalAssessmentResource::collection($items),
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $assessment = $this->find($request, $uuid);

        return response()->json([
            'ok' => true,
            'data' => new ProfessionalAssessmentResource($assessment->load(['trainer', 'parent'])),
        ]);
    }

    public function acknowledge(Request $request, string $uuid): JsonResponse
    {
        $assessment = $this->find($request, $uuid);
        $this->assessments->acknowledge($assessment);

        return response()->json(['ok' => true, 'message' => 'Marcada como leída.']);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /** Resuelve la valoración del PROPIO miembro y visible; 404 en otro caso. */
    private function find(Request $request, string $uuid): ProfessionalAssessment
    {
        $member = $this->member($request);

        $assessment = ProfessionalAssessment::query()
            ->where('uuid', $uuid)
            ->forMember($member->getKey())
            ->visibleToMember()
            ->first();

        abort_if($assessment === null, 404, 'Valoración no encontrada.');

        return $assessment;
    }
}
