<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Member;
use App\Models\NutritionAiRun;
use App\Models\NutritionGuide;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Services\IronAiMembershipAccessService;
use App\Services\Nutrition\Ai\NutritionAiClient;
use App\Services\Nutrition\Ai\NutritionAiCostGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
 *
 * POTENCIAS (docs/iron-ia/POTENCIAS-IRON-IA.md). Cuánto se planifica lo decide
 * el PLAN VIGENTE del socio, nunca la petición: con las dos potencias a 1 se
 * genera exactamente como siempre (una llamada, prompt v1); con alguna por
 * encima, {@see IntegralPlanPowerEngine} genera por secciones. La respuesta a
 * la app, los borradores y la regla de «los dos o ninguno» son los mismos.
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
        private readonly IronAiMembershipAccessService $access,
        private readonly IntegralPlanPowerEngine $engine,
        private readonly IntegralPlanEvaluator $evaluator,
        private readonly IntegralPlanAssembler $assembler,
        private readonly PlanSafetyLexicon $lexicon,
    ) {}

    /** Lo que puede durar una generación como mucho (30 s del nivel 1 + guardar), con margen. */
    private const LOCK_SECONDS = 90;

    /**
     * @return array{guide: NutritionGuide, routine: Routine, run: NutritionAiRun}
     *
     * @throws IntegralPlanException
     */
    public function generate(Trainer $trainer, Member $member): array
    {
        $this->assertEnabled();

        // A1: UNA generación a la vez por socio. Dos toques seguidos en la app
        // (o dos entrenadores) pagarían dos veces y dejarían dos borradores.
        $cerrojo = Cache::lock('integral-plan:'.$member->getKey(), self::LOCK_SECONDS);
        if (! $cerrojo->get()) {
            throw IntegralPlanException::busy();
        }

        try {
            $this->throttleTrainer($trainer);

            // Presupuesto PROPIO del plan integral (tokens del día y
            // generaciones por socio), separado del de nutrición de los socios.
            $guard = $this->costGuard->checkIntegralPlan($member);
            if (! ($guard['allowed'] ?? false)) {
                throw IntegralPlanException::costGuard((string) ($guard['reason'] ?? 'cost_guard'));
            }

            $catalogo = $this->catalog->forPrompt();
            if ($catalogo === []) {
                throw IntegralPlanException::emptyCatalog();
            }

            $contexto = $this->context->for($member);
            $potencias = $this->powersFor($member);
            $v1 = $potencias['nutrition'] === 1 && $potencias['routine'] === 1;

            // A1: la corrida existe ANTES de llamar (cuenta para el guardián
            // desde el primer segundo) y se cierra pase lo que pase.
            $run = $this->openRun(
                $member, $potencias,
                $v1 ? (string) config('services.openai.model', 'gpt-4.1-mini') : $this->poweredModel(),
                $v1 ? self::PROMPT_VERSION : IntegralPlanStrategy::PROMPT_VERSION,
            );

            try {
                return $v1
                    ? $this->generateV1($trainer, $member, $contexto, $catalogo, $run)
                    : $this->generatePowered($trainer, $member, $contexto, $catalogo, $potencias, $run);
            } finally {
                if ($run->refresh()->status === 'running') {
                    // Una excepción no prevista: la corrida no se queda colgada.
                    $run->update(['status' => 'failed', 'error_code' => 'exception']);
                }
            }
        } finally {
            $cerrojo->release();
        }
    }

    /**
     * A1: tope de generaciones por entrenador y ventana (configurable). Lo que
     * se rechaza aquí no llega a OpenAI ni crea corrida.
     *
     * @throws IntegralPlanException
     */
    private function throttleTrainer(Trainer $trainer): void
    {
        $max = (int) config('iron_ai.plan_powers.trainer_max_per_window', 10);
        if ($max <= 0) {
            return;
        }
        $clave = 'integral-plan:trainer:'.$trainer->getKey();
        if (RateLimiter::tooManyAttempts($clave, $max)) {
            throw IntegralPlanException::trainerRateLimited(RateLimiter::availableIn($clave));
        }
        RateLimiter::hit($clave, (int) config('iron_ai.plan_powers.trainer_window_seconds', 600));
    }

    /**
     * Las potencias que se APLICAN a este socio: las de su plan vigente
     * (`users.plan`, que tras una transferencia de membresía es el que
     * recibió el beneficiario). Sin plan vigente, nivel 1.
     *
     * @return array{nutrition: int, routine: int, source: string, plan_id: ?int, plan_name: ?string}
     */
    public function powersFor(Member $member): array
    {
        $member->loadMissing('user');
        $membresia = $this->access->getCurrentMembership($member, $member->user);

        return $this->access->getPlanPowers($membresia);
    }

    /**
     * Genera SIN GUARDAR NADA: ni borradores ni registro de la corrida. Lo
     * usa `php artisan iron-ia:evaluate` con perfiles ficticios para medir
     * calidad, tokens y latencia por nivel sin tocar datos de socios.
     *
     * @param  array<string,mixed>  $contexto  con la forma de MemberContextAssembler::for()
     * @param  array<string,mixed>  $brief  MemberContextAssembler::buildBrief()
     * @param  list<array<string,mixed>>  $catalogo
     * @return array{status: string, error_code: ?string, message: ?string, plan: ?array, usage: array<string,mixed>, latency_ms: int, evaluation: ?array, structured: array<string,mixed>}
     */
    public function preview(array $contexto, array $brief, array $catalogo, int $nutritionLevel, int $routineLevel): array
    {
        $this->assertEnabled();
        $inicio = microtime(true);

        if ($nutritionLevel === 1 && $routineLevel === 1) {
            $llamada = $this->callV1($contexto, $catalogo);
            $latencia = (int) round((microtime(true) - $inicio) * 1000);
            $uso = $this->v1Usage($llamada['respuesta']);

            try {
                $plan = $this->v1Safety($this->validateV1($llamada['respuesta']), $contexto);
                if (($riesgo = $this->riskInPlan($plan)) !== null) {
                    throw IntegralPlanException::malformed("el plan menciona «{$riesgo['found']}», que no se puede recomendar");
                }
            } catch (IntegralPlanException $e) {
                return ['status' => 'failed', 'error_code' => $e->code_, 'message' => $e->getMessage(), 'plan' => null,
                    'usage' => $uso, 'latency_ms' => $latencia, 'evaluation' => null, 'structured' => []];
            }

            return ['status' => 'ok', 'error_code' => null, 'message' => null, 'plan' => $plan, 'usage' => $uso,
                'latency_ms' => $latencia, 'evaluation' => $this->evaluator->evaluate($plan, [], $brief, 1, 1), 'structured' => []];
        }

        $res = $this->engine->generate($contexto, $brief, $catalogo, $nutritionLevel, $routineLevel, $this->poweredModel());
        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        return [
            'status' => $res['status'],
            'error_code' => $res['error_code'],
            'message' => $res['error']?->getMessage(),
            'plan' => $res['plan'],
            'usage' => $res['usage'],
            'latency_ms' => $latencia,
            'evaluation' => $res['payload']['quality']['evaluation'] ?? null,
            'structured' => $res['payload']['structured'] ?? [],
            'repairs_skipped_reason' => $res['payload']['repairs_skipped_reason'] ?? null,
        ];
    }

    // ── Nivel 1: el de siempre ──────────────────────────────────────────────

    /**
     * Las dos potencias a 1. El prompt, los parámetros y la salida son los de
     * antes de las potencias, byte a byte (lo fija
     * IntegralPlanPowersTest::test_nivel_1_envia_el_mismo_payload_que_produccion).
     * Solo se añadió robustez que no cambia una generación correcta: se lee
     * `finish_reason`, se guardan `usage` y latencia, y un texto largo ya no
     * se corta a mitad de palabra.
     *
     * @param  array<string,mixed>  $contexto
     * @param  list<array<string,mixed>>  $catalogo
     * @return array{guide: NutritionGuide, routine: Routine, run: NutritionAiRun, analysis: ?array}
     */
    private function generateV1(Trainer $trainer, Member $member, array $contexto, array $catalogo, NutritionAiRun $run): array
    {
        $inicio = microtime(true);
        $llamada = $this->callV1($contexto, $catalogo);
        $respuesta = $llamada['respuesta'];
        $hash = $llamada['hash'];

        $extra = $this->runMetrics(1, 1, $this->v1Usage($respuesta), (int) round((microtime(true) - $inicio) * 1000));

        if (($respuesta['status'] ?? '') !== NutritionAiClient::STATUS_SUCCESS) {
            $this->closeRun($run, $hash, 'failed', $respuesta['error_code'] ?? 'upstream', null, $extra);
            throw IntegralPlanException::upstream($respuesta['error_code'] ?? null);
        }

        // Validación COMPLETA antes de tocar la base. Si algo falla aquí, no hay
        // nada que deshacer porque no se ha escrito nada todavía.
        try {
            $plan = $this->validateV1($respuesta);
        } catch (IntegralPlanException $e) {
            // Una respuesta cortada por longitud se guarda tal cual llegó (antes
            // se perdía): es lo único que permite saber qué se estaba pidiendo.
            $cortada = ($respuesta['finish_reason'] ?? null) === 'length';
            $this->closeRun(
                $run, $hash, 'invalid',
                $cortada ? 'ai_truncated' : $e->code_,
                $cortada && ($respuesta['json'] ?? null) === null
                    ? ['truncated_content' => $respuesta['content'] ?? null]
                    : ($respuesta['json'] ?? null),
                $extra,
            );
            throw $e;
        }

        $plan = $this->v1Safety($plan, $contexto);

        // M2: un plan que nombra fármacos, purgas o ayunos prolongados no se
        // guarda. El nivel 1 no repara (es una llamada): se rechaza, reintentable.
        if (($riesgo = $this->riskInPlan($plan)) !== null) {
            $this->closeRun($run, $hash, 'invalid', 'risk_content', $plan, $extra);
            throw IntegralPlanException::malformed("el plan menciona «{$riesgo['found']}», que no se puede recomendar");
        }

        $resultado = $this->persist($trainer, $member, $plan, $contexto, $run);
        $this->closeRun($run, $hash, 'ok', null, $plan, $extra);

        return ['run' => $run->fresh()] + $resultado;
    }

    /**
     * Seguridad clínica del nivel 1 (autorizada por el propietario tras la
     * corrida 1). POSPROCESO, no prompt: lo que se envía a OpenAI no cambia y,
     * sin señales de riesgo, el plan sale INTACTO (lo fija la prueba de
     * paridad). Ver {@see IntegralPlanAssembler::clinicalNotice()}.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $contexto
     * @return array<string,mixed>
     */
    private function v1Safety(array $plan, array $contexto): array
    {
        return $this->assembler->clinicalNotice($plan, $this->context->riskSignals($contexto));
    }

    /**
     * El primer término de riesgo en cualquier texto del plan (guía, rutina,
     * síntesis), o null.
     *
     * @return array{term: string, found: string, snippet: string}|null
     */
    private function riskInPlan(mixed $v): ?array
    {
        if (is_string($v)) {
            return $this->lexicon->riskIn($v);
        }
        foreach (is_array($v) ? $v : [] as $x) {
            if (($r = $this->riskInPlan($x)) !== null) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $contexto
     * @param  list<array<string,mixed>>  $catalogo
     * @return array{respuesta: array<string,mixed>, modelo: string, hash: string}
     */
    private function callV1(array $contexto, array $catalogo): array
    {
        $mensajes = $this->messages($contexto, $catalogo);

        $modelo = (string) config('services.openai.model', 'gpt-4.1-mini');
        $hash = hash('sha256', self::PROMPT_VERSION.'|'.json_encode($mensajes));

        $respuesta = $this->ai->complete($modelo, $mensajes, json: true, maxTokens: 3000);

        return ['respuesta' => $respuesta, 'modelo' => $modelo, 'hash' => $hash];
    }

    /**
     * @param  array<string,mixed>  $respuesta
     * @return array<string,mixed>
     *
     * @throws IntegralPlanException
     */
    private function validateV1(array $respuesta): array
    {
        if (($respuesta['status'] ?? '') !== NutritionAiClient::STATUS_SUCCESS) {
            throw IntegralPlanException::upstream($respuesta['error_code'] ?? null);
        }

        // Cortada por el tope de tokens: el JSON no está completo aunque a
        // veces llegue a decodificar. Se dice qué pasó en vez de «mal formada».
        if (($respuesta['finish_reason'] ?? null) === 'length') {
            throw IntegralPlanException::malformed('la respuesta se cortó por longitud');
        }

        return $this->validator->validate($respuesta['json'] ?? null);
    }

    // ── Niveles 2..5 ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $contexto
     * @param  list<array<string,mixed>>  $catalogo
     * @param  array{nutrition: int, routine: int}  $potencias
     * @return array{guide: NutritionGuide, routine: Routine, run: NutritionAiRun, analysis: ?array}
     */
    private function generatePowered(Trainer $trainer, Member $member, array $contexto, array $catalogo, array $potencias, NutritionAiRun $run): array
    {
        $brief = $this->context->planBrief($member, $contexto);
        $modelo = $this->poweredModel();

        $inicio = microtime(true);
        $res = $this->engine->generate($contexto, $brief, $catalogo, $potencias['nutrition'], $potencias['routine'], $modelo);
        $extra = $this->runMetrics(
            $potencias['nutrition'], $potencias['routine'], $res['usage'],
            (int) round((microtime(true) - $inicio) * 1000),
        );

        // UNA fila por generación, salga bien o mal: el guardián de coste
        // cuenta filas, y una generación por secciones sigue siendo una.
        if ($res['status'] !== 'ok') {
            $this->closeRun($run, $res['hash'], $res['status'], $res['error_code'], $res['payload'], $extra);
            throw $res['error'];
        }

        $resultado = $this->persist($trainer, $member, $res['plan'], $contexto, $run);
        $this->closeRun($run, $res['hash'], 'ok', null, $res['payload'], $extra);

        return ['run' => $run->fresh()] + $resultado;
    }

    private function poweredModel(): string
    {
        return (string) (config('iron_ai.plan_powers.model') ?: config('services.openai.model', 'gpt-4.1-mini'));
    }

    // ── Persistencia: la misma para todos los niveles ───────────────────────

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $contexto
     * @return array{guide: NutritionGuide, routine: Routine, run: NutritionAiRun, analysis: ?array}
     */
    private function persist(Trainer $trainer, Member $member, array $plan, array $contexto, NutritionAiRun $run): array
    {
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

            return [
                'guide' => $guia->fresh(),
                'routine' => $rutina->fresh('routineExercises'),
                'run' => $run,
                'analysis' => $plan['analysis'] ?? null,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $uso
     * @return array<string,mixed>
     */
    private function runMetrics(int $nutrition, int $routine, array $uso, int $latencia): array
    {
        $sinMedir = ! isset($uso['total_tokens']) && ! isset($uso['prompt_tokens']);

        return [
            'nutrition_power' => $nutrition,
            'routine_power' => $routine,
            'prompt_tokens' => $sinMedir ? null : (int) ($uso['prompt_tokens'] ?? 0),
            'completion_tokens' => $sinMedir ? null : (int) ($uso['completion_tokens'] ?? 0),
            'total_tokens' => $sinMedir ? null : (int) ($uso['total_tokens'] ?? 0),
            'latency_ms' => $latencia,
            'usage_json' => $sinMedir ? null : $uso,
        ];
    }

    /** @return array<string,mixed> */
    private function v1Usage(array $respuesta): array
    {
        $u = is_array($respuesta['usage'] ?? null) ? $respuesta['usage'] : [];
        if ($u === []) {
            return [];
        }

        return [
            'prompt_tokens' => (int) ($u['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($u['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($u['total_tokens'] ?? 0),
            'calls' => 1,
            'repairs' => 0,
            'finish_reason' => $respuesta['finish_reason'] ?? null,
        ];
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
          "analysis": {
            "profile": "una frase: objetivo, nivel y disponibilidad",
            "priorities": ["prioridad corta", "otra"],
            "approach": "enfoque recomendado, 2-3 frases",
            "limitations": "limitaciones relevantes, o cadena vacía si no hay",
            "strategy": "estrategia general de entrenamiento y alimentación"
          },
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
        8. En `analysis` razonas sobre lo que te dieron; NO inventas datos del
           socio. Si no conoces su peso, su grasa corporal, sus medidas, sus
           alergias o sus lesiones, NO te los imagines: di que no constan. Una
           recomendación es tuya; una medición es del socio y solo existe si
           alguien la tomó.

        Todo lo que escribas lo va a revisar y corregir un profesional antes de
        que lo vea nadie más.
        TXT;
    }

    // ── Registro ────────────────────────────────────────────────────────────

    /**
     * Deja constancia de cada intento en `nutrition_ai_runs`, que es donde ya
     * viven los del módulo de nutrición. No se crea otra tabla de auditoría.
     *
     * Se ABRE en `running` antes de llamar a OpenAI (A1: así cuenta para el
     * guardián desde el primer segundo y una caída a mitad deja rastro) y se
     * cierra con {@see closeRun()}; si nada la cierra, lo hace el `finally`
     * de {@see generate()} como `failed`/`exception`.
     *
     * El entrenador no está en el esquema de esa tabla; la trazabilidad de
     * quién generó queda en los borradores, que sí llevan `trainer_id`.
     *
     * @param  array{nutrition: int, routine: int}  $potencias
     */
    private function openRun(Member $member, array $potencias, string $model, string $promptVersion): NutritionAiRun
    {
        return NutritionAiRun::create([
            'uuid' => (string) Str::uuid(),
            'member_id' => $member->getKey(),
            'mode' => self::RUN_MODE,
            'provider' => 'openai',
            'model' => $model,
            'status' => 'running',
            'prompt_version' => $promptVersion,
            'nutrition_power' => $potencias['nutrition'],
            'routine_power' => $potencias['routine'],
        ]);
    }

    /**
     * `$extra`: nivel de potencia, tokens, latencia y detalle por sección
     * (columnas nulables: null es «no se midió»).
     *
     * @param  array<string,mixed>|null  $payload
     * @param  array<string,mixed>  $extra
     */
    private function closeRun(NutritionAiRun $run, string $hash, string $status, ?string $errorCode, ?array $payload, array $extra): void
    {
        $run->update(array_merge([
            'input_hash' => $hash,
            'status' => $status,
            'error_code' => $errorCode,
            'response_json' => $payload,
        ], $extra));
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
