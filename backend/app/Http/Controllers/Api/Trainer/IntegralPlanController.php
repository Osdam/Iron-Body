<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Routine;
use App\Models\Trainer;
use App\Services\Trainer\EligibleExerciseCatalog;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\RoutineDraftEditor;
use App\Services\Trainer\RoutinePublisher;
use App\Services\Trainer\TrainerMemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generar y publicar el plan integral: guía nutricional + rutina.
 *
 * El `member` llega por la RUTA y se comprueba contra las asignaciones activas.
 * Un `member_id` en el cuerpo no se mira: si el cliente pudiera elegirlo,
 * bastaría con conocer el número de otro socio para generarle un plan.
 */
class IntegralPlanController extends Controller
{
    public function __construct(private readonly TrainerMemberAccess $access) {}

    /**
     * POST /api/trainer/members/{member}/integral-plan/generate
     *
     * Devuelve DOS borradores. Nada queda visible para el socio hasta que el
     * entrenador lo revise y lo publique.
     */
    public function generate(Request $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccess($trainer, $member);

        try {
            $plan = app(IntegralPlanGenerationService::class)->generate($trainer, $member);
        } catch (IntegralPlanException $e) {
            return $this->planError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => 'draft',
                'nutrition_guide' => [
                    'uuid' => $plan['guide']->uuid,
                    'status' => $plan['guide']->status,
                    'objective' => $plan['guide']->objective,
                    'meals_count' => is_array($plan['guide']->meals) ? count($plan['guide']->meals) : 0,
                ],
                'routine' => $this->routinePayload($plan['routine']),
                // La síntesis que el entrenador lee antes de entrar al detalle.
                // Vive en la corrida de IA (`nutrition_ai_runs.response_json`),
                // que ya la guarda: no hace falta una columna nueva para un
                // texto que solo acompaña a esta respuesta.
                'analysis' => $plan['analysis'] ?? null,
                'source_assessment_id' => $plan['guide']->source_assessment_id,
            ],
        ], 201);
    }

    /**
     * POST /api/trainer/routines/{routine}/publish
     *
     * A partir de aquí el socio la ve en Entrenar → Mis Rutinas. No hay
     * categoría nueva: es el mismo sitio donde ya mira.
     */
    public function publishRoutine(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);

        try {
            $publicada = app(RoutinePublisher::class)->publish($routine, $trainer);
        } catch (IntegralPlanException $e) {
            return $this->planError($e, 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($publicada),
        ]);
    }

    /**
     * GET /api/trainer/routines/{routine} — el borrador, para revisarlo.
     */
    public function showRoutine(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwnsRoutine($trainer, $routine);

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($routine, withExercises: true),
        ]);
    }

    /**
     * PUT /api/trainer/routines/{routine}/exercises — corregir el borrador.
     *
     * Llega la lista COMPLETA en el orden final. Cada línea puede traer el `id`
     * de la fila que ya existía para conservarla; lo que no venga, se elimina.
     * El orden sale de la posición, no de un número que mande el cliente.
     *
     * Cada `exercise_id` se vuelve a resolver contra el catálogo aunque la app
     * solo enseñe ejercicios válidos: quien llame a la API directamente puede
     * mandar cualquier número.
     */
    public function updateRoutineExercises(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);

        $data = $request->validate([
            'exercises' => ['required', 'array', 'min:1', 'max:60'],
            'exercises.*.id' => ['nullable', 'integer'],
            'exercises.*.exercise_id' => ['required', 'integer'],
            'exercises.*.day' => ['nullable', 'string', 'max:80'],
            'exercises.*.sets' => ['required', 'integer', 'min:1', 'max:10'],
            'exercises.*.reps' => ['nullable', 'string', 'max:20'],
            'exercises.*.weight' => ['nullable', 'string', 'max:40'],
            'exercises.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $actualizada = app(RoutineDraftEditor::class)
                ->replaceExercises($routine, $trainer, $data['exercises']);
        } catch (IntegralPlanException $e) {
            return $this->planError($e);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($actualizada, withExercises: true),
        ]);
    }

    /**
     * GET /api/trainer/exercises — el catálogo del que puede elegir.
     *
     * La MISMA fuente que ve Iron IA al generar. No hay un segundo catálogo:
     * si el entrenador pudiera elegir de una lista distinta, acabaría poniendo
     * ejercicios que el generador nunca propondría y al revés.
     */
    public function exercises(Request $request): JsonResponse
    {
        $this->trainer($request);

        return response()->json([
            'ok' => true,
            'data' => app(EligibleExerciseCatalog::class)->forPrompt(),
        ]);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function routinePayload(Routine $routine, bool $withExercises = false): array
    {
        $data = [
            'id' => $routine->id,
            'name' => $routine->name,
            'objective' => $routine->objective,
            'level' => $routine->level,
            'days_per_week' => $routine->days_per_week,
            'status' => $routine->status,
            // Lo que de verdad decide si el socio la ve.
            'is_assigned' => (bool) $routine->is_assigned,
            'published' => (bool) $routine->is_assigned,
            'generated_by_ai' => (bool) $routine->generated_by_ai,
            'source_assessment_id' => $routine->source_assessment_id,
            'exercises_count' => $routine->routineExercises()->count(),
            'days' => $routine->days,
        ];

        if ($withExercises) {
            // La lista PLANA en su orden real: es lo que el editor manipula.
            // `days` sirve para el resumen; esto para trabajar.
            $data['exercises'] = $routine->routineExercises()
                ->with('exercise:id,name,local_name,muscle_group,body_part,equipment')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($re) => [
                    'id' => $re->id,
                    'exercise_id' => $re->exercise_id,
                    'name' => $re->exercise?->local_name ?: $re->exercise?->name,
                    'muscle_group' => $re->exercise?->muscle_group ?: $re->exercise?->body_part,
                    'equipment' => $re->exercise?->equipment,
                    'sets' => (int) $re->sets,
                    'reps' => (string) $re->reps,
                    'weight' => $re->weight,
                    'notes' => $re->notes,
                    'sort_order' => (int) $re->sort_order,
                ])
                ->all();
        }

        return $data;
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function assertOwnsRoutine(Trainer $trainer, Routine $routine): void
    {
        abort_unless(
            (int) $routine->trainer_id === (int) $trainer->getKey(),
            403,
            'Esta rutina la creó otro entrenador.',
        );
    }

    private function trainer(Request $request): Trainer
    {
        $trainer = $request->attributes->get('auth_trainer');
        abort_unless($trainer instanceof Trainer, 401, 'Sesión profesional requerida.');

        return $trainer;
    }

    private function assertCanAccess(Trainer $trainer, Member $member): void
    {
        abort_unless(
            $this->access->canAccess($trainer, $member),
            403,
            'No tienes acceso a este miembro.',
        );
    }

    /**
     * El código explica QUÉ pasó y `retryable` si tiene sentido volver a
     * intentarlo. Sin eso, la app solo puede ofrecer «reintentar» siempre,
     * incluso cuando la cuota del día ya se agotó.
     */
    private function planError(IntegralPlanException $e, int $status = 422): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $e->code_,
            'message' => $e->getMessage(),
            'retryable' => $e->retryable,
        ], $status);
    }
}
