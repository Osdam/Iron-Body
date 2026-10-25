<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Las ejecuciones del laboratorio (contrato H4 §A3 y §A7): estado, estimación,
 * creación con RESERVA ATÓMICA del presupuesto, cancelación, listado y
 * detalle. Es una SIMULACIÓN DEL STUDIO, no ULTRON: nada de esto toca el motor.
 *
 * CREAR (`POST lab/runs`), las comprobaciones en el orden del contrato:
 *   1 la bandera (controlador) · 2 los permisos · 3 la configuración (solo
 *   `openai`) · 4 el objetivo · 5 los escenarios · 6 las ejecuciones del día ·
 *   7 la estimación recalculada (tope por ejecución y `estimate_changed`) ·
 *   8 la reserva · 9 las filas y el evento en UNA transacción · 10 el job,
 *   `afterCommit()`.
 * La repetición (`clientRequestId`) va justo después de los permisos: el
 * doble clic recibe su 200 `replayed` aunque el primero haya gastado el último
 * hueco del día.
 *
 * RESERVA ATÓMICA. Cada caso guarda su día de Bogotá y su máximo
 * (`reserved_micros`). Lo comprometido del día es la suma de lo liquidado,
 * más las reservas de lo pendiente o en curso, sin los casos pendientes de
 * ejecuciones en cola que ya caducaron ({@see committed()}). En PostgreSQL la
 * lectura de lo comprometido y la inserción van detrás de
 * `pg_advisory_xact_lock(crc32('ultron_studio_lab:budget'))`, en READ
 * COMMITTED (la lección B-2 de G): dos ejecuciones que juntas superan el tope
 * dan un 201 y un 409, nunca dos 201. SQLite escribe en serie.
 *
 * VISIBILIDAD. Una ejecución sobre un borrador solo la ven su dueño y quien
 * tenga `audit.view`; para cualquier otra persona es un 404 `run_not_found`
 * idéntico al de un uuid que no existe ({@see visibleTo()}).
 *
 * El veredicto no vive aquí: lo emite una persona ({@see StudioLabVerdicts}).
 */
final class StudioLabRuns
{
    /** La llave de lanzar y cancelar ejecuciones (decisión 2 del lead). */
    public const LAB_RUN = 'ultron.lab_run';

    public const FIDELITY = 'Simulación del Studio: un actor de laboratorio interpreta la versión. No es ULTRON: el motor no lee el Studio y sus prompts viven en n8n.';

    public const DISABLED_MESSAGE = 'El laboratorio está apagado en el servidor.';

    public const BASELINE_LABEL = 'Línea base del código';

    /** La nota de un veredicto, como mucho (§A6). */
    public const VERDICT_NOTE_MAX = 1000;

    public const STATUS_LABELS = [
        StudioLabRun::STATUS_QUEUED => 'En cola',
        StudioLabRun::STATUS_RUNNING => 'En curso',
        StudioLabRun::STATUS_COMPLETED => 'Completada',
        StudioLabRun::STATUS_CANCELLED => 'Cancelada',
        StudioLabRun::STATUS_STOPPED => 'Detenida',
    ];

    /** Por qué una ejecución no admite veredicto (§A6), en el orden en que se comprueba. */
    public const NOT_REVIEWABLE = [
        'not_completed' => 'Solo se emite el veredicto sobre una ejecución completada: esta no terminó todos sus casos.',
        'fake_mode' => 'Un ensayo sin IA no admite veredicto: no opinó ningún modelo.',
        'target_not_version' => 'El veredicto se emite sobre versiones: esta ejecución probó un borrador o la línea base.',
    ];

    /** Lo que se lee de una ejecución en una lista: todo menos la instantánea del objetivo. */
    private const SUMMARY_COLUMNS = [
        'id', 'uuid', 'target_kind', 'target_version_id', 'target_draft_admin_id', 'target_label', 'target_content_hash',
        'mode', 'model', 'prompt_version', 'status', 'stop_reason', 'case_count', 'estimate_max_micros', 'cost_micros',
        'ai_recommendation', 'created_by_admin_id', 'created_by_name', 'created_by_role', 'cancel_requested_at',
        'started_at', 'finished_at', 'created_at', 'summary',
    ];

    public function __construct(
        private readonly StudioLabEvents $events,
        private readonly StudioLabRunner $runner,
        private readonly StudioLabScenarios $scenarios,
        private readonly StudioVersions $versions,
        private readonly StudioDrafts $drafts,
    ) {}

    /** La bandera: apagada, todo `lab/*` salvo `GET lab/status` es 409 `lab_disabled`. */
    public static function assertEnabled(): void
    {
        if (! StudioLabConfig::current()->enabled) {
            throw UltronStudioException::labDisabled();
        }
    }

    /** El día del presupuesto: el de Bogotá, nunca el UTC (constante de §A1). */
    public static function budgetDay(?Carbon $at = null): string
    {
        return ($at ?? now())->copy()->setTimezone(StudioLabConfig::BUDGET_TIMEZONE)->toDateString();
    }

    // ── GET lab/status ──────────────────────────────────────────────────────

    /**
     * Siempre 200 a quien tiene `ultron.view`. Apagado: `{enabled:false,
     * message, can}`; encendido, el modo, la configuración (sin la clave), el
     * presupuesto del día, los límites y qué puede hacer quien mira.
     *
     * @return array<string, mixed>
     */
    public function status(StudioAccess $access): array
    {
        $can = [
            'view' => $access->can(StudioAccess::VIEW),
            'manageScenarios' => $access->can(StudioLabScenarios::LAB_MANAGE),
            'run' => $access->can(self::LAB_RUN),
            'verdict' => $access->can(StudioAccess::APPROVE),
        ];
        $cfg = StudioLabConfig::current();
        if (! $cfg->enabled) {
            return ['enabled' => false, 'message' => self::DISABLED_MESSAGE, 'can' => $can];
        }

        $dia = self::budgetDay();
        ['spent' => $gastado, 'reserved' => $reservado] = self::committed($dia);

        return [
            'enabled' => true,
            'mode' => $cfg->mode(),
            'configured' => $cfg->configured(),
            'reasons' => $cfg->reasons(),
            'model' => $cfg->isOpenAi() ? $cfg->model : null,
            'promptVersion' => StudioLabPrompts::PROMPT_VERSION,
            'catalog' => ['version' => StudioLabCatalog::VERSION, 'scenariosImported' => $this->scenarios->catalogImported()],
            'fidelity' => self::FIDELITY,
            'budget' => [
                'timezone' => StudioLabConfig::BUDGET_TIMEZONE,
                'day' => $dia,
                'dailyCapMicros' => $cfg->dailyBudgetMicros,
                'spentMicros' => $gastado,
                'reservedMicros' => $reservado,
                'remainingMicros' => $cfg->dailyBudgetMicros - $gastado - $reservado,
                'runCapMicros' => $cfg->runBudgetMicros,
                'runsToday' => self::runsOn($dia),
                'maxRunsPerDay' => $cfg->maxRunsPerDay,
            ],
            'limits' => [
                'maxCasesPerRun' => $cfg->maxCasesPerRun,
                'maxLiveScenarios' => StudioLabCatalog::MAX_LIVE_SCENARIOS,
                'scenario' => StudioLabCatalog::limits(),
                'verdictNoteMax' => self::VERDICT_NOTE_MAX,
            ],
            'can' => $can,
        ];
    }

    // ── POST lab/estimate ───────────────────────────────────────────────────

    /**
     * La estimación de una ejecución: no escribe nada. `allowed` y `refusal`
     * dicen lo que respondería `POST lab/runs` ahora mismo (con el mismo
     * código), salvo la carrera con otra ejecución.
     *
     * @param  array<string, mixed>  $target  `{kind, uuid?, revision?}`
     * @param  list<string>  $scenarioUuids
     * @return array<string, mixed> EstimateDto
     *
     * @throws ValidationException 422 por posición en `scenarioUuids`
     * @throws UltronStudioException 404/409 del objetivo; 409 `target_contains_personal_data`
     */
    public function estimate(StudioAccess $access, array $target, array $scenarioUuids): array
    {
        $cfg = StudioLabConfig::current();
        $objetivo = $this->resolveTarget($access, $target);
        $escenarios = $this->resolveScenarios($scenarioUuids, $objetivo['snapshot']);

        return $this->estimateDto($access, $cfg, $objetivo, $escenarios, $this->computeEstimate($objetivo['snapshot'], $escenarios, $cfg));
    }

    // ── POST lab/runs ───────────────────────────────────────────────────────

    /**
     * Crea una ejecución con la reserva atómica del presupuesto. 201 la
     * primera vez, 200 `replayed` al repetir el mismo `clientRequestId`.
     *
     * @param  array<string, mixed>  $target
     * @param  list<string>  $scenarioUuids  ya en minúsculas y distintos
     * @return array{run:StudioLabRun,replayed:bool}
     */
    public function create(StudioAccess $access, array $target, array $scenarioUuids, int $acceptedMaxMicros, string $clientRequestId): array
    {
        // 2. Los permisos (la puerta ya los comprobó; esto es la defensa en profundidad).
        $access->require(self::LAB_RUN);

        $peticion = self::requestKey($target, $scenarioUuids, $acceptedMaxMicros);
        if ($previa = $this->replay($access, $clientRequestId, $peticion)) {
            return ['run' => $previa, 'replayed' => true];
        }

        // 3. La configuración: en `fake` siempre está; en `openai`, sin ningún motivo pendiente.
        $cfg = StudioLabConfig::current();
        if (! $cfg->configured()) {
            throw UltronStudioException::labNotConfigured($cfg->reasons(), $cfg->mode());
        }

        // 4 y 5. El objetivo (con el filtro de datos personales) y los escenarios.
        $objetivo = $this->resolveTarget($access, $target);
        $escenarios = $this->resolveScenarios($scenarioUuids, $objetivo['snapshot']);

        // 6. Las ejecuciones del día (se repite dentro del cerrojo).
        $dia = self::budgetDay();
        $hoy = self::runsOn($dia);
        if ($hoy >= $cfg->maxRunsPerDay) {
            throw UltronStudioException::labDailyRunsExceeded($hoy, $cfg->maxRunsPerDay);
        }

        // 7. La estimación, recalculada con lo que se va a congelar.
        $calculo = $this->computeEstimate($objetivo['snapshot'], $escenarios, $cfg);
        if ($calculo['maxMicros'] > $cfg->runBudgetMicros) {
            throw UltronStudioException::labRunBudgetExceeded($calculo['maxMicros'], $cfg->runBudgetMicros);
        }
        if ($calculo['maxMicros'] > $acceptedMaxMicros) {
            throw UltronStudioException::estimateChanged($this->estimateDto($access, $cfg, $objetivo, $escenarios, $calculo));
        }

        try {
            $repetida = false;
            $run = DB::transaction(function () use ($access, $cfg, $objetivo, $escenarios, $calculo, $dia, $acceptedMaxMicros, $clientRequestId, $peticion, &$repetida): StudioLabRun {
                // 8. La reserva: el cerrojo ANTES de leer nada.
                $this->serializeBudget();
                // La misma petición pudo confirmarse mientras esta esperaba el
                // cerrojo: el segundo clic recibe su 200, no un 409 del tope.
                if ($previa = $this->replay($access, $clientRequestId, $peticion)) {
                    $repetida = true;

                    return $previa;
                }

                $hoy = self::runsOn($dia);
                if ($hoy >= $cfg->maxRunsPerDay) {
                    throw UltronStudioException::labDailyRunsExceeded($hoy, $cfg->maxRunsPerDay);
                }
                ['spent' => $gastado, 'reserved' => $reservado] = self::committed($dia);
                $restante = $cfg->dailyBudgetMicros - $gastado - $reservado;
                // Una ejecución que no cuesta nada (`fake`) no se para por presupuesto.
                if ($calculo['maxMicros'] > 0 && $calculo['maxMicros'] > $restante) {
                    throw UltronStudioException::labDailyBudgetExceeded($calculo['maxMicros'], $restante);
                }

                // 9. La ejecución, sus casos con la entrada congelada y el evento.
                $run = $this->insertRun($access, $cfg, $objetivo, $escenarios, $calculo, $dia, $acceptedMaxMicros, $clientRequestId);

                // 10. El primer paso, cuando la transacción se confirme: las
                // conexiones de carril tienen `after_commit = false`.
                StudioLabStepJob::dispatch($run->id)->afterCommit();

                return $run;
            });
        } catch (UniqueConstraintViolationException $e) {
            // La misma llave llegó dos veces a la vez y el índice único paró a la
            // segunda. Se resuelve FUERA de la transacción: en PostgreSQL un error
            // la deja abortada.
            $previa = $this->replay($access, $clientRequestId, $peticion);
            if ($previa === null) {
                throw $e;
            }

            return ['run' => $previa, 'replayed' => true];
        }

        return ['run' => $run, 'replayed' => $repetida];
    }

    // ── POST lab/runs/{uuid}/cancel ─────────────────────────────────────────

    /**
     * Parar el gasto siempre se permite a quien tenga la llave. Sin ningún caso
     * en vuelo (en cola, o en curso entre dos pasos o con su paso perdido:
     * H-P2-1 del tester, Enmienda 5): pasa a `cancelled` al instante, bajo el
     * cerrojo de los reclamos. Con un caso en vuelo: se marca y la llamada
     * termina (y se cobra); el siguiente paso cierra la ejecución. Terminal:
     * 409 `run_not_cancellable`. Pedirlo dos veces no vuelve a escribir la
     * petición; si para entonces ya no queda ningún caso en vuelo, la cierra.
     */
    public function cancel(StudioAccess $access, StudioLabRun $run): StudioLabRun
    {
        $access->require(self::LAB_RUN);
        $yo = $access->snapshot();

        DB::transaction(function () use ($access, $run, $yo): void {
            // El cerrojo de los reclamos PRIMERO: READ COMMITTED antes de leer
            // nada, y un reclamo en curso termina antes de que esto mire el estado.
            $this->runner->serializeClaims();
            $actual = StudioLabRun::query()->whereKey($run->id)->first(['id', 'status', 'cancel_requested_at']);
            if ($actual === null || $actual->isTerminal()) {
                throw UltronStudioException::runNotCancellable((string) $actual?->status);
            }

            $pedida = $actual->cancel_requested_at !== null;
            $campos = $pedida ? [] : ['cancel_requested_at' => now(), 'cancel_requested_by_name' => $yo['name']];
            if (! $pedida) {
                $this->events->record($access->actor, 'run.cancel_requested', StudioLabEvent::SUBJECT_RUN, $run->uuid,
                    'Pidió cancelar la ejecución', ['fromStatus' => $actual->status]);
            }

            // Sin caso en vuelo, se cierra ya: `close()` vuelve a mirarlo bajo
            // este mismo cerrojo y se niega si hay un caso en curso. Una
            // ejecución en curso sin caso en vuelo puede no tener ningún paso
            // en la cola (el suyo falló antes de reclamar): esperar al
            // siguiente paso la dejaría viva para siempre.
            if ($this->runner->close($run, StudioLabRun::STATUS_CANCELLED, null, $access->actor, StudioLabRun::LIVE_STATUSES, $campos)
                || $pedida) {
                return;
            }

            // Con un caso en vuelo: la cierra el siguiente paso.
            $marcada = StudioLabRun::query()->whereKey($run->id)
                ->whereIn('status', StudioLabRun::LIVE_STATUSES)
                ->whereNull('cancel_requested_at')
                ->update($campos);
            if ($marcada !== 1) {
                $ahora = (string) StudioLabRun::query()->whereKey($run->id)->value('status');
                if (in_array($ahora, StudioLabRun::TERMINAL_STATUSES, true)) {
                    throw UltronStudioException::runNotCancellable($ahora);
                }
            }
        });

        return $run->fresh() ?? $run;
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    /**
     * GET lab/runs: el paginador de Laravel con RunSummary, del más reciente al
     * más antiguo, con lo que puede ver quien mira. Sin la instantánea del
     * objetivo y con los recuentos de casos en UNA consulta agrupada.
     *
     * @param  array{version?:?string,target?:?string,scope?:?string}  $filters
     */
    public function page(StudioAccess $access, array $filters, int $perPage): LengthAwarePaginator
    {
        $pagina = self::visibleTo(StudioLabRun::query(), $access)
            ->select(self::SUMMARY_COLUMNS)
            ->with(['verdict', 'targetVersion:id,uuid,created_by_admin_id'])
            ->when($filters['version'] ?? null, fn ($q, string $uuid) => $q->whereIn('target_version_id', StudioVersion::query()->select('id')->where('uuid', $uuid)))
            ->when($filters['target'] ?? null, fn ($q, string $kind) => $q->where('target_kind', $kind))
            ->when(($filters['scope'] ?? 'all') === 'mine', fn ($q) => $q->where('created_by_admin_id', $access->id()))
            ->orderByDesc('id')
            ->paginate($perPage);

        [$conteos, $enVuelo] = self::countsByRun($pagina->getCollection()->pluck('id')->all());

        return $pagina->through(fn (StudioLabRun $r): array => $this->summary($access, $r, $conteos[$r->id] ?? self::emptyCounts(), isset($enVuelo[$r->id])));
    }

    /** La ejecución si quien mira puede verla; si no, el mismo 404 que un uuid inexistente. */
    public function findVisible(StudioAccess $access, string $uuid): StudioLabRun
    {
        return self::visibleTo(StudioLabRun::query(), $access)->where('uuid', strtolower($uuid))->first()
            ?? throw UltronStudioException::runNotFound();
    }

    /**
     * Lo que puede ver quien mira: todo, salvo las ejecuciones sobre el
     * borrador de otra persona (las ve su dueño o quien tenga `audit.view`),
     * con el mismo criterio que los eventos de borrador de G.
     *
     * @param  Builder<StudioLabRun>  $query
     * @return Builder<StudioLabRun>
     */
    public static function visibleTo(Builder $query, StudioAccess $viewer): Builder
    {
        if ($viewer->seesEveryDraftEvent()) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('target_kind', '<>', StudioLabRun::TARGET_DRAFT)
            ->orWhere('target_draft_admin_id', $viewer->id()));
    }

    /**
     * RunDetail (§A7 y Enmienda 2): el resumen más la fidelidad, la
     * instantánea del objetivo, los casos, la huella, lo que hay que reconocer
     * y qué puede hacer quien mira.
     *
     * @return array<string, mixed>
     */
    public function detail(StudioAccess $access, StudioLabRun $run): array
    {
        $run->loadMissing(['verdict', 'targetVersion:id,uuid,created_by_admin_id']);
        $casos = StudioLabRunCase::query()->with('scenario:id,uuid')->where('run_id', $run->id)->orderBy('position')->get();
        $enVuelo = $casos->contains(static fn (StudioLabRunCase $c): bool => $c->status === StudioLabRunCase::STATUS_RUNNING);

        return $this->summary($access, $run, self::countsOf($casos), $enVuelo) + [
            'fidelity' => self::FIDELITY,
            'targetSnapshot' => self::snapshotOut(is_array($run->target_snapshot) ? $run->target_snapshot : []),
            'cases' => $casos->map(fn (StudioLabRunCase $c): array => self::presentCase($c))->all(),
            'resultsHash' => $run->results_hash,
            'requiredAcknowledgements' => self::requiredAcknowledgements($casos->all()),
            'actions' => $this->actions($access, $run),
        ];
    }

    /**
     * RunSummary (§A7 y Enmienda 2, §6). Una ejecución huérfana con el plazo
     * vencido ({@see isOrphanPastDeadline()}) se presenta `stopped` con
     * `deadline` y `expired: true`, sin escribir nada.
     *
     * @param  array{progress:array{done:int,error:int,skipped:int,pending:int},outcomes:array{pass:int,review:int,fail:int}}  $counts
     * @param  bool  $caseInFlight  si tiene algún caso `running` (sin saberlo, true: nada se presenta parado)
     * @return array<string, mixed>
     */
    public function summary(StudioAccess $access, StudioLabRun $run, array $counts, bool $caseInFlight = true): array
    {
        $veredicto = $run->relationLoaded('verdict') ? $run->verdict : $run->verdict()->first();
        $huerfana = self::isOrphanPastDeadline($run, $caseInFlight);
        $estado = $huerfana ? StudioLabRun::STATUS_STOPPED : $run->status;

        return [
            'uuid' => $run->uuid,
            'status' => $estado,
            'statusLabel' => self::STATUS_LABELS[$estado] ?? $estado,
            'stopReason' => $huerfana ? StudioLabRun::STOP_DEADLINE : $run->stop_reason,
            'budgetCap' => $huerfana ? null : self::budgetCapOf($run),
            'expired' => $huerfana || self::isExpired($run),
            'cancelRequested' => $run->cancel_requested_at !== null,
            'target' => [
                'kind' => $run->target_kind,
                'uuid' => $run->target_kind === StudioLabRun::TARGET_VERSION ? self::versionOf($run)?->uuid : null,
                'label' => $run->target_label,
                'shortHash' => substr((string) $run->target_content_hash, 0, 8),
            ],
            'mode' => $run->mode,
            'model' => $run->mode === StudioLabConfig::MODE_OPENAI && $run->model !== '' ? $run->model : null,
            'promptVersion' => $run->prompt_version,
            'caseCount' => (int) $run->case_count,
            'progress' => $counts['progress'],
            'outcomes' => $counts['outcomes'],
            'aiRecommendation' => $run->ai_recommendation,
            'cost' => ['estimateMaxMicros' => (int) $run->estimate_max_micros, 'actualMicros' => (int) $run->cost_micros],
            'createdBy' => ['name' => $run->created_by_name, 'role' => $run->created_by_role],
            'mine' => (int) $run->created_by_admin_id === $access->id(),
            'createdAt' => $run->created_at?->toIso8601String(),
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'verdict' => $veredicto !== null ? [
                'decision' => $veredicto->decision,
                'by' => ['name' => $veredicto->reviewer_name, 'role' => $veredicto->reviewer_role],
                'at' => $veredicto->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    // ── Cálculos compartidos con el runner y el veredicto ───────────────────

    /**
     * Lo comprometido de un día de presupuesto: lo liquidado (`spent`) y las
     * reservas de lo pendiente o en curso (`reserved`), sin los casos
     * pendientes de ejecuciones en cola que ya caducaron. Forma Q1b del
     * informe de datos: entra por el día en las dos tablas. El corte de la
     * caducidad se calcula aquí, en UTC, y viaja como parámetro.
     *
     * @return array{spent:int,reserved:int}
     */
    public static function committed(string $day): array
    {
        $caducidad = now()->subMinutes(StudioLabConfig::QUEUED_EXPIRY_MINUTES);
        $fila = DB::table('ultron_studio_lab_run_cases as c')
            ->join('ultron_studio_lab_runs as r', function ($j): void {
                $j->on('r.id', '=', 'c.run_id')->on('r.budget_day', '=', 'c.budget_day');
            })
            ->where('c.budget_day', $day)
            ->whereNot(fn ($q) => $q
                ->where('c.status', StudioLabRunCase::STATUS_PENDING)
                ->where('r.status', StudioLabRun::STATUS_QUEUED)
                ->where('r.created_at', '<', $caducidad))
            ->selectRaw('COALESCE(SUM(CASE WHEN c.cost_micros IS NOT NULL THEN c.cost_micros ELSE 0 END), 0) AS spent')
            ->selectRaw("COALESCE(SUM(CASE WHEN c.cost_micros IS NULL AND c.status IN ('pending', 'running') THEN c.reserved_micros ELSE 0 END), 0) AS reserved")
            ->first();

        return ['spent' => (int) ($fila->spent ?? 0), 'reserved' => (int) ($fila->reserved ?? 0)];
    }

    /** Cuántas ejecuciones se crearon en un día de presupuesto (también las `fake`). */
    public static function runsOn(string $day): int
    {
        return StudioLabRun::query()->where('budget_day', $day)->count();
    }

    /** ¿Lleva en cola más de lo que admite? Se presenta al leer, sin escribir nada (§A3, paso 4). */
    public static function isExpired(StudioLabRun $run): bool
    {
        if ($run->status === StudioLabRun::STATUS_STOPPED) {
            return $run->stop_reason === StudioLabRun::STOP_EXPIRED;
        }

        return $run->status === StudioLabRun::STATUS_QUEUED
            && $run->created_at !== null
            && $run->created_at->lt(now()->subMinutes(StudioLabConfig::QUEUED_EXPIRY_MINUTES));
    }

    /**
     * Qué tope paró la ejecución con `budget_exhausted` (Enmienda 6.3, I-3 del
     * reviewer): `{kind: daily|run|accepted, capMicros}`, leído del `summary`
     * que escribió el cierre; null en cualquier otro caso.
     *
     * @return array{kind:string,capMicros:int}|null
     */
    public static function budgetCapOf(StudioLabRun $run): ?array
    {
        $tope = is_array($run->summary) ? ($run->summary['budgetCap'] ?? null) : null;
        if (! is_array($tope) || ! is_string($tope['kind'] ?? null) || ! is_int($tope['capMicros'] ?? null)) {
            return null;
        }

        return ['kind' => $tope['kind'], 'capMicros' => $tope['capMicros']];
    }

    /**
     * ¿Es una ejecución HUÉRFANA con el plazo vencido? En curso, sin ningún
     * caso en vuelo y con `started_at` + 30 min ya pasado (H-P2-1 del tester,
     * Enmienda 5): su paso falló antes de reclamar y nadie la va a mover, o el
     * siguiente paso la parará con `deadline` en cuanto llegue. Se presenta
     * `stopped` con `deadline` (y `expired: true`) sin escribir nada, como la
     * caducidad en cola; la materializa el siguiente paso que llegue
     * (`deadline`), el `failed()` del job (`deadline`) o `cancel`, que la
     * cierra `cancelled`. Las acciones se calculan sobre lo guardado.
     */
    public static function isOrphanPastDeadline(StudioLabRun $run, bool $caseInFlight): bool
    {
        return $run->status === StudioLabRun::STATUS_RUNNING
            && ! $caseInFlight
            && $run->started_at !== null
            && $run->started_at->lt(now()->subMinutes(StudioLabConfig::RUN_DEADLINE_MINUTES));
    }

    /**
     * La huella canónica de los resultados (§A4): la ejecución, el objetivo y
     * su huella, el modo, el modelo, la versión de prompts y, de cada caso, su
     * posición, el escenario y su revisión, el estado, el resultado, el error,
     * la respuesta, los hallazgos y el juez. Se calcula SIEMPRE desde las filas
     * leídas de la base, así que recalcularla da lo mismo.
     *
     * @param  iterable<StudioLabRunCase>  $cases
     */
    public static function resultsHash(StudioLabRun $run, iterable $cases): string
    {
        $casos = [];
        foreach ($cases as $c) {
            $casos[] = [
                'position' => (int) $c->position,
                'scenario' => ['code' => $c->scenario_code, 'revision' => (int) $c->scenario_revision],
                'status' => $c->status,
                'outcome' => $c->outcome,
                'errorCode' => $c->error_code,
                'reply' => $c->reply,
                'checks' => is_array($c->checks) ? array_values($c->checks) : [],
                'judge' => is_array($c->judge) ? $c->judge : null,
            ];
        }

        return StudioContentHasher::hash([
            'run' => $run->uuid,
            'target' => [
                'kind' => $run->target_kind,
                'version' => $run->target_version_id !== null ? StudioVersion::query()->whereKey($run->target_version_id)->value('uuid') : null,
                'contentHash' => $run->target_content_hash,
            ],
            'mode' => $run->mode,
            'model' => $run->model,
            'promptVersion' => $run->prompt_version,
            'cases' => $casos,
        ]);
    }

    /**
     * Lo que un `approved` tiene que reconocer: los casos `fail`, `review`,
     * `error` y `skipped`, por código de escenario y en orden de posición.
     *
     * @param  list<StudioLabRunCase>  $cases
     * @return list<string>
     */
    public static function requiredAcknowledgements(array $cases): array
    {
        $out = [];
        foreach ($cases as $c) {
            if ($c->status === StudioLabRunCase::STATUS_ERROR || $c->status === StudioLabRunCase::STATUS_SKIPPED
                || $c->outcome === StudioLabOutcome::FAIL || $c->outcome === StudioLabOutcome::REVIEW) {
                $out[] = $c->scenario_code;
            }
        }

        return $out;
    }

    /** Por qué una ejecución no admite veredicto, o null si lo admite (§A6). */
    public static function notReviewableReason(StudioLabRun $run): ?string
    {
        return match (true) {
            $run->status !== StudioLabRun::STATUS_COMPLETED => 'not_completed',
            $run->mode !== StudioLabConfig::MODE_OPENAI => 'fake_mode',
            $run->target_kind !== StudioLabRun::TARGET_VERSION => 'target_not_version',
            default => null,
        };
    }

    /**
     * El progreso y los resultados de unos casos (`pending` incluye el que
     * está en curso: Enmienda 2, §6).
     *
     * @param  iterable<StudioLabRunCase>  $cases
     * @return array{progress:array{done:int,error:int,skipped:int,pending:int},outcomes:array{pass:int,review:int,fail:int}}
     */
    public static function countsOf(iterable $cases): array
    {
        $out = self::emptyCounts();
        foreach ($cases as $c) {
            self::count($out, $c->status, $c->outcome, 1);
        }

        return $out;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * El objetivo, resuelto y congelado: la versión (íntegra), el borrador
     * PROPIO con esa revisión o la línea base, con su configuración, sus
     * aristas, las reglas que viajan (como mucho 20 S-NNN) y la huella del
     * contenido. Lo que escribió el personal pasa el filtro de datos
     * personales (Enmienda 3): si no, 409 y la entrada no se construye.
     *
     * @param  array<string, mixed>  $target
     * @return array{kind:string,version:?StudioVersion,draftOwner:?int,label:string,contentHash:string,snapshot:array<string,mixed>}
     */
    private function resolveTarget(StudioAccess $access, array $target): array
    {
        $kind = (string) ($target['kind'] ?? '');
        $version = null;
        $dueno = null;
        $revision = null;

        if ($kind === StudioLabRun::TARGET_VERSION) {
            $version = $this->versions->findByUuid((string) $target['uuid']);
            if (! StudioVersions::integrityOk($version)) {
                throw UltronStudioException::integrityMismatch();
            }
            $contenido = $version->content;
            $reglas = is_array($contenido['rules'] ?? null) ? $contenido['rules'] : [];
            $huella = (string) $version->content_hash;
            $etiqueta = mb_substr((string) $version->label, 0, 80);
        } elseif ($kind === StudioLabRun::TARGET_DRAFT) {
            $revision = (int) $target['revision'];
            $draft = $this->drafts->find($access);
            if ($draft === null || (int) $draft->revision !== $revision) {
                throw UltronStudioException::draftConflict(StudioDrafts::present($draft));
            }
            $contenido = $draft->content;
            // Las reglas vivas: las que copiaría la versión creada desde el borrador.
            $reglas = StudioRules::snapshotForVersion();
            $huella = (string) $draft->content_hash;
            $etiqueta = "Borrador · revisión {$revision}";
            $dueno = $access->id();
        } else {
            $contenido = StudioDrafts::baselineContent();
            $reglas = StudioRules::baselineSnapshot();
            $huella = StudioContentHasher::draftHash(StudioCatalog::VERSION, $contenido);
            $etiqueta = self::BASELINE_LABEL;
        }

        $seleccion = StudioLabPrompts::selectRules(array_values(array_filter($reglas, 'is_array')));
        $snapshot = [
            'kind' => $kind,
            'configs' => is_array($contenido['configs'] ?? null) ? $contenido['configs'] : [],
            'edgeIds' => array_values(is_array($contenido['definition']['edgeIds'] ?? null) ? $contenido['definition']['edgeIds'] : []),
            'rules' => $seleccion['included'],
            'excludedRules' => $seleccion['excluded'],
            'contentHash' => $huella,
            'studioCatalogVersion' => StudioCatalog::VERSION,
        ] + ($revision !== null ? ['draftRevision' => $revision] : []);

        $campos = self::personalDataFields($snapshot);
        if ($campos !== []) {
            throw UltronStudioException::targetContainsPersonalData($campos);
        }

        return ['kind' => $kind, 'version' => $version, 'draftOwner' => $dueno, 'label' => $etiqueta, 'contentHash' => $huella, 'snapshot' => $snapshot];
    }

    /**
     * Los escenarios por posición: tienen que existir y estar activos. Un 422
     * con TODAS las posiciones que fallan (`scenarioUuids.N`). Defensa: un
     * escenario cuyo texto ya no pasa el filtro (el filtro cambió) tampoco.
     *
     * @param  list<string>  $uuids
     * @param  array<string, mixed>  $snapshot
     * @return list<StudioLabScenario>
     */
    private function resolveScenarios(array $uuids, array $snapshot): array
    {
        $porUuid = StudioLabScenario::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $errores = [];
        $out = [];
        foreach (array_values($uuids) as $i => $uuid) {
            $s = $porUuid->get($uuid);
            if ($s === null || $s->isArchived()) {
                $errores["scenarioUuids.{$i}"][] = 'El escenario no existe o está archivado.';

                continue;
            }
            foreach (StudioLabPrompts::personalDataPaths($snapshot, StudioLabScenarios::contentOf($s)) as $ruta) {
                if (str_starts_with($ruta, 'escenario.')) {
                    $errores["scenarioUuids.{$i}"][] = StudioLabPersonalData::MESSAGE.'.';

                    break;
                }
            }
            $out[] = $s;
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $out;
    }

    /**
     * La estimación con los prompts REALES: por caso, el máximo de sus dos
     * llamadas (su `reserved_micros`) y lo esperado. Sin precio (laboratorio
     * sin configurar) todo cuenta 0: la negativa ya lo dice.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<StudioLabScenario>  $escenarios
     * @return array{expectedMicros:int,maxMicros:int,perCase:list<int>,prices:array{input:int,output:int}}
     */
    private function computeEstimate(array $snapshot, array $escenarios, StudioLabConfig $cfg): array
    {
        $precios = $cfg->prices() ?? ['input' => 0, 'output' => 0];
        $porCaso = [];
        $total = ['expectedMicros' => 0, 'maxMicros' => 0];
        foreach ($escenarios as $s) {
            $e = StudioLabCost::estimateScenario($snapshot, StudioLabScenarios::contentOf($s), $cfg->actorMaxOutputTokens, $cfg->judgeMaxOutputTokens, $precios);
            $porCaso[] = $e['maxMicros'];
            $total['expectedMicros'] += $e['expectedMicros'];
            $total['maxMicros'] += $e['maxMicros'];
        }

        return $total + ['perCase' => $porCaso, 'prices' => $precios];
    }

    /**
     * EstimateDto (§A3 y Enmienda 2, §12).
     *
     * @param  array<string, mixed>  $objetivo
     * @param  list<StudioLabScenario>  $escenarios
     * @param  array{expectedMicros:int,maxMicros:int}  $calculo
     * @return array<string, mixed>
     */
    private function estimateDto(StudioAccess $access, StudioLabConfig $cfg, array $objetivo, array $escenarios, array $calculo): array
    {
        $dia = self::budgetDay();
        ['spent' => $gastado, 'reserved' => $reservado] = self::committed($dia);
        $comprometido = $gastado + $reservado;
        $restante = $cfg->dailyBudgetMicros - $comprometido;
        $hoy = self::runsOn($dia);

        // Lo que respondería POST lab/runs, en su mismo orden (2, 3, 6, 7, 8).
        $negativa = match (true) {
            ! $access->can(self::LAB_RUN) => UltronStudioException::forbidden(self::LAB_RUN),
            ! $cfg->configured() => UltronStudioException::labNotConfigured($cfg->reasons(), $cfg->mode()),
            $hoy >= $cfg->maxRunsPerDay => UltronStudioException::labDailyRunsExceeded($hoy, $cfg->maxRunsPerDay),
            $calculo['maxMicros'] > $cfg->runBudgetMicros => UltronStudioException::labRunBudgetExceeded($calculo['maxMicros'], $cfg->runBudgetMicros),
            $calculo['maxMicros'] > 0 && $calculo['maxMicros'] > $restante => UltronStudioException::labDailyBudgetExceeded($calculo['maxMicros'], $restante),
            default => null,
        };

        return [
            'mode' => $cfg->mode(),
            'model' => $cfg->isOpenAi() ? $cfg->model : null,
            'promptVersion' => StudioLabPrompts::PROMPT_VERSION,
            'cases' => count($escenarios),
            'estimate' => ['expectedMicros' => $calculo['expectedMicros'], 'maxMicros' => $calculo['maxMicros']],
            'budget' => [
                'day' => $dia,
                'dailyCapMicros' => $cfg->dailyBudgetMicros,
                'committedMicros' => $comprometido,
                'remainingMicros' => $restante,
                'runCapMicros' => $cfg->runBudgetMicros,
                'runsToday' => $hoy,
                'maxRunsPerDay' => $cfg->maxRunsPerDay,
            ],
            'allowed' => $negativa === null,
            'refusal' => $negativa !== null ? ['code' => $negativa->errorCode, 'message' => $negativa->getMessage()] : null,
            'excludedRules' => array_values($objetivo['snapshot']['excludedRules'] ?? []),
        ];
    }

    /**
     * Las filas de la ejecución y de sus casos, con la entrada congelada, y el
     * evento `run.created`. Dentro de la transacción de la reserva.
     *
     * @param  array<string, mixed>  $objetivo
     * @param  list<StudioLabScenario>  $escenarios
     * @param  array{expectedMicros:int,maxMicros:int,perCase:list<int>,prices:array{input:int,output:int}}  $calculo
     */
    private function insertRun(StudioAccess $access, StudioLabConfig $cfg, array $objetivo, array $escenarios, array $calculo, string $dia, int $acceptedMaxMicros, string $clientRequestId): StudioLabRun
    {
        $yo = $access->snapshot();
        $modo = (string) $cfg->mode();
        $modelo = $cfg->isOpenAi() ? (string) $cfg->model : '';

        $run = StudioLabRun::query()->forceCreate([
            'client_request_id' => $clientRequestId,
            'target_kind' => $objetivo['kind'],
            'target_version_id' => $objetivo['version']?->id,
            'target_draft_admin_id' => $objetivo['draftOwner'],
            'target_label' => $objetivo['label'],
            'target_content_hash' => $objetivo['contentHash'],
            'target_snapshot' => $objetivo['snapshot'],
            'catalog_version' => StudioLabCatalog::VERSION,
            'mode' => $modo,
            'model' => $modelo,
            'prompt_version' => StudioLabPrompts::PROMPT_VERSION,
            // Con estos máximos se calculó la reserva, y con ellos se cobra un timeout.
            'prices' => $calculo['prices'] + [
                'actorMaxOutputTokens' => $cfg->actorMaxOutputTokens,
                'judgeMaxOutputTokens' => $cfg->judgeMaxOutputTokens,
            ],
            'status' => StudioLabRun::STATUS_QUEUED,
            'case_count' => count($escenarios),
            'estimate_expected_micros' => $calculo['expectedMicros'],
            'estimate_max_micros' => $calculo['maxMicros'],
            'accepted_max_micros' => $acceptedMaxMicros,
            'budget_day' => $dia,
            'created_by_admin_id' => $yo['id'],
            'created_by_name' => $yo['name'],
            'created_by_role' => $yo['role'],
        ]);

        foreach ($escenarios as $i => $s) {
            StudioLabRunCase::query()->forceCreate([
                'run_id' => $run->id,
                'position' => $i + 1,
                'scenario_id' => $s->id,
                'scenario_code' => $s->code,
                'scenario_revision' => (int) $s->revision,
                'scenario_snapshot' => StudioLabScenarios::contentOf($s),
                'status' => StudioLabRunCase::STATUS_PENDING,
                'budget_day' => $dia,
                'reserved_micros' => $calculo['perCase'][$i],
                'overrun_micros' => 0,
            ]);
        }

        $this->events->record($access->actor, 'run.created', StudioLabEvent::SUBJECT_RUN, $run->uuid,
            "Lanzó una ejecución del laboratorio sobre «{$objetivo['label']}» (".count($escenarios).' casos, '.($modo === StudioLabConfig::MODE_OPENAI ? 'OpenAI' : 'ensayo sin IA').')', [
                'toStatus' => StudioLabRun::STATUS_QUEUED,
                'diff' => [
                    'target' => ['kind' => $objetivo['kind'], 'uuid' => $objetivo['version']?->uuid, 'label' => $objetivo['label']],
                    'mode' => $modo,
                    'model' => $modelo !== '' ? $modelo : null,
                    'promptVersion' => StudioLabPrompts::PROMPT_VERSION,
                    'scenarios' => array_map(static fn (StudioLabScenario $s): string => $s->code, $escenarios),
                    'estimateMaxMicros' => $calculo['maxMicros'],
                    'acceptedMaxMicros' => $acceptedMaxMicros,
                ],
            ]);

        return $run;
    }

    /**
     * EL cerrojo del presupuesto (§A3). En PostgreSQL, READ COMMITTED como
     * primera sentencia (en REPEATABLE READ la instantánea la tomaría la propia
     * sentencia del cerrojo, ANTES de esperar, y quien esperó leería lo
     * comprometido de antes de que la otra confirmara: la lección B-2 de G) y
     * después el cerrojo consultivo, antes de leer lo comprometido.
     */
    private function serializeBudget(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ultron_studio_lab:budget')]);
        }
    }

    /**
     * Lo que identifica una petición de creación, para reconocer la misma
     * repetida.
     *
     * @param  array<string, mixed>  $target
     * @param  list<string>  $uuids
     * @return array<string, mixed>
     */
    private static function requestKey(array $target, array $uuids, int $acceptedMax): array
    {
        $kind = (string) ($target['kind'] ?? '');

        return [
            'kind' => $kind,
            'uuid' => $kind === StudioLabRun::TARGET_VERSION ? strtolower((string) $target['uuid']) : null,
            'revision' => $kind === StudioLabRun::TARGET_DRAFT ? (int) $target['revision'] : null,
            'scenarios' => array_values($uuids),
            'acceptedMax' => $acceptedMax,
        ];
    }

    /**
     * La misma petición repetida devuelve la ejecución de la primera. La misma
     * llave para otra cosa (otra persona, otro objetivo, otros escenarios u
     * otro máximo aceptado) es 409 `client_request_reused`.
     *
     * @param  array<string, mixed>  $peticion
     */
    private function replay(StudioAccess $access, string $clientRequestId, array $peticion): ?StudioLabRun
    {
        $previa = StudioLabRun::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }

        $escenarios = DB::table('ultron_studio_lab_run_cases as c')
            ->join('ultron_studio_lab_scenarios as s', 's.id', '=', 'c.scenario_id')
            ->where('c.run_id', $previa->id)
            ->orderBy('c.position')
            ->pluck('s.uuid')
            ->map(static fn ($u): string => strtolower((string) $u))
            ->all();
        $snapshot = is_array($previa->target_snapshot) ? $previa->target_snapshot : [];
        $original = [
            'kind' => $previa->target_kind,
            'uuid' => $previa->target_kind === StudioLabRun::TARGET_VERSION ? strtolower((string) self::versionOf($previa)?->uuid) : null,
            'revision' => $previa->target_kind === StudioLabRun::TARGET_DRAFT ? (int) ($snapshot['draftRevision'] ?? 0) : null,
            'scenarios' => $escenarios,
            'acceptedMax' => (int) $previa->accepted_max_micros,
        ];

        if ((int) $previa->created_by_admin_id !== $access->id() || $original !== $peticion) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previa;
    }

    /** La versión objetivo (cargada o no). */
    private static function versionOf(StudioLabRun $run): ?StudioVersion
    {
        if ($run->target_version_id === null) {
            return null;
        }

        return $run->relationLoaded('targetVersion') ? $run->targetVersion : $run->targetVersion()->first(['id', 'uuid', 'created_by_admin_id']);
    }

    /**
     * Qué puede hacer quien mira con esta ejecución, y si no, por qué. El
     * servidor lo vuelve a comprobar al actuar.
     *
     * @return array{cancel:array{allowed:bool,code?:string,message?:string},verdict:array{allowed:bool,code?:string,message?:string}}
     */
    private function actions(StudioAccess $access, StudioLabRun $run): array
    {
        $no = static fn (string $code, string $message): array => ['allowed' => false, 'code' => $code, 'message' => $message];

        $cancelar = match (true) {
            ! $access->can(self::LAB_RUN) => $no('forbidden', 'Hace falta el permiso «Lanzar ejecuciones del laboratorio».'),
            $run->isTerminal() => $no('run_not_cancellable', 'La ejecución ya terminó: no hay nada que cancelar.'),
            $run->cancel_requested_at !== null => $no('cancel_requested', 'Ya se pidió cancelarla: la cierra el siguiente paso.'),
            default => ['allowed' => true],
        };

        $motivo = self::notReviewableReason($run);
        $version = self::versionOf($run);
        $veredicto = match (true) {
            ! $access->can(StudioAccess::APPROVE) => $no('forbidden', 'Hace falta el permiso «Aprobar o rechazar versiones de otra persona».'),
            $motivo !== null => $no('run_not_reviewable', self::NOT_REVIEWABLE[$motivo]),
            $version !== null && (int) $version->created_by_admin_id === $access->id() => $no('four_eyes_required', 'Nadie emite el veredicto sobre su propia versión: tiene que hacerlo otra persona.'),
            ($run->relationLoaded('verdict') ? $run->verdict : $run->verdict()->first()) !== null => $no('verdict_exists', 'Esta ejecución ya tiene veredicto.'),
            default => ['allowed' => true],
        };

        return ['cancel' => $cancelar, 'verdict' => $veredicto];
    }

    /**
     * Un caso tal como lo pinta el CRM (Enmienda 2, §7).
     *
     * @return array<string, mixed>
     */
    private static function presentCase(StudioLabRunCase $c): array
    {
        $esc = is_array($c->scenario_snapshot) ? $c->scenario_snapshot : [];
        $llamadas = is_array($c->calls) ? $c->calls : [];
        $juez = is_array($c->judge) ? $c->judge : null;
        $falloDelJuez = null;
        if ($c->status === StudioLabRunCase::STATUS_DONE && $juez === null) {
            foreach ($llamadas as $l) {
                if (($l['role'] ?? null) === StudioLabPrompts::ROLE_JUDGE && is_string($l['class'] ?? null)) {
                    $falloDelJuez = ['class' => $l['class']];
                }
            }
        }
        $liquidado = $c->cost_micros !== null;

        return [
            'position' => (int) $c->position,
            'scenario' => [
                'uuid' => $c->scenario?->uuid,
                'code' => $c->scenario_code,
                'title' => (string) ($esc['title'] ?? ''),
                'category' => (string) ($esc['category'] ?? ''),
                'revision' => (int) $c->scenario_revision,
            ],
            'input' => [
                'turns' => array_values($esc['turns'] ?? []),
                'facts' => [
                    'paymentState' => (string) ($esc['facts']['paymentState'] ?? StudioLabCatalog::DEFAULT_FACTS['paymentState']),
                    'handoffRequested' => ($esc['facts']['handoffRequested'] ?? false) === true,
                ],
                'criteria' => array_values($esc['criteria'] ?? []),
            ],
            'status' => $c->status,
            'outcome' => $c->outcome,
            'errorCode' => $c->error_code,
            'errorMessage' => $c->error_code !== null ? StudioLabRunner::errorMessage($c->error_code) : null,
            'reply' => $c->reply,
            'checks' => array_map(static fn (array $h): array => [
                'id' => (string) $h['id'],
                'source' => (string) $h['source'],
                'level' => (string) $h['level'],
                'title' => (string) $h['title'],
                'detail' => (string) ($h['detail'] ?? ''),
                'quote' => isset($h['quote']) ? (string) $h['quote'] : null,
            ], array_values(array_filter(is_array($c->checks) ? $c->checks : [], 'is_array'))),
            'judge' => $juez,
            'judgeError' => $falloDelJuez,
            'usage' => [
                'inputTokens' => $liquidado ? (int) $c->input_tokens : null,
                'outputTokens' => $liquidado ? (int) $c->output_tokens : null,
                'costMicros' => $c->cost_micros,
                'overrunMicros' => (int) $c->overrun_micros,
            ],
            'startedAt' => $c->started_at?->toIso8601String(),
            'finishedAt' => $c->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Lo que se enseña de la instantánea del objetivo (Enmienda 2, §8):
     * `{configs, rules, contentHash}`. Las capas vacías van como objetos.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function snapshotOut(array $snapshot): array
    {
        $configs = [];
        foreach (is_array($snapshot['configs'] ?? null) ? $snapshot['configs'] : [] as $capa => $valores) {
            $configs[$capa] = is_array($valores) && $valores !== [] ? $valores : new stdClass;
        }

        return [
            'configs' => $configs !== [] ? $configs : new stdClass,
            'rules' => array_map(static fn (array $r): array => [
                'code' => (string) $r['code'],
                'revision' => isset($r['revision']) ? (int) $r['revision'] : null,
                'description' => (string) ($r['description'] ?? ''),
                'conditions' => is_array($r['conditions'] ?? null) ? $r['conditions'] : null,
            ], array_values(array_filter(is_array($snapshot['rules'] ?? null) ? $snapshot['rules'] : [], 'is_array'))),
            'contentHash' => $snapshot['contentHash'] ?? null,
        ];
    }

    /**
     * Las rutas del objetivo que no pasan el filtro, dichas en el lenguaje del
     * Studio: `configs.<capa>.<clave>` o `rules.<código>`.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private static function personalDataFields(array $snapshot): array
    {
        $vacio = ['title' => '', 'description' => '', 'turns' => [], 'facts' => StudioLabCatalog::DEFAULT_FACTS, 'criteria' => []];
        $rutas = StudioLabPrompts::personalDataPaths($snapshot, $vacio);
        if ($rutas === []) {
            return [];
        }

        // Los parámetros que viajan, en el orden de `configurationLines()`.
        $configs = is_array($snapshot['configs'] ?? null) ? $snapshot['configs'] : [];
        $parametros = [];
        foreach (StudioLabPrompts::ACTOR_PARAMS as [$capa, $clave]) {
            if (is_array($configs[$capa] ?? null) && array_key_exists($clave, $configs[$capa])) {
                $parametros[] = "configs.{$capa}.{$clave}";
            }
        }
        $reglas = array_values(array_filter(is_array($snapshot['rules'] ?? null) ? $snapshot['rules'] : [], static fn (mixed $r): bool => is_array($r) && is_string($r['code'] ?? null)));

        $out = [];
        foreach ($rutas as $ruta) {
            if (preg_match('/^configuracion\.(\d+)/', $ruta, $m) === 1) {
                $out[] = $parametros[(int) $m[1]] ?? $ruta;
            } elseif (preg_match('/^reglas\.(\d+)/', $ruta, $m) === 1) {
                $out[] = isset($reglas[(int) $m[1]]) ? 'rules.'.$reglas[(int) $m[1]]['code'] : $ruta;
            } else {
                $out[] = $ruta;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Los recuentos de los casos de varias ejecuciones, en UNA consulta, y qué
     * ejecuciones tienen algún caso en vuelo (`running`; en el recuento cuenta
     * como `pending`).
     *
     * @param  list<int>  $ids
     * @return array{0:array<int, array{progress:array{done:int,error:int,skipped:int,pending:int},outcomes:array{pass:int,review:int,fail:int}}>,1:array<int,true>}
     */
    private static function countsByRun(array $ids): array
    {
        if ($ids === []) {
            return [[], []];
        }

        $out = [];
        $enVuelo = [];
        $filas = DB::table('ultron_studio_lab_run_cases')
            ->whereIn('run_id', $ids)
            ->groupBy('run_id', 'status', 'outcome')
            ->select(['run_id', 'status', 'outcome', DB::raw('COUNT(*) AS n')])
            ->get();
        foreach ($filas as $f) {
            $id = (int) $f->run_id;
            $out[$id] ??= self::emptyCounts();
            self::count($out[$id], (string) $f->status, $f->outcome !== null ? (string) $f->outcome : null, (int) $f->n);
            if ((string) $f->status === StudioLabRunCase::STATUS_RUNNING) {
                $enVuelo[$id] = true;
            }
        }

        return [$out, $enVuelo];
    }

    /** @return array{progress:array{done:int,error:int,skipped:int,pending:int},outcomes:array{pass:int,review:int,fail:int}} */
    private static function emptyCounts(): array
    {
        return [
            'progress' => ['done' => 0, 'error' => 0, 'skipped' => 0, 'pending' => 0],
            'outcomes' => ['pass' => 0, 'review' => 0, 'fail' => 0],
        ];
    }

    /** @param  array{progress:array<string,int>,outcomes:array<string,int>}  $counts */
    private static function count(array &$counts, string $status, ?string $outcome, int $n): void
    {
        $clave = match ($status) {
            StudioLabRunCase::STATUS_DONE => 'done',
            StudioLabRunCase::STATUS_ERROR => 'error',
            StudioLabRunCase::STATUS_SKIPPED => 'skipped',
            default => 'pending',
        };
        $counts['progress'][$clave] += $n;
        if ($status === StudioLabRunCase::STATUS_DONE && $outcome !== null && isset($counts['outcomes'][$outcome])) {
            $counts['outcomes'][$outcome] += $n;
        }
    }
}
