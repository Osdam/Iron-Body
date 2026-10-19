<?php

namespace App\Services\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Un PASO de una ejecución del laboratorio (contrato H4 §A3): como mucho UN
 * caso por paso, y un job por paso ({@see StudioLabStepJob}). Es una
 * SIMULACIÓN DEL STUDIO, no ULTRON: no escribe nada del motor, no usa sus
 * prompts ni n8n, y NADA APRUEBA SOLO (esta clase no nombra el veredicto).
 *
 *  1. Carga la ejecución; si es terminal, no hace nada.
 *  2. Si se pidió cancelarla, la cierra `cancelled` (los pendientes, `skipped`).
 *  3. Bandera apagada o configuración rota → `stopped` con su motivo.
 *  4. En cola desde hace más de 30 min → `stopped` (`expired`).
 *  5. Si hay un caso en curso en el sistema desde hace menos de 150 s, vuelve
 *     a encolarse con 30 s de retraso: un caso a la vez en todo el sistema.
 *  6. Reclama el siguiente caso con un UPDATE condicionado `pending →
 *     running`: dos pasos duplicados nunca procesan el mismo caso. Antes,
 *     BAJO EL CERROJO, vuelve a leer la petición de cancelar (Enmienda 4.5):
 *     la del punto 2 es de antes de esperar el cerrojo, y una cancelación
 *     confirmada mientras tanto nunca deja reclamar (ni pagar) otro caso.
 *  7. Pasado el plazo de 30 min → `stopped` (`deadline`). En `openai`, con lo
 *     comprometido del día por encima del tope diario, o con el coste REAL
 *     de la ejecución por encima de su tope (el menor entre el tope por
 *     ejecución y el máximo que aceptó la persona: Enmienda 4.1) → `stopped`
 *     (`budget_exhausted`, y el resumen dice qué tope). Se comprueba antes de
 *     reclamar, después de liquidar el caso anterior: el siguiente caso no
 *     llega a empezar.
 *  8. Actor → pruebas deterministas → juez → agregación → liquidación. El
 *     caso termina `done` o `error` con su clase. Sin transacción abierta
 *     mientras se espera al proveedor.
 *  9. La política de proveedor (A5 y Enmienda 1): auth, cuota y rechazo paran;
 *     dos errores seguidos (POR CASO) paran; un 429 de ritmo retrasa 30 s.
 * 10. Si quedan casos, encola el siguiente paso; si no, cierra: resumen,
 *     propuesta, `results_hash`, `completed` y `run.finished`.
 *
 * CERROJO DE LOS RECLAMOS. Reclamar y cerrar van detrás del mismo cerrojo de
 * transacción (PostgreSQL), así que un cierre nunca deja un caso a medias y el
 * «uno a la vez» del sistema es atómico. Cerrar con un caso en curso no se
 * hace: el paso vuelve a encolarse y la llamada en vuelo termina (y se cobra).
 *
 * LOS CORTES DE TIEMPO (30 min, 150 s) se calculan aquí en UTC y viajan como
 * parámetros, nunca con el reloj de SQL.
 */
final class StudioLabRunner
{
    /** Un caso en curso desde hace más que el timeout del job está perdido. */
    public const STALE_AFTER_SECONDS = 150;

    /** El retraso de un paso que espera: otro caso en curso o un 429 de ritmo. */
    public const REQUEUE_DELAY_SECONDS = 30;

    /** Clases de un caso que no vienen del proveedor (no cuentan para la racha). */
    public const CLASS_WORKER_LOST = 'worker_lost';

    public const CLASS_INTERNAL_ERROR = 'internal_error';

    public const CLASS_BLOCKED_INPUT = 'blocked_input';

    /** Lo que se le enseña al personal de esas clases. */
    public const OWN_MESSAGES = [
        self::CLASS_WORKER_LOST => 'El proceso que ejecutaba el caso se perdió. Se cobra su reserva: no se sabe si el proveedor cobró.',
        self::CLASS_INTERNAL_ERROR => 'El caso falló por un error interno del laboratorio. Se cobra su reserva: no se sabe si el proveedor cobró.',
        self::CLASS_BLOCKED_INPUT => 'Un texto del caso no pasó el filtro de datos personales: no se envió al proveedor.',
    ];

    /**
     * Qué tope paró una ejecución con `budget_exhausted` (Enmienda 4.1: el
     * motivo se reutiliza y el resumen dice cuál). Va como `budgetCap
     * {kind, capMicros}` en el resumen de la ejecución y en el `diff` de
     * `run.finished`, y con su frase en el resumen del evento.
     */
    public const BUDGET_CAP_DAILY = 'daily';

    public const BUDGET_CAP_RUN = 'run';

    public const BUDGET_CAP_ACCEPTED = 'accepted';

    public const BUDGET_CAP_SUMMARIES = [
        self::BUDGET_CAP_DAILY => 'lo comprometido del día superó el tope diario',
        self::BUDGET_CAP_RUN => 'el coste real superó el tope por ejecución',
        self::BUDGET_CAP_ACCEPTED => 'el coste real superó el máximo aceptado al lanzarla',
    ];

    public function __construct(private readonly StudioLabEvents $events) {}

    /**
     * Un paso. Devuelve qué pasó (para las pruebas y el registro): `noop`,
     * `busy`, `closed`, `retry`, `case` u otro código corto.
     */
    public function step(int $runId): string
    {
        $run = StudioLabRun::query()->find($runId);
        if ($run === null || $run->isTerminal()) {
            return 'noop';
        }

        $ahora = now();

        // Un caso de ESTA ejecución en curso: reciente, otro paso lo está
        // procesando (se espera); viejo, su proceso murió sin `failed()`.
        foreach ($this->runningCases($runId) as $caso) {
            if ($caso->started_at !== null && $caso->started_at->gt($ahora->copy()->subSeconds(self::STALE_AFTER_SECONDS))) {
                $this->requeue($runId, self::REQUEUE_DELAY_SECONDS);

                return 'busy';
            }
            $this->settleWithoutAnswer($run, $caso, self::CLASS_WORKER_LOST);
        }

        $cfg = StudioLabConfig::current();

        // 2. Cancelar.
        if ($run->cancel_requested_at !== null) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_CANCELLED, null);
        }
        // 3. Bandera y configuración. Una ejecución `openai` necesita la misma
        // configuración con la que se creó (el modelo congelado); una `fake` no
        // necesita nada: no gasta ni sale a la red.
        if (! $cfg->enabled) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_LAB_DISABLED);
        }
        if ($run->mode === StudioLabConfig::MODE_OPENAI && (! $cfg->isOpenAi() || ! $cfg->configured() || $cfg->model !== $run->model)) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_NOT_CONFIGURED);
        }
        // 4. Caducidad en cola.
        if ($run->status === StudioLabRun::STATUS_QUEUED && $run->created_at !== null
            && $run->created_at->lt($ahora->copy()->subMinutes(StudioLabConfig::QUEUED_EXPIRY_MINUTES))) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_EXPIRED);
        }
        // 5. Un caso a la vez en todo el sistema.
        if ($this->busy($ahora)) {
            $this->requeue($runId, self::REQUEUE_DELAY_SECONDS);

            return 'busy';
        }
        // 7. El plazo y el presupuesto del día.
        if ($run->started_at !== null && $run->started_at->lt($ahora->copy()->subMinutes(StudioLabConfig::RUN_DEADLINE_MINUTES))) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_DEADLINE);
        }
        if ($run->mode === StudioLabConfig::MODE_OPENAI) {
            ['spent' => $gastado, 'reserved' => $reservado] = StudioLabRuns::committed((string) $run->budget_day);
            // «Alcanza el tope» se lee como «lo supera»: con todo reservado, lo
            // admitido cabe justo y seguir no lo sobrepasa; solo un exceso real
            // (una estimación corta) deja el día por encima.
            if ($gastado + $reservado > $cfg->dailyBudgetMicros) {
                return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_BUDGET_EXHAUSTED,
                    ['kind' => self::BUDGET_CAP_DAILY, 'capMicros' => $cfg->dailyBudgetMicros]);
            }
            // El tope de ESTA ejecución, sobre su coste REAL (Enmienda 4.1): la
            // estimación cuenta 3 bytes por token y el proveedor puede facturar
            // más entrada. Se lee de la fila (lo liquidado hasta ahora, también
            // un caso perdido que se acaba de cobrar arriba) y, como el del
            // día, solo para si queda algo por reclamar.
            $tope = self::runCap($run, $cfg);
            if ((int) StudioLabRun::query()->whereKey($runId)->value('cost_micros') > $tope['capMicros']) {
                return $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, StudioLabRun::STOP_BUDGET_EXHAUSTED, $tope);
            }
        }

        // 6. Reclamar el siguiente caso.
        $reclamo = $this->claim($run, $ahora);
        if ($reclamo === 'cancelled') {
            // Se pidió cancelarla mientras el paso esperaba el cerrojo.
            return $this->closeOrRetry($run, StudioLabRun::STATUS_CANCELLED, null);
        }
        if ($reclamo === 'busy') {
            $this->requeue($runId, self::REQUEUE_DELAY_SECONDS);

            return 'busy';
        }
        if ($reclamo === 'empty') {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_COMPLETED, null);
        }
        if (! $reclamo instanceof StudioLabRunCase) {
            // `gone` (otro la cerró) o `lost` (otro paso reclamó el caso): ese sigue.
            return $reclamo;
        }

        // 8 y 9. El caso y lo que su resultado le hace a la ejecución.
        $efecto = $this->process($run, $reclamo);
        if (str_starts_with($efecto, StudioLabOutcome::EFFECT_STOP)) {
            $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, substr($efecto, strlen(StudioLabOutcome::EFFECT_STOP)));

            return 'stopped';
        }

        // 10. El siguiente paso, o el cierre.
        if (! StudioLabRunCase::query()->where('run_id', $runId)->where('status', StudioLabRunCase::STATUS_PENDING)->exists()) {
            return $this->closeOrRetry($run, StudioLabRun::STATUS_COMPLETED, null);
        }
        $this->requeue($runId, $efecto === StudioLabOutcome::EFFECT_DELAY ? self::REQUEUE_DELAY_SECONDS : 0);

        return 'case';
    }

    /**
     * `failed()` del job (timeout del job o worker perdido): el caso en curso
     * pasa a `error` (`timeout`, `worker_lost` o `internal_error`) con COSTE =
     * SU RESERVA ENTERA, porque no se sabe si se cobró, y se encola el
     * siguiente paso (o se para, si es el segundo error seguido). Sin caso en
     * curso no hay nada que liquidar ni progreso que hacer: no se encola nada
     * (un fallo repetido antes de reclamar no puede girar en bucle).
     */
    public function failed(int $runId, ?Throwable $e): void
    {
        $clase = match (true) {
            $e instanceof TimeoutExceededException => StudioLabModelClient::CLASS_TIMEOUT,
            $e instanceof MaxAttemptsExceededException => self::CLASS_WORKER_LOST,
            default => self::CLASS_INTERNAL_ERROR,
        };
        $run = StudioLabRun::query()->find($runId);
        // Por su clase, nunca por su mensaje.
        Log::warning('ultron_studio.lab.step_failed', ['run' => $run?->uuid, 'class' => $clase, 'exception' => $e !== null ? class_basename($e) : null]);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        $caso = $this->runningCases($runId)->first();
        if ($caso === null || ! $this->settleWithoutAnswer($run, $caso, $clase)) {
            return;
        }

        $efecto = isset(StudioLabModelClient::POLICY[$clase])
            ? StudioLabOutcome::callEffect($clase, $this->streakBefore($runId, (int) $caso->position))
            : StudioLabOutcome::EFFECT_CONTINUE;
        if (str_starts_with($efecto, StudioLabOutcome::EFFECT_STOP)) {
            $this->closeOrRetry($run, StudioLabRun::STATUS_STOPPED, substr($efecto, strlen(StudioLabOutcome::EFFECT_STOP)));

            return;
        }
        $this->requeue($runId, 0);
    }

    /**
     * Cierra la ejecución en `$status`: los casos pendientes pasan a `skipped`
     * y la fila de la ejecución recibe en UN SOLO UPDATE el resumen, la
     * propuesta (solo `completed` y `openai`), la huella de los resultados, el
     * coste y `finished_at` (después la base ya no admite ningún UPDATE).
     * Escribe `run.finished`. Devuelve false, sin escribir nada, si hay un caso
     * en curso o si otra petición la movió antes (UPDATE condicionado a
     * `$from`).
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra  columnas mutables que van en el mismo UPDATE (la petición de cancelar)
     * @param  array{kind:string,capMicros:int}|null  $budgetCap  qué tope paró la ejecución (solo `budget_exhausted`)
     */
    public function close(StudioLabRun $run, string $status, ?string $stopReason, ?Admin $actor = null, array $from = StudioLabRun::LIVE_STATUSES, array $extra = [], ?array $budgetCap = null): bool
    {
        if (! in_array($status, StudioLabRun::TERMINAL_STATUSES, true)) {
            throw new LogicException('ultron_studio_lab: solo se cierra en un estado terminal.');
        }

        return DB::transaction(function () use ($run, $status, $stopReason, $actor, $from, $extra, $budgetCap): bool {
            $this->serializeClaims();
            $desde = StudioLabRun::query()->whereKey($run->id)->value('status');
            if (! in_array($desde, $from, true)
                || StudioLabRunCase::query()->where('run_id', $run->id)->where('status', StudioLabRunCase::STATUS_RUNNING)->exists()) {
                return false;
            }

            // Los pendientes se saltan: la huella y el resumen ya los ven saltados.
            $ahora = now();
            $casos = StudioLabRunCase::query()->where('run_id', $run->id)->orderBy('position')->get();
            $saltados = 0;
            foreach ($casos as $c) {
                if ($c->status === StudioLabRunCase::STATUS_PENDING) {
                    $c->status = StudioLabRunCase::STATUS_SKIPPED;
                    $saltados++;
                }
            }
            $resumen = self::summarize($casos);
            $propuesta = StudioLabOutcome::proposal($status, $run->mode, $casos->map(static fn (StudioLabRunCase $c): array => ['status' => $c->status, 'outcome' => $c->outcome])->all());

            if (! StudioLabRun::advance($run->id, $from, $extra + [
                'status' => $status,
                'stop_reason' => $stopReason,
                'summary' => $budgetCap !== null ? $resumen + ['budgetCap' => $budgetCap] : $resumen,
                'ai_recommendation' => $propuesta,
                'results_hash' => StudioLabRuns::resultsHash($run, $casos),
                'cost_micros' => $resumen['costMicros'],
                'input_tokens' => $resumen['inputTokens'],
                'output_tokens' => $resumen['outputTokens'],
                'finished_at' => $ahora,
            ])) {
                return false;
            }
            if ($saltados > 0) {
                $filas = StudioLabRunCase::query()->where('run_id', $run->id)->where('status', StudioLabRunCase::STATUS_PENDING)
                    ->update(['status' => StudioLabRunCase::STATUS_SKIPPED, 'finished_at' => $ahora]);
                if ($filas !== $saltados) {
                    // Bajo el cerrojo no puede pasar: la huella ya no diría lo que hay.
                    throw new LogicException('ultron_studio_lab: un caso cambió mientras se cerraba la ejecución.');
                }
            }

            $this->events->record($actor, 'run.finished', StudioLabEvent::SUBJECT_RUN, $run->uuid,
                self::finishedSummary($status, $stopReason, $budgetCap), [
                    'fromStatus' => $desde,
                    'toStatus' => $status,
                    'diff' => [
                        'status' => $status,
                        'stopReason' => $stopReason,
                        'outcomes' => $resumen['outcomes'],
                        'costMicros' => $resumen['costMicros'],
                        'aiRecommendation' => $propuesta,
                    ] + ($budgetCap !== null ? ['budgetCap' => $budgetCap] : []),
                ], StudioLabEvents::SYSTEM_RUNNER);

            return true;
        });
    }

    /**
     * EL cerrojo de los reclamos y los cierres (PostgreSQL). READ COMMITTED
     * primero, como el del presupuesto. SQLite escribe en serie.
     */
    public function serializeClaims(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ultron_studio_lab:claim')]);
        }
    }

    /** El mensaje fijo de un código de error de caso. Nunca nada del proveedor. */
    public static function errorMessage(string $code): string
    {
        return StudioLabModelClient::MESSAGES[$code] ?? self::OWN_MESSAGES[$code] ?? 'El caso no se pudo completar.';
    }

    /**
     * El resumen de unos casos: progreso, resultados, coste, exceso y tokens.
     *
     * @param  iterable<StudioLabRunCase>  $cases
     * @return array{progress:array<string,int>,outcomes:array<string,int>,costMicros:int,overrunMicros:int,inputTokens:int,outputTokens:int}
     */
    public static function summarize(iterable $cases): array
    {
        $coste = 0;
        $exceso = 0;
        $entrada = 0;
        $salida = 0;
        foreach ($cases as $c) {
            $coste += (int) $c->cost_micros;
            $exceso += (int) $c->overrun_micros;
            $entrada += (int) $c->input_tokens;
            $salida += (int) $c->output_tokens;
        }

        return StudioLabRuns::countsOf($cases) + ['costMicros' => $coste, 'overrunMicros' => $exceso, 'inputTokens' => $entrada, 'outputTokens' => $salida];
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * El reclamo, detrás del cerrojo: no se pidió cancelarla, nadie más en
     * curso, la ejecución sigue viva (y pasa a `running` si estaba en cola) y
     * el siguiente caso pasa de `pending` a `running` con un UPDATE
     * condicionado.
     *
     * La petición de cancelar se vuelve a leer AQUÍ (Enmienda 4.5): la que
     * cargó el paso es de antes de esperar el cerrojo, y `cancel()` escribe
     * detrás de este mismo cerrojo, así que la que ya se confirmó se ve.
     * Entonces no se reclama nada: `cancelled`, y el paso la cierra.
     */
    private function claim(StudioLabRun $run, Carbon $ahora): StudioLabRunCase|string
    {
        return DB::transaction(function () use ($run, $ahora): StudioLabRunCase|string {
            $this->serializeClaims();
            $fila = StudioLabRun::query()->whereKey($run->id)->first(['id', 'status', 'cancel_requested_at']);
            if ($fila?->cancel_requested_at !== null && in_array($fila->status, StudioLabRun::LIVE_STATUSES, true)) {
                return 'cancelled';
            }
            if ($this->busy($ahora)) {
                return 'busy';
            }

            $actual = $fila?->status;
            $viva = match ($actual) {
                StudioLabRun::STATUS_QUEUED => StudioLabRun::advance($run->id, [StudioLabRun::STATUS_QUEUED], ['status' => StudioLabRun::STATUS_RUNNING, 'started_at' => $ahora]),
                StudioLabRun::STATUS_RUNNING => StudioLabRun::advance($run->id, [StudioLabRun::STATUS_RUNNING], ['status' => StudioLabRun::STATUS_RUNNING]),
                default => false,
            };
            if (! $viva) {
                return 'gone';
            }

            $siguiente = StudioLabRunCase::query()->where('run_id', $run->id)->where('status', StudioLabRunCase::STATUS_PENDING)->orderBy('position')->first();
            if ($siguiente === null) {
                return 'empty';
            }
            if (! StudioLabRunCase::advance($siguiente->id, [StudioLabRunCase::STATUS_PENDING], ['status' => StudioLabRunCase::STATUS_RUNNING, 'started_at' => $ahora])) {
                return 'lost';
            }

            return $siguiente->refresh();
        });
    }

    /**
     * Un caso de principio a fin: actor, pruebas deterministas, juez,
     * agregación y liquidación. Devuelve el efecto en la ejecución
     * (`continue`, `delay` o `stop:<motivo>`).
     */
    private function process(StudioLabRun $run, StudioLabRunCase $caso): string
    {
        $target = is_array($run->target_snapshot) ? $run->target_snapshot : [];
        $escenario = is_array($caso->scenario_snapshot) ? $caso->scenario_snapshot : [];
        $precios = ['input' => (int) ($run->prices['input'] ?? 0), 'output' => (int) ($run->prices['output'] ?? 0)];
        $maxActor = (int) ($run->prices['actorMaxOutputTokens'] ?? StudioLabConfig::DEFAULT_ACTOR_MAX_OUTPUT_TOKENS);
        $maxJuez = (int) ($run->prices['judgeMaxOutputTokens'] ?? StudioLabConfig::DEFAULT_JUDGE_MAX_OUTPUT_TOKENS);

        try {
            $estimado = StudioLabCost::estimateScenario($target, $escenario, $maxActor, $maxJuez, $precios);
            $entradaActor = StudioLabPrompts::actorInput($target, $escenario);
        } catch (InvalidArgumentException) {
            // El filtro de datos personales cambió desde que se creó: no se envía nada.
            $this->settle($run, $caso, self::errorValues(self::CLASS_BLOCKED_INPUT, [], 0, (int) $caso->reserved_micros));

            return StudioLabOutcome::EFFECT_CONTINUE;
        }
        $topeActor = StudioLabCost::callEstimate($estimado['actorInputTokens'], $maxActor, $precios)['maxMicros'];
        $topeJuez = StudioLabCost::callEstimate($estimado['judgeInputTokens'], $maxJuez, $precios)['maxMicros'];
        $cliente = $this->client($run, $target);

        $a = $cliente->complete(StudioLabPrompts::ROLE_ACTOR, StudioLabPrompts::ACTOR_INSTRUCTIONS, $entradaActor, StudioLabPrompts::actorSchema(), $maxActor);
        $actor = StudioLabPrompts::readActorCall($a);
        $llamadas = [$this->call($run, $caso, StudioLabPrompts::ROLE_ACTOR, $actor['class'], $a, $topeActor, $precios)];

        if ($actor['class'] !== null) {
            // Falló el actor: el caso es `error` con su clase.
            $clase = $actor['class'];
            $coste = (int) $llamadas[0]['costMicros'];
            $valores = self::errorValues($clase, $llamadas, $coste, (int) $caso->reserved_micros);
        } else {
            $reply = (string) $actor['reply'];
            $hallazgos = [
                ...StudioLabChecks::check($reply, $target),
                ...(new StudioLabEngineChecks)->check($reply, is_array($escenario['facts'] ?? null) ? $escenario['facts'] : StudioLabCatalog::DEFAULT_FACTS),
            ];
            $criterios = array_values(array_filter(is_array($escenario['criteria'] ?? null) ? $escenario['criteria'] : [], 'is_array'));
            $reglas = array_values(array_filter(is_array($target['rules'] ?? null) ? $target['rules'] : [], 'is_array'));

            $j = $cliente->complete(StudioLabPrompts::ROLE_JUDGE, StudioLabPrompts::JUDGE_INSTRUCTIONS, StudioLabPrompts::judgeInput($target, $escenario, $reply), StudioLabPrompts::judgeSchema(), $maxJuez);
            $juez = StudioLabPrompts::readJudgeCall($j, array_column($criterios, 'id'), array_column($reglas, 'code'));
            $llamadas[] = $this->call($run, $caso, StudioLabPrompts::ROLE_JUDGE, $juez['class'], $j, $topeJuez, $precios);

            // Si falló el juez, el caso es `done` sin opinión de la IA y llega
            // como mucho a `review` (Enmienda 1, decisión 3). La IA no decide.
            $clase = $juez['class'];
            $coste = array_sum(array_column($llamadas, 'costMicros'));
            $valores = [
                'status' => StudioLabRunCase::STATUS_DONE,
                'outcome' => StudioLabOutcome::forCase($hallazgos, $juez['judge'], $criterios),
                'error_code' => null,
                'reply' => $reply,
                'checks' => $hallazgos,
                'judge' => $juez['judge'],
            ] + self::settlement($llamadas, $coste, (int) $caso->reserved_micros);
        }

        if (! $this->settle($run, $caso, $valores)) {
            // Otro (el `failed()` de un paso duplicado) ya lo liquidó: esta vez no cuenta.
            return StudioLabOutcome::EFFECT_CONTINUE;
        }

        return StudioLabOutcome::callEffect($clase, $this->streakBefore((int) $run->id, (int) $caso->position));
    }

    /**
     * El registro de UNA llamada (`calls` del caso) y, si falló, una línea en
     * el log: `{run, posición, papel, clase, http, excepción}`, sin cuerpos ni
     * cabeceras, y la excepción por su clase.
     *
     * @param  array<string, mixed>  $resultado  el de {@see StudioLabModelClient::complete()}
     * @param  array{input:int,output:int}  $precios
     * @return array{role:string,class:?string,inputTokens:?int,outputTokens:?int,costMicros:int,latencyMs:int}
     */
    private function call(StudioLabRun $run, StudioLabRunCase $caso, string $role, ?string $clase, array $resultado, int $tope, array $precios): array
    {
        $uso = is_array($resultado['usage'] ?? null) ? $resultado['usage'] : null;
        if ($clase !== null) {
            Log::warning('ultron_studio.lab.provider_call', [
                'run' => $run->uuid,
                'position' => (int) $caso->position,
                'role' => $role,
                'class' => $clase,
                'http' => $resultado['http'] ?? null,
                'exception' => $resultado['exception'] ?? null,
            ]);
        }

        return [
            'role' => $role,
            'class' => $clase,
            'inputTokens' => $uso !== null ? (int) $uso['inputTokens'] : null,
            'outputTokens' => $uso !== null ? (int) $uso['outputTokens'] : null,
            'costMicros' => StudioLabCost::callMicros($clase, $uso, $tope, $precios),
            'latencyMs' => (int) ($resultado['latencyMs'] ?? 0),
        ];
    }

    /**
     * La liquidación del caso en UN UPDATE `running → done|error`, y los
     * totales de la ejecución, en la misma transacción. false si otro lo
     * liquidó antes.
     *
     * @param  array<string, mixed>  $valores
     */
    private function settle(StudioLabRun $run, StudioLabRunCase $caso, array $valores): bool
    {
        return DB::transaction(function () use ($run, $caso, $valores): bool {
            if (! StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], $valores + ['finished_at' => now()])) {
                return false;
            }

            $totales = StudioLabRunCase::query()->toBase()->where('run_id', $run->id)
                ->selectRaw('COALESCE(SUM(cost_micros), 0) AS coste, COALESCE(SUM(input_tokens), 0) AS entrada, COALESCE(SUM(output_tokens), 0) AS salida')
                ->first();
            StudioLabRun::advance($run->id, StudioLabRun::LIVE_STATUSES, [
                'cost_micros' => (int) $totales->coste,
                'input_tokens' => (int) $totales->entrada,
                'output_tokens' => (int) $totales->salida,
            ]);

            return true;
        });
    }

    /**
     * Un caso en curso que se queda sin respuesta (el job murió o caducó): pasa
     * a `error` con la RESERVA ENTERA como coste, porque no se sabe si el
     * proveedor cobró.
     */
    private function settleWithoutAnswer(StudioLabRun $run, StudioLabRunCase $caso, string $clase): bool
    {
        Log::warning('ultron_studio.lab.case_lost', ['run' => $run->uuid, 'position' => (int) $caso->position, 'class' => $clase]);

        return $this->settle($run, $caso, self::errorValues($clase, [], (int) $caso->reserved_micros, (int) $caso->reserved_micros));
    }

    /**
     * La racha antes de un caso (Enmienda 1, decisión 2): los casos anteriores
     * ya liquidados, en orden, con {@see StudioLabOutcome::nextStreak()}. Un
     * caso `done` con juez reinicia; las clases que no son del proveedor
     * (`worker_lost`, `internal_error`, `blocked_input`) ni suman ni reinician.
     */
    private function streakBefore(int $runId, int $position): int
    {
        $racha = 0;
        $casos = StudioLabRunCase::query()
            ->where('run_id', $runId)
            ->where('position', '<', $position)
            ->whereIn('status', [StudioLabRunCase::STATUS_DONE, StudioLabRunCase::STATUS_ERROR])
            ->orderBy('position')
            ->get(['id', 'position', 'status', 'error_code', 'calls']);
        foreach ($casos as $c) {
            $clase = self::classOf($c);
            if ($clase !== null && ! isset(StudioLabModelClient::POLICY[$clase])) {
                continue;
            }
            $racha = StudioLabOutcome::nextStreak($clase, $racha);
        }

        return $racha;
    }

    /** La clase con la que terminó un caso: la del error, o la del juez si el caso es `done`. */
    private static function classOf(StudioLabRunCase $c): ?string
    {
        if ($c->status === StudioLabRunCase::STATUS_ERROR) {
            return $c->error_code;
        }
        foreach (is_array($c->calls) ? $c->calls : [] as $l) {
            if (($l['role'] ?? null) === StudioLabPrompts::ROLE_JUDGE) {
                return is_string($l['class'] ?? null) ? $l['class'] : null;
            }
        }

        return null;
    }

    /**
     * Los valores de un caso `error`.
     *
     * @param  list<array<string, mixed>>  $llamadas
     * @return array<string, mixed>
     */
    private static function errorValues(string $clase, array $llamadas, int $coste, int $reserva): array
    {
        return [
            'status' => StudioLabRunCase::STATUS_ERROR,
            'outcome' => null,
            'error_code' => mb_substr($clase, 0, 32),
            'reply' => null,
            'checks' => null,
            'judge' => null,
        ] + self::settlement($llamadas, $coste, $reserva);
    }

    /**
     * Coste, exceso y tokens de un caso.
     *
     * @param  list<array<string, mixed>>  $llamadas
     * @return array<string, mixed>
     */
    private static function settlement(array $llamadas, int $coste, int $reserva): array
    {
        return [
            'calls' => $llamadas,
            'cost_micros' => $coste,
            'overrun_micros' => StudioLabCost::overrunMicros($coste, $reserva),
            'input_tokens' => array_sum(array_map(static fn (array $l): int => (int) ($l['inputTokens'] ?? 0), $llamadas)),
            'output_tokens' => array_sum(array_map(static fn (array $l): int => (int) ($l['outputTokens'] ?? 0), $llamadas)),
        ];
    }

    /**
     * El proveedor de la ejecución: el de OpenAI (o el cliente guionizado que
     * registran las pruebas en su contenedor) o el de prueba sin red. Nunca se
     * cae de uno a otro: el modo lo fijó la ejecución al crearse.
     *
     * @param  array<string, mixed>  $target
     */
    private function client(StudioLabRun $run, array $target): StudioLabModelClient
    {
        if ($run->mode === StudioLabConfig::MODE_OPENAI) {
            return app()->bound(StudioLabModelClient::class) ? app(StudioLabModelClient::class) : new StudioLabOpenAiClient;
        }
        $maximo = $target['configs']['persona']['maxChars'] ?? null;

        return new StudioLabFakeClient(is_int($maximo) ? $maximo : null);
    }

    /**
     * ¿Hay un caso en curso en el sistema desde hace menos de 150 s? Entra por
     * las ejecuciones en curso (forma Q3b del informe de datos).
     */
    private function busy(Carbon $ahora): bool
    {
        return DB::table('ultron_studio_lab_runs as r')
            ->join('ultron_studio_lab_run_cases as c', 'c.run_id', '=', 'r.id')
            ->where('r.status', StudioLabRun::STATUS_RUNNING)
            ->where('c.status', StudioLabRunCase::STATUS_RUNNING)
            ->where('c.started_at', '>', $ahora->copy()->subSeconds(self::STALE_AFTER_SECONDS))
            ->exists();
    }

    /** @return Collection<int, StudioLabRunCase> */
    private function runningCases(int $runId): Collection
    {
        return StudioLabRunCase::query()->where('run_id', $runId)->where('status', StudioLabRunCase::STATUS_RUNNING)->orderBy('position')->get()->toBase();
    }

    /**
     * Cierra o, si no se puede ahora (un caso en curso), vuelve a intentarlo en 30 s.
     *
     * @param  array{kind:string,capMicros:int}|null  $budgetCap
     */
    private function closeOrRetry(StudioLabRun $run, string $status, ?string $stopReason, ?array $budgetCap = null): string
    {
        if ($this->close($run, $status, $stopReason, budgetCap: $budgetCap)) {
            return 'closed';
        }
        if (StudioLabRun::query()->whereKey($run->id)->whereIn('status', StudioLabRun::LIVE_STATUSES)->exists()) {
            $this->requeue((int) $run->id, self::REQUEUE_DELAY_SECONDS);

            return 'retry';
        }

        return 'noop';
    }

    /** El siguiente paso, cuando se confirme lo escrito (`afterCommit`). */
    private function requeue(int $runId, int $delaySeconds): void
    {
        $pendiente = StudioLabStepJob::dispatch($runId)->afterCommit();
        if ($delaySeconds > 0) {
            $pendiente->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * El tope de una ejecución sobre su coste real (Enmienda 4.1): el menor
     * entre el tope por ejecución de la configuración y el máximo que aceptó
     * la persona al lanzarla. Empatados, es el del servidor.
     *
     * @return array{kind:string,capMicros:int}
     */
    private static function runCap(StudioLabRun $run, StudioLabConfig $cfg): array
    {
        $aceptado = (int) $run->accepted_max_micros;

        return $aceptado < $cfg->runBudgetMicros
            ? ['kind' => self::BUDGET_CAP_ACCEPTED, 'capMicros' => $aceptado]
            : ['kind' => self::BUDGET_CAP_RUN, 'capMicros' => $cfg->runBudgetMicros];
    }

    /** @param  array{kind:string,capMicros:int}|null  $budgetCap */
    private static function finishedSummary(string $status, ?string $stopReason, ?array $budgetCap = null): string
    {
        $tope = $budgetCap !== null && isset(self::BUDGET_CAP_SUMMARIES[$budgetCap['kind']]) ? ': '.self::BUDGET_CAP_SUMMARIES[$budgetCap['kind']] : '';

        return match ($status) {
            StudioLabRun::STATUS_COMPLETED => 'La ejecución terminó todos sus casos',
            StudioLabRun::STATUS_CANCELLED => 'La ejecución quedó cancelada',
            default => 'La ejecución se detuvo ('.($stopReason ?? 'sin motivo').')'.$tope,
        };
    }
}
