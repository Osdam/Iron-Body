<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trainer\GenerateIntegralPlanRequest;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\Routine;
use App\Models\Trainer;
use App\Services\Trainer\EligibleExerciseCatalog;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use App\Services\Trainer\RoutineDraftEditor;
use App\Services\Trainer\RoutinePublisher;
use App\Services\Trainer\SessionTimeBudget;
use App\Services\Trainer\TrainerMemberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     *
     * El cuerpo es el contexto de «Contexto para el plan» (las claves de
     * `PlanContext.toJson()`), todo opcional: las apps hasta la 2.0.9 envían
     * `{}` y generan exactamente como antes ({@see GenerateIntegralPlanRequest}).
     */
    public function generate(GenerateIntegralPlanRequest $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccess($trainer, $member);

        try {
            $plan = app(IntegralPlanGenerationService::class)->generate($trainer, $member, $request->trainerInput());
        } catch (IntegralPlanException $e) {
            // A1: otra generación en curso (409) o tope del entrenador (429).
            // El resto, como siempre: 422 con su código y `retryable`.
            return $this->planError($e, match ($e->code_) {
                'generation_in_progress' => 409,
                'trainer_rate_limited' => 429,
                default => 422,
            });
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
                // Sin `recovery`: una generación normal nunca es una propuesta, y la
                // respuesta de generar sigue siendo la de la 2.0.10 (paridad v1).
                'routine' => $this->routinePayload($plan['routine'], withRecovery: false),
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
    /**
     * GET /trainer/members/{member}/routines — las rutinas del socio.
     *
     * Lo que el entrenador necesita para hacerse cargo: cuáles hay, cuál está
     * activa y cuál nació de Iron IA. Incluye las RETIRADAS a propósito: el
     * socio no las ve, pero forman parte de su historia y por eso el
     * seguimiento sí las enseña.
     */
    public function memberRoutines(Request $request, Member $member): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertCanAccess($trainer, $member);

        $rutinas = Routine::query()
            ->where('member_id', $member->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $rutinas->map(fn (Routine $r) => $this->routinePayload($r))->all(),
        ]);
    }

    /** POST /trainer/routines/{routine}/retire — el socio deja de verla. */
    public function retireRoutine(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);

        try {
            $retirada = app(RoutinePublisher::class)->retire($routine, $trainer);
        } catch (IntegralPlanException $e) {
            return $this->planError($e, 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($retirada),
        ]);
    }

    /**
     * POST /trainer/routines/{routine}/publish — el socio empieza a verla.
     *
     * Una propuesta de recuperación exige `recovery_confirmation`
     * (docs/iron-ia/RECUPERACION-REVISION-CONTRATO.md §2); en cualquier otra
     * rutina el cuerpo no se mira, como siempre.
     */
    public function publishRoutine(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);

        $confirmacion = null;
        if ($request->has('recovery_confirmation')) {
            $this->normalizeRecoveryInput($request, 'recovery_confirmation.');
            $v = $request->validate(array_merge(
                ['recovery_confirmation' => ['required', 'array']],
                $this->recoveryRules('recovery_confirmation.'),
                [
                    'recovery_confirmation.availability_confirmed' => ['required', 'boolean'],
                    'recovery_confirmation.muscle_split_reviewed' => ['required', 'boolean'],
                ],
            ))['recovery_confirmation'];
            $confirmacion = $this->recoveryInput($v) + [
                'availability_confirmed' => filter_var($v['availability_confirmed'], FILTER_VALIDATE_BOOLEAN),
                'muscle_split_reviewed' => filter_var($v['muscle_split_reviewed'], FILTER_VALIDATE_BOOLEAN),
            ];
        }

        try {
            $publicada = app(RoutinePublisher::class)->publish($routine, $trainer, $confirmacion);
        } catch (IntegralPlanException $e) {
            return $this->planError($e, 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($publicada),
        ]);
    }

    /**
     * POST /trainer/routines/{routine}/recovery/regenerate — una propuesta
     * nueva con lo que el entrenador confirmó con el socio
     * (RECUPERACION-REVISION-CONTRATO.md §3). Va por la campaña: no gasta los
     * cupos del entrenador.
     */
    public function regenerateRecovery(Request $request, Routine $routine): JsonResponse
    {
        $trainer = $this->trainer($request);
        $this->assertOwnsRoutine($trainer, $routine);

        $item = IronIaRecoveryItem::forProposal((int) $routine->getKey());
        abort_unless($item instanceof IronIaRecoveryItem, 404, 'Esta rutina no es una propuesta de recuperación.');

        $member = Member::find($routine->member_id);
        abort_unless($member instanceof Member, 404, 'Esta rutina no tiene socio asignado.');
        $this->assertCanAccess($trainer, $member);

        $this->normalizeRecoveryInput($request, '');
        $v = $request->validate($this->recoveryRules('') + [
            'observations' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $confirmado = $this->recoveryInput($v);
        if (is_string($v['observations'] ?? null) && trim($v['observations']) !== '') {
            $confirmado['observations'] = trim($v['observations']);
        }

        try {
            $nueva = app(RoutineRecoveryCampaign::class)->regenerate($item, $trainer, $confirmado);
        } catch (IntegralPlanException $e) {
            return $this->planError($e, match ($e->code_) {
                'generation_in_progress' => 409,
                'recovery_regeneration_limit' => 429,
                default => 422,
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $this->routinePayload($nueva),
        ], 201);
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
    private function routinePayload(Routine $routine, bool $withExercises = false, bool $withRecovery = true): array
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
        if ($withRecovery) {
            // Propuesta de recuperación de Iron IA (RECUPERACION-REVISION-CONTRATO.md §1).
            $data['recovery'] = IronIaRecoveryItem::apiFor($routine);
        }

        if ($withExercises) {
            // La lista PLANA en su orden real: es lo que el editor manipula.
            // `days` sirve para el resumen; esto para trabajar.
            $filas = $routine->routineExercises()
                ->with('exercise:id,name,local_name,muscle_group,body_part,equipment')
                ->orderBy('sort_order')
                ->get();
            $dias = $this->dayOfEachRow($routine, $filas->count());
            $data['exercises'] = $filas
                ->values()
                ->map(fn ($re, $i) => [
                    'id' => $re->id,
                    // El día de cada fila: sin él, el editor devolvía la lista sin
                    // días y al guardar todo quedaba en «Día 1» (una rutina de 6
                    // días pasaba a 1). La app ya lo lee y lo reenvía.
                    'day' => $dias[$i] ?? null,
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

    /**
     * El día de cada fila de `routine_exercises`, por posición: la generación y
     * el editor guardan las filas en el orden de los días de `routines.days`.
     * Si no cuadran (la rutina se tocó por otra vía), no se inventa: null.
     *
     * @return list<string|null>
     */
    private function dayOfEachRow(Routine $routine, int $filas): array
    {
        $etiquetas = [];
        foreach ((array) $routine->days as $i => $dia) {
            $nombre = is_array($dia) && is_string($dia['name'] ?? null) && trim($dia['name']) !== ''
                ? trim($dia['name'])
                : 'Día '.($i + 1);
            foreach ((array) ($dia['exercises'] ?? []) as $_) {
                $etiquetas[] = $nombre;
            }
        }

        return count($etiquetas) === $filas ? $etiquetas : array_fill(0, $filas, null);
    }

    /**
     * Lo que el entrenador confirma con el socio: las mismas reglas que el
     * formulario de generación (GenerateIntegralPlanRequest), pero obligatorias.
     *
     * @return array<string,array<int,mixed>>
     */
    private function recoveryRules(string $prefix): array
    {
        return [
            $prefix.'days_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            $prefix.'session_minutes' => ['required', 'integer', 'min:'.SessionTimeBudget::MIN_SESSION_MINUTES, 'max:'.SessionTimeBudget::MAX_SESSION_MINUTES],
            $prefix.'training_level' => ['required', 'string', Rule::in(GenerateIntegralPlanRequest::LEVELS)],
            $prefix.'objective' => ['required', 'string', 'max:180'],
            $prefix.'injuries' => ['required', 'string', 'max:2000'],
        ];
    }

    /** Espacios recortados y el nivel en minúsculas, ANTES de validar. */
    private function normalizeRecoveryInput(Request $request, string $prefix): void
    {
        $datos = $prefix === '' ? $request->all() : (array) $request->input(rtrim($prefix, '.'), []);
        if (! is_array($datos)) {
            return;
        }
        foreach (['objective', 'injuries', 'observations'] as $campo) {
            if (is_string($datos[$campo] ?? null)) {
                $datos[$campo] = trim(preg_replace('/\s+/u', ' ', $datos[$campo]) ?? '');
            }
        }
        if (is_string($datos['training_level'] ?? null)) {
            $datos['training_level'] = mb_strtolower(trim($datos['training_level']));
        }
        $request->merge($prefix === '' ? $datos : [rtrim($prefix, '.') => $datos]);
    }

    /**
     * @param  array<string,mixed>  $v  ya validado
     * @return array{days_per_week:int,session_minutes:int,training_level:string,objective:string,injuries:string}
     */
    private function recoveryInput(array $v): array
    {
        return [
            'days_per_week' => (int) $v['days_per_week'],
            'session_minutes' => (int) $v['session_minutes'],
            'training_level' => (string) $v['training_level'],
            'objective' => (string) $v['objective'],
            'injuries' => (string) $v['injuries'],
        ];
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
