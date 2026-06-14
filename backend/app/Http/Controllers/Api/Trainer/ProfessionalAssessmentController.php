<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\AssessmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\AmendProfessionalAssessmentRequest;
use App\Http\Requests\Trainer\StoreProfessionalAssessmentRequest;
use App\Http\Resources\ProfessionalAssessmentResource;
use App\Models\Member;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Services\Trainer\ProfessionalAssessmentService;
use App\Services\Trainer\TrainerMemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Valoraciones profesionales desde el portal del entrenador. La autorización se
 * compone: feature flag + `trainer.can:<permiso>` (en las rutas) + acceso al
 * miembro (asignación) + propiedad del recurso (autor) aquí. Protege contra IDOR
 * y acceso cruzado entre entrenadores/sedes.
 */
class ProfessionalAssessmentController extends Controller
{
    public function __construct(
        private readonly ProfessionalAssessmentService $assessments,
        private readonly TrainerMemberAccess $access,
    ) {}

    public function index(Request $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccessMember($trainer, $member);

        $items = ProfessionalAssessment::forMember($member->getKey())
            ->where('trainer_id', $trainer->getKey())
            ->with('trainer')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => ProfessionalAssessmentResource::collection($items),
        ]);
    }

    public function store(StoreProfessionalAssessmentRequest $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccessMember($trainer, $member);

        $assessment = $this->assessments->createDraft($trainer, $member, $request->validated());

        return response()->json([
            'ok' => true,
            'data' => new ProfessionalAssessmentResource($assessment->load('trainer')),
        ], 201);
    }

    public function show(Request $request, ProfessionalAssessment $assessment): JsonResponse
    {
        $this->assertOwner($this->trainer($request), $assessment);

        return response()->json([
            'ok' => true,
            'data' => new ProfessionalAssessmentResource($assessment->load(['trainer', 'parent'])),
        ]);
    }

    public function update(StoreProfessionalAssessmentRequest $request, ProfessionalAssessment $assessment): JsonResponse
    {
        $this->assertOwner($this->trainer($request), $assessment);

        try {
            $updated = $this->assessments->updateDraft($assessment, $request->validated());
        } catch (AssessmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'data' => new ProfessionalAssessmentResource($updated->load('trainer')),
        ]);
    }

    public function submit(Request $request, ProfessionalAssessment $assessment): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $assessment);

        try {
            $submitted = $this->assessments->submit($assessment, $trainer);
        } catch (AssessmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Valoración enviada al miembro.',
            'data' => new ProfessionalAssessmentResource($submitted->load('trainer')),
        ]);
    }

    public function amend(AmendProfessionalAssessmentRequest $request, ProfessionalAssessment $assessment): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $assessment);

        try {
            $amendment = $this->assessments->amend($assessment, $trainer, $request->validated());
        } catch (AssessmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Corrección enviada al miembro.',
            'data' => new ProfessionalAssessmentResource($amendment->load(['trainer', 'parent'])),
        ], 201);
    }

    private function trainer(Request $request): Trainer
    {
        return $request->attributes->get('auth_trainer');
    }

    private function assertCanAccessMember(Trainer $trainer, Member $member): void
    {
        abort_unless($this->access->canAccess($trainer, $member), 403, 'No tienes acceso a este miembro.');
    }

    /** El recurso debe pertenecer al entrenador autenticado (autor). */
    private function assertOwner(Trainer $trainer, ProfessionalAssessment $assessment): void
    {
        abort_unless((int) $assessment->trainer_id === (int) $trainer->getKey(), 403, 'Recurso no disponible.');
    }

    private function error(AssessmentException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'assessment_error',
            'message' => $e->getMessage(),
        ], $e->status);
    }
}
