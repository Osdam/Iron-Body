<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\NutritionGuideException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\StoreNutritionGuideRequest;
use App\Http\Resources\NutritionGuideResource;
use App\Http\Resources\ProfessionalAssessmentResource;
use App\Models\Member;
use App\Models\NutritionGuide;
use App\Models\Trainer;
use App\Services\Trainer\NutritionGuideService;
use App\Services\Trainer\TrainerMemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guías nutricionales — portal del ENTRENADOR.
 *
 * La autorización se compone igual que en las valoraciones: feature flag +
 * `trainer.can:<permiso>` (en las rutas) + acceso al miembro (asignación) +
 * propiedad del recurso (autor) aquí. Protege contra IDOR y contra el acceso
 * cruzado entre entrenadores y sedes.
 */
class NutritionGuideController extends Controller
{
    public function __construct(
        private readonly NutritionGuideService $guides,
        private readonly TrainerMemberAccess $access,
    ) {}

    public function index(Request $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccessMember($trainer, $member);

        $items = NutritionGuide::forMember((int) $member->getKey())
            ->where('trainer_id', $trainer->getKey())
            ->with('trainer')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => NutritionGuideResource::collection($items),
        ]);
    }

    /**
     * Datos con los que arrancar una guía sin volver a teclearlos.
     *
     * Existe para que el formulario pueda ofrecer "usar la última valoración"
     * mostrando ANTES qué se va a copiar: aceptar a ciegas un rellenado
     * automático es cómo se publican medidas de hace seis meses.
     */
    public function prefill(Request $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccessMember($trainer, $member);

        $assessment = $this->guides->lastAssessmentFor($member);

        return response()->json([
            'ok' => true,
            'data' => [
                'has_assessment' => $assessment !== null,
                'assessment' => $assessment
                    ? new ProfessionalAssessmentResource($assessment->load('trainer'))
                    : null,
                'measurements' => $assessment ? $this->guides->measurementsFrom($assessment) : [],
            ],
        ]);
    }

    public function store(StoreNutritionGuideRequest $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccessMember($trainer, $member);

        $guide = $this->guides->createDraft(
            $trainer,
            $member,
            $request->validated(),
            useLastAssessment: (bool) $request->boolean('use_last_assessment'),
        );

        return response()->json([
            'ok' => true,
            'data' => new NutritionGuideResource($guide->load('trainer')),
        ], 201);
    }

    public function show(Request $request, NutritionGuide $guide): JsonResponse
    {
        $this->assertOwner($this->trainer($request), $guide);

        return response()->json([
            'ok' => true,
            'data' => new NutritionGuideResource($guide->load(['trainer', 'parent', 'sourceAssessment'])),
        ]);
    }

    public function update(StoreNutritionGuideRequest $request, NutritionGuide $guide): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $guide);

        try {
            $actualizada = $this->guides->updateDraft($guide, $trainer, $request->validated());
        } catch (NutritionGuideException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'data' => new NutritionGuideResource($actualizada->load('trainer')),
        ]);
    }

    public function publish(Request $request, NutritionGuide $guide): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $guide);

        try {
            $publicada = $this->guides->publish($guide, $trainer);
        } catch (NutritionGuideException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Guía publicada para el socio.',
            'data' => new NutritionGuideResource($publicada->load('trainer')),
        ]);
    }

    public function amend(StoreNutritionGuideRequest $request, NutritionGuide $guide): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $guide);

        try {
            $correccion = $this->guides->amend($guide, $trainer, $request->validated());
        } catch (NutritionGuideException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Borrador de la nueva versión creado. La guía anterior '
                .'sigue vigente hasta que publiques esta.',
            'data' => new NutritionGuideResource($correccion->load(['trainer', 'parent'])),
        ], 201);
    }

    public function void(Request $request, NutritionGuide $guide): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwner($trainer, $guide);

        $data = $request->validate(['void_reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $anulada = $this->guides->void($guide, $trainer, $data['void_reason']);
        } catch (NutritionGuideException $e) {
            return $this->error($e);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Guía anulada.',
            'data' => new NutritionGuideResource($anulada->load('trainer')),
        ]);
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
    private function assertOwner(Trainer $trainer, NutritionGuide $guide): void
    {
        abort_unless((int) $guide->trainer_id === (int) $trainer->getKey(), 403, 'Recurso no disponible.');
    }

    private function error(NutritionGuideException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'nutrition_guide_error',
            'message' => $e->getMessage(),
        ], $e->status);
    }
}
