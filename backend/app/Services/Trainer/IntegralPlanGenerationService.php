<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Member;
use App\Models\NutritionAiRun;
use App\Models\NutritionGuide;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Services\Nutrition\Ai\NutritionAiClient;
use App\Services\Nutrition\Ai\NutritionAiCostGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Genera, de una sola evaluación, los DOS borradores: guía y rutina.
 *
 * O LOS DOS, O NINGUNO. Si la rutina no valida, tampoco se guarda la nutrición.
 * Medio plan es peor que ninguno: el entrenador publicaría la guía creyendo que
 * la rutina también está, y el socio se quedaría a medias sin que nadie lo note
 * hasta que pregunte. Por eso la persistencia va en una transacción y la
 * validación entera ocurre ANTES de abrirla.
 *
 * BORRADORES, NUNCA PUBLICADO. La guía nace `draft` y la rutina con
 * `is_assigned = false` y sin asignación: el socio no ve nada hasta que una
 * persona lo revisa y lo publica. Un plan de entrenamiento y alimentación que
 * llega solo, sin que nadie lo haya leído, no es una funcionalidad.
 *
 * LA IA NO ES LA AUTORIDAD. Lo que devuelve pasa por
 * {@see IntegralPlanValidator}, y cada ejercicio se resuelve contra el catálogo
 * real. Lo que no existe, no entra.
 */
class IntegralPlanGenerationService
{
    /** Sube al cambiar el prompt: invalida la caché y queda en el registro. */
    public const PROMPT_VERSION = 'v1';

    public const RUN_MODE = 'integral_plan';

    public function __construct(
        private readonly MemberContextAssembler $context,
        private readonly EligibleExerciseCatalog $catalog,
        private readonly IntegralPlanValidator $validator,
        private readonly NutritionGuideService $guides,
        private readonly NutritionAiClient $ai,
        private readonly NutritionAiCostGuard $costGuard,
    ) {}

    /**
     * @return array{guide: NutritionGuide, routine: Routine, run: NutritionAiRun}
     *
     * @throws IntegralPlanException
     */
    public function generate(Trainer $trainer, Member $member): array
    {
        $this->assertEnabled();

        $guard = $this->costGuard->check($member);
        if (! ($guard['allowed'] ?? false)) {
            throw IntegralPlanException::costGuard((string) ($guard['reason'] ?? 'cost_guard'));
        }

        $catalogo = $this->catalog->forPrompt();
        if ($catalogo === []) {
            throw IntegralPlanException::emptyCatalog();
        }

        $contexto = $this->context->for($member);
        $mensajes = $this->messages($contexto, $catalogo);

        $modelo = (string) config('services.openai.model', 'gpt-4.1-mini');
        $hash = hash('sha256', self::PROMPT_VERSION.'|'.json_encode($mensajes));

        $respuesta = $this->ai->complete($modelo, $mensajes, json: true, maxTokens: 3000);

        if (($respuesta['status'] ?? '') !== 'ok') {
            $this->recordRun($member, $modelo, $hash, 'failed', $respuesta['error_code'] ?? 'upstream');
            throw IntegralPlanException::upstream($respuesta['error_code'] ?? null);
        }

        // Validación COMPLETA antes de tocar la base. Si algo falla aquí, no hay
        // nada que deshacer porque no se ha escrito nada todavía.
        try {
            $plan = $this->validator->validate($respuesta['json'] ?? null);
        } catch (IntegralPlanException $e) {
            $this->recordRun($member, $modelo, $hash, 'invalid', $e->code_, $respuesta['json'] ?? null);
            throw $e;
        }

        $run = $this->recordRun($member, $modelo, $hash, 'ok', null, $plan);

        return DB::transaction(function () use ($trainer, $member, $plan, $contexto, $run) {
            $guia = $this->guides->createDraft(
                $trainer,
                $member,
                $plan['nutrition'],
                useLastAssessment: true,
            );
            $guia->update(['generated_by_ai' => true]);

            $rutina = $this->createRoutineDraft(
                $trainer,
                $member,
                $plan['routine'],
                (int) ($contexto['sources']['assessment_id'] ?? 0) ?: null,
            );

            return ['guide' => $guia->fresh(), 'routine' => $rutina->fresh('routineExercises'), 'run' => $run];
        });
    }

    // ── Rutina en borrador ──────────────────────────────────────────────────

    /**
     * La rutina nace INVISIBLE para el socio.
     *
     * `is_assigned = false` y sin fila en `member_routine_assignments`: son las
     * dos vías por las que «Mis Rutinas» encuentra una rutina, y mientras
     * ninguna esté puesta, no aparece. `member_id` sí se guarda —es de quién
     * es— pero por sí solo no la hace visible.
     *
     * @param  array<string,mixed>  $data
     */
    private function createRoutineDraft(
        Trainer $trainer,
        Member $member,
        array $data,
        ?int $assessmentId,
    ): Routine {
        $rutina = Routine::create([
            'name' => $data['name'],
            'objective' => $data['objective'] ?? null,
            'level' => $data['level'] ?? null,
            'description' => $data['description'] ?? null,
            'days_per_week' => $data['days_per_week'] ?? count($data['days']),
            'member_id' => $member->getKey(),
            'trainer_id' => $trainer->getKey(),
            'trainer_name' => $trainer->full_name,
            // Borrador: invisible hasta que alguien la publique.
            'is_assigned' => false,
            'is_template' => false,
            'created_by_admin' => false,
            'status' => 'Borrador',
            'source_assessment_id' => $assessmentId,
            'generated_by_ai' => true,
            // La estructura por días se guarda tal cual para poder editarla y
            // volver a pintarla; las filas de `routine_exercises` son la fuente
            // que consume la app del socio.
            'days' => $data['days'],
        ]);

        $orden = 0;
        foreach ($data['days'] as $dia) {
            foreach ($dia['exercises'] as $ej) {
                RoutineExercise::create([
                    'routine_id' => $rutina->id,
                    'exercise_id' => $ej['exercise_id'],
                    'sets' => $ej['sets'],
                    'reps' => $ej['reps'],
                    'notes' => $ej['notes'] ?? null,
                    'sort_order' => $orden++,
                ]);
            }
        }

        return $rutina;
    }

    // ── El prompt ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $contexto
     * @param  list<array<string,mixed>>  $catalogo
     * @return list<array{role:string,content:string}>
     */
    private function messages(array $contexto, array $catalogo): array
    {
        // Solo lo que influye en el plan. El nombre del socio, su teléfono o su
        // documento no cambian una rutina y no tienen por qué salir del sistema.
        $util = [
            'body_composition' => $contexto['body_composition'],
            'measurements' => $contexto['measurements'],
            'goal' => $contexto['goal'],
            'training' => $contexto['training'],
            'nutrition_constraints' => $contexto['nutrition_constraints'],
            'trainer_notes' => $contexto['trainer_notes'],
            'age_years' => $contexto['member']['age_years'] ?? null,
            'gender' => $contexto['member']['gender'] ?? null,
        ];

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => json_encode([
                'member' => $util,
                'exercise_catalog' => $catalogo,
            ], JSON_UNESCAPED_UNICODE)],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        Eres el asistente de un entrenador de gimnasio en Colombia. Preparas un
        BORRADOR de plan que una persona va a revisar antes de entregarlo. No
        hablas con el socio.

        Devuelves EXCLUSIVAMENTE un objeto JSON con esta forma:

        {
          "nutrition": {
            "objective": "texto corto",
            "objective_description": "texto",
            "training_stage": "texto corto",
            "meals": [{"label":"Desayuno","time":"07:00","description":"texto"}],
            "recommendations": "texto",
            "restrictions": "texto",
            "supplements": "texto",
            "notes": "texto"
          },
          "routine": {
            "name": "texto corto",
            "objective": "texto corto",
            "level": "principiante|intermedio|avanzado",
            "days": [
              {"name":"Día 1","exercises":[
                {"exercise_id":123,"sets":4,"reps":"8-10","rest_seconds":90,"notes":"texto"}
              ]}
            ]
          }
        }

        REGLAS QUE NO PUEDES SALTARTE:

        1. Los ejercicios SOLO pueden salir de `exercise_catalog`. Usa su `id`
           exacto en `exercise_id`. No inventes ejercicios ni ids: cualquier id
           que no esté en la lista será descartado y el plan quedará incompleto.
        2. No repitas el mismo ejercicio dentro de un mismo día.
        3. Entre 2 y 6 días, y entre 4 y 10 ejercicios por día.
        4. Respeta las lesiones y limitaciones que vengan en `training.injuries`:
           evita los ejercicios que las agraven.
        5. Respeta `nutrition_constraints.restrictions` en todas las comidas.
        6. Escribe en español de Colombia, claro y sin tecnicismos innecesarios.
        7. No des consejo médico ni diagnostiques. Si algo excede lo que un
           entrenador puede indicar, dilo en `notes` y no lo prescribas.

        Todo lo que escribas lo va a revisar y corregir un profesional antes de
        que lo vea nadie más.
        TXT;
    }

    // ── Registro ────────────────────────────────────────────────────────────

    /**
     * Deja constancia de cada intento en `nutrition_ai_runs`, que es donde ya
     * viven los del módulo de nutrición. No se crea otra tabla de auditoría:
     * el guardián de coste cuenta sobre ésta, y una tabla aparte lo dejaría
     * ciego a las generaciones de planes.
     *
     * El entrenador no está en el esquema de esa tabla; la trazabilidad de
     * quién generó queda en los borradores, que sí llevan `trainer_id`.
     *
     * @param  array<string,mixed>|null  $payload
     */
    private function recordRun(
        Member $member,
        string $model,
        string $hash,
        string $status,
        ?string $errorCode = null,
        ?array $payload = null,
    ): NutritionAiRun {
        return NutritionAiRun::create([
            'uuid' => (string) Str::uuid(),
            'member_id' => $member->getKey(),
            'mode' => self::RUN_MODE,
            'provider' => 'openai',
            'model' => $model,
            'input_hash' => $hash,
            'status' => $status,
            'error_code' => $errorCode,
            'prompt_version' => self::PROMPT_VERSION,
            'response_json' => $payload,
        ]);
    }

    private function assertEnabled(): void
    {
        $cfg = config('services.openai');

        if (! ($cfg['enabled'] ?? false) || empty($cfg['api_key'])) {
            Log::info('integral_plan:disabled');
            throw IntegralPlanException::disabled();
        }
    }
}
