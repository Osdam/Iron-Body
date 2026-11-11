<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\PriorityArbiter;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\Candidate;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\SubjectFacts;
use App\Services\Outreach\Facts\SubjectKey;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * EL PLANIFICADOR (contrato H9, D9-8, etapas 1–5): detección, evaluación,
 * priorización, autorización y planificación, TODO EN FILAS, con los
 * componentes PUROS del núcleo decidiendo y este servicio solo leyendo y
 * escribiendo.
 *
 *  1 · DETECCIÓN: {@see sweep()} barre cada disparador periódico de las
 *     estrategias vivas con su cadencia del catálogo por la ÚNICA
 *     correspondencia disparador → prefiltro ({@see TriggerPrefilters}, L9-41),
 *     ≤ 500 personas por estrategia y pasada, y cada pasada sigue donde acabó
 *     la anterior hasta recorrer la audiencia entera y vuelve a empezar (I-4,
 *     {@see OutreachRuns::sweepPosition()}); {@see ingest()} lee
 *     `commercial_events` por un cursor monótono (por modo) y lo avanza al
 *     final. Con la PARADA GLOBAL echada no nace ninguna inscripción (la
 *     pasada queda `aborted` con su motivo); con el freno de una estrategia,
 *     esa estrategia se salta.
 *  2 · EVALUACIÓN ({@see evaluateCandidates()}): la clave de ciclo del
 *     catálogo (`occurrence_key` = estrategia × persona × ciclo × modo, L9-1),
 *     la foto de hechos por lotes (solo lo que la estrategia usa) y la puerta
 *     pura `audience → exclusions → success`. Con `false` o `unknown`, solo
 *     recuentos (P9-9); con `true`, la inscripción y su decisión `enroll`.
 *     Una ocurrencia duplicada o dos trabajadores a la vez (#13, #14) los
 *     resuelve la base (UNIQUE): aquí se cuentan.
 *  3 · PRIORIZACIÓN ({@see planDue()}): por persona y día, `PriorityArbiter`
 *     (nunca dos finalidades el mismo día; `service > collections >
 *     commercial`; prioridad; objetivo; uuid). Las que pierden, `defer`
 *     (`lower_priority` / `purpose_conflict_today`) hasta el día siguiente.
 *  4 · AUTORIZACIÓN: `PolicyLadder` por cada canal del paso con la entrada de
 *     {@see LadderInputBuilder} (`phone` siempre, L9-33) y `ChannelSelector`.
 *     `defer` aplaza al `retryAt`; `deny` en N2 ESPERA a que se suelte el
 *     freno (`waitsForRelease`); `deny` con efecto (N6) sale como `converted`,
 *     se deriva (`handed_off`) o se cancela (`context_changed`); `deny`
 *     permanente deja un resultado `blocked` con su motivo y la inscripción
 *     espera (`on.blocked`, por defecto `wait`) o sale.
 *  5 · PLANIFICACIÓN: el primer hueco permitido (ley ∩ horario propio ∩ el de
 *     la estrategia) más un desfase DETERMINISTA por la huella de la persona
 *     (reparte carga sin azar), `expires_at` dentro de la ventana, la RESERVA
 *     ATÓMICA por contadores (canal, estrategia y teléfono; L9-2, L9-30) y el
 *     trabajo `planned` con su decisión `plan`. En SOMBRA el trabajo es
 *     `simulated` y no reserva nada.
 *
 * El reloj entra como argumento; nada aquí llama a la red.
 */
final class OutreachPlanner
{
    public const CURSOR_SOURCE = 'commercial_events';

    /** Cuánto espera una inscripción cuyo despacho frenó N2 o cuya persona ya tiene un trabajo vivo (minutos). */
    public const WAIT_MINUTES = 15;

    /** El desfase determinista por persona dentro del hueco (minutos: 0–14). */
    public const OFFSET_MINUTES = 15;

    /** Lo que vive un trabajo planificado si la ventana no acaba antes (horas). */
    public const JOB_TTL_HOURS = 24;

    /** El evento del CRM que registra una cita como resultado de negocio (D9-10 `appointment_booked`). */
    public const APPOINTMENT_EVENT = 'appointment_created';

    public function __construct(
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly TriggerPrefilters $prefilters = new TriggerPrefilters,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
        private readonly OutreachStore $store = new OutreachStore,
        private readonly BudgetReservations $budgets = new BudgetReservations,
        private readonly OutreachRuns $runs = new OutreachRuns,
        private readonly OutreachStop $stop = new OutreachStop,
        private readonly LadderInputBuilder $inputs = new LadderInputBuilder,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
    ) {}

    // ── Etapa 1 · Detección ─────────────────────────────────────────────────

    /**
     * El barrido de los disparadores periódicos.
     *
     * @return array<string, mixed> `{skipped?, reason?, strategies: {uuid: counts}, runs: list<int>}`
     */
    public function sweep(DateTimeInterface $now, bool $force = false): array
    {
        $ahora = self::utc($now);
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off', 'strategies' => [], 'runs' => []];
        }
        $modo = OutreachConfig::mode();
        $abiertos = $this->stop->openScopes();
        if ($abiertos['global']) {
            $run = $this->runs->open('sweep', $modo, $ahora);
            $this->runs->abort($run, $ahora, ['strategies' => 0], 'outreach_brake_global');

            return ['skipped' => true, 'reason' => 'outreach_brake_global', 'strategies' => [], 'runs' => [$run]];
        }

        $salida = ['skipped' => false, 'strategies' => [], 'runs' => []];
        foreach ($this->bridge->runnableStrategies() as $live) {
            $disparador = $live->triggerEvent();
            if (! TriggerPrefilters::hasPrefilter($disparador)) {
                continue;
            }
            if (in_array($live->strategyUuid, $abiertos['strategies'], true)) {
                $salida['strategies'][$live->strategyUuid] = ['skipped' => 'outreach_brake_strategy'];

                continue;
            }
            if (! $force && $this->sweptRecently($live, $disparador, $ahora)) {
                $salida['strategies'][$live->strategyUuid] = ['skipped' => 'cadence'];

                continue;
            }
            $run = $this->runs->open('sweep', $modo, $ahora, $live->strategyId, $disparador);
            $salida['runs'][] = $run;
            $recuentos = [];
            try {
                // I-4: cada pasada sigue donde acabó la última completada de esta estrategia, disparador y modo.
                $desde = $this->runs->sweepPosition($live->strategyId, $disparador, $modo);
                $pagina = $this->prefilters->pageFrom($live->trigger(), $ahora, $desde, OutreachConfig::sweepBatch()) ?? ['candidates' => [], 'next' => ''];
                if ($desde !== '' && $pagina['candidates'] === [] && $pagina['next'] === '') {
                    // Lo que quedaba de la vuelta ya no está (salió de la ventana): esta misma pasada empieza la siguiente.
                    $desde = '';
                    $pagina = $this->prefilters->pageFrom($live->trigger(), $ahora, $desde, OutreachConfig::sweepBatch()) ?? ['candidates' => [], 'next' => ''];
                }
                $recuentos = $this->evaluateCandidates($live, $pagina['candidates'], $ahora, $run, $modo);
                $recuentos['more'] = $pagina['next'] !== '';
                // La posición se escribe SOLO al completar: si algo falla antes, la siguiente pasada repite esta página.
                $this->runs->complete($run, $ahora, $recuentos + [OutreachRuns::SWEEP_CURSOR_FROM => $desde, OutreachRuns::SWEEP_CURSOR_NEXT => $pagina['next']]);
            } catch (Throwable $e) {
                $this->runs->fail($run, $ahora, $recuentos, OutreachRuns::errorCode($e));

                throw $e;
            }
            $salida['strategies'][$live->strategyUuid] = $recuentos;
        }

        return $salida;
    }

    /**
     * La ingesta de `commercial_events` por cursor (los disparadores
     * `crm_fact`) y los hechos del CRM que son resultados (`appointment_created`
     * → `appointment_booked` de las inscripciones abiertas de esa persona).
     *
     * @return array<string, mixed>
     */
    public function ingest(DateTimeInterface $now): array
    {
        $ahora = self::utc($now);
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off'];
        }
        $modo = OutreachConfig::mode();
        $abiertos = $this->stop->openScopes();
        $fuente = self::CURSOR_SOURCE.':'.$modo;
        if ($abiertos['global']) {
            $run = $this->runs->open('ingest', $modo, $ahora);
            $this->runs->abort($run, $ahora, ['events' => 0], 'outreach_brake_global');

            return ['skipped' => true, 'reason' => 'outreach_brake_global', 'run' => $run];
        }
        $run = $this->runs->open('ingest', $modo, $ahora);
        $desde = $this->store->cursor($fuente);
        $recuentos = ['events' => 0, 'cursor_from' => $desde, 'cursor_to' => $desde, 'outcomes' => 0, 'strategies' => []];
        try {
            $porEvento = [];
            foreach ($this->bridge->runnableStrategies() as $live) {
                $disparador = $live->triggerEvent();
                if (TriggerPrefilters::hasPrefilter($disparador) || ! array_key_exists($disparador, OutreachCatalog::CRM_EVENTS) || in_array($live->strategyUuid, $abiertos['strategies'], true)) {
                    continue;
                }
                $porEvento[$disparador][] = $live;
            }
            $eventos = DB::table('commercial_events')->where('id', '>', $desde)->orderBy('id')->limit(OutreachConfig::sweepBatch())
                ->get(['id', 'event', 'member_id', 'marketing_lead_id', 'occurred_at']);
            $candidatos = [];
            foreach ($eventos as $e) {
                $recuentos['events']++;
                $recuentos['cursor_to'] = max($recuentos['cursor_to'], (int) $e->id);
                $clave = $e->member_id !== null ? SubjectKey::member((int) $e->member_id) : ($e->marketing_lead_id !== null ? SubjectKey::lead((int) $e->marketing_lead_id) : null);
                if ($clave === null) {
                    continue;
                }
                if ((string) $e->event === self::APPOINTMENT_EVENT) {
                    $recuentos['outcomes'] += $this->appointmentBooked($clave, (int) $e->id, $modo, $ahora);
                }
                foreach ($porEvento[(string) $e->event] ?? [] as $live) {
                    $candidatos[$live->versionId][] = new Candidate($clave, (string) $e->event, ['commercial_event_id' => (int) $e->id], (int) $e->id);
                }
            }
            foreach ($porEvento as $vivas) {
                foreach ($vivas as $live) {
                    if (isset($candidatos[$live->versionId])) {
                        $recuentos['strategies'][$live->strategyUuid] = $this->evaluateCandidates($live, $candidatos[$live->versionId], $ahora, $run, $modo);
                    }
                }
            }
            $this->store->advanceCursor($fuente, $recuentos['cursor_to'], $ahora);
            $this->runs->complete($run, $ahora, ['events' => $recuentos['events'], 'cursor_to' => $recuentos['cursor_to'], 'outcomes' => $recuentos['outcomes'], 'strategies' => count($recuentos['strategies'])]);
        } catch (Throwable $e) {
            $this->runs->fail($run, $ahora, ['events' => $recuentos['events']], OutreachRuns::errorCode($e));

            throw $e;
        }

        return ['skipped' => false, 'run' => $run] + $recuentos;
    }

    // ── Etapa 2 · Evaluación ────────────────────────────────────────────────

    /**
     * Evalúa candidatos de UNA estrategia (de un barrido o de la ingesta) e
     * inscribe a quien califica. Devuelve los recuentos de la pasada.
     *
     * @param  list<Candidate>  $candidates
     * @return array<string, int>
     */
    public function evaluateCandidates(LiveStrategy $live, array $candidates, DateTimeImmutable $now, ?int $runId, string $mode): array
    {
        $r = ['candidates' => count($candidates), 'evaluated' => 0, 'enrolled' => 0, 'not_in_audience' => 0, 'insufficient_facts' => 0, 'excluded' => 0,
            'success_reached' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'cooldown' => 0, 'daily_enrollment_cap' => 0, 'no_cycle_key' => 0, 'unknown_subject' => 0];
        $ciclos = [];
        foreach ($candidates as $c) {
            if (isset($ciclos[$c->subjectKey])) {
                continue;
            }
            $ciclo = OutreachCatalog::cycleKey($c->event, $c->cycleParts, $now);
            if ($ciclo === null) {
                $r['no_cycle_key']++;

                continue;
            }
            $ciclos[$c->subjectKey] = $ciclo;
        }
        if ($ciclos === []) {
            return $r;
        }
        $cupo = $live->limit('newEnrollmentsPerDay', 500) - $this->store->enrollmentsCreatedOn($live->strategyId, $mode, RelativeTime::bogotaDate($now));
        $seleccion = CoreProjection::selectionFor($live->uses(), true);
        $ventana = $now->modify('+'.$live->limit('enrollmentDays', 14).' days');
        foreach (array_chunk(array_keys($ciclos), BatchSnapshot::MAX_SUBJECTS) as $claves) {
            $foto = $this->snapshots->build($claves, $now, $seleccion);
            foreach ($claves as $pedida) {
                $s = $foto->subject($pedida);
                if ($s === null || ! $s->resolved() || $s->canonicalKey === null || $s->ref === null) {
                    $r['unknown_subject']++;

                    continue;
                }
                $clave = $s->canonicalKey;
                $ciclo = $ciclos[$pedida];
                if ($this->store->occurrenceEnrolled($live->strategyId, $clave, $ciclo, $mode)) {
                    $r['already_enrolled']++;

                    continue;
                }
                if ($this->store->hasOpenEnrollment($live->strategyId, $clave, $mode)) {
                    $r['open_enrollment']++;

                    continue;
                }
                if ($this->store->cooldownUntil($live->strategyId, $clave, $mode, $live->limit('cooldownDays', 0), $now) !== null) {
                    $r['cooldown']++;

                    continue;
                }
                $r['evaluated']++;
                $hechos = CoreProjection::snapshot($s, $live->strategyUuid);
                $puerta = EnrollmentGate::evaluate($live->audience(), $live->exclusions(), $live->success(), $hechos, $now);
                if (! $puerta['enroll']) {
                    $r[$puerta['reason']] = ($r[$puerta['reason']] ?? 0) + 1;

                    continue;
                }
                if ($cupo <= 0) {
                    $r['daily_enrollment_cap']++;

                    continue;
                }
                $inscrita = DB::transaction(function () use ($live, $mode, $clave, $s, $ciclo, $now, $ventana, $hechos, $puerta, $runId): bool {
                    $id = $this->store->insertEnrollment($live, $mode, $clave, (string) $s->ref, $ciclo, $now, $ventana);
                    if ($id === null) {
                        return false;
                    }
                    $traza = DecisionTrace::build(['kind' => 'enroll'], $hechos, $now, ['audience' => $puerta['audience'], 'exclusions' => $puerta['exclusions'], 'success' => $puerta['success']], ['gate' => ['reason' => $puerta['reason'], 'cycleKey' => $ciclo]]);
                    $this->store->insertDecision($live->seal($mode), $clave, (string) $s->ref, 'enroll', null, [], null, $hechos, $traza, $now, $runId, $id);

                    return true;
                });
                if ($inscrita) {
                    $r['enrolled']++;
                    $cupo--;
                } else {
                    $r['already_enrolled']++;
                }
            }
        }

        return $r;
    }

    // ── Etapas 3–5 · Priorización, autorización y planificación ────────────

    /**
     * Planifica las inscripciones que ya tocan (sin trabajo vivo).
     *
     * @return array<string, mixed>
     */
    public function planDue(DateTimeInterface $now, int $limit = 500): array
    {
        $ahora = self::utc($now);
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off'];
        }
        $modo = OutreachConfig::mode();
        $abiertos = $this->stop->openScopes();
        if ($abiertos['global']) {
            return ['skipped' => true, 'reason' => 'outreach_brake_global'];
        }
        $r = ['skipped' => false, 'due' => 0, 'planned' => 0, 'deferred' => 0, 'denied' => 0, 'waiting' => 0, 'canceled' => 0, 'converted' => 0, 'handed_off' => 0, 'exited' => 0, 'collisions' => 0];
        $vencidas = $this->store->dueEnrollments($modo, $ahora, $limit);
        $r['due'] = count($vencidas);
        if ($vencidas === []) {
            return $r;
        }
        $vivas = [];
        foreach ($this->bridge->liveStrategies() as $l) {
            $vivas[$l->versionId.':'.$l->part] = $l;
        }
        $ilegibles = $this->bridge->unverifiedStrategyIds();
        $porVersion = [];
        foreach ($vencidas as $e) {
            $live = $vivas[$e->strategy_version_id.':'.$e->part] ?? null;
            if ($live === null && in_array((int) $e->strategy_id, $ilegibles, true)) {
                // El par falta porque la cabeza de esa estrategia no verifica (REV9 m-10, L9-72): cadena ilegible, no huérfana; la inscripción espera.
                $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);
                $r['waiting']++;

                continue;
            }
            $huerfana = $live === null ? 'strategy_moved' : $live->orphanReason();
            if ($huerfana !== null) {
                // La versión ya no está en la cadena (reversión, retirada) o no se puede releer: la inscripción se cancela, nada se reinterpreta (D9-8).
                $this->cancelEnrollment($e, $huerfana, 'N5', $ahora);
                $r['canceled']++;

                continue;
            }
            if (! $live->runnable()) {
                // La entrada de la cadena no verifica (D9-11.3, REV9 M-5): N5 niega y el monitor frena; la inscripción espera, no se cancela.
                $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);
                $r['waiting']++;

                continue;
            }
            if ($live->paused() || in_array($live->strategyUuid, $abiertos['strategies'], true)) {
                // Pausada o frenada: espera (los trabajos planificados esperan; aquí todavía no hay trabajo).
                $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);
                $r['waiting']++;

                continue;
            }
            $porVersion[$live->versionId.':'.$live->part][] = $e;
        }
        // Una foto por versión (solo lo que usa esa estrategia), por lotes.
        $fotos = [];
        foreach ($porVersion as $clave => $filas) {
            $live = $vivas[$clave];
            $claves = array_values(array_unique(array_map(static fn (object $e): string => (string) $e->subject_key, $filas)));
            foreach (array_chunk($claves, BatchSnapshot::MAX_SUBJECTS) as $trozo) {
                $foto = $this->snapshots->build($trozo, $ahora, CoreProjection::selectionFor($live->uses(), true));
                foreach ($trozo as $k) {
                    $fotos[$clave][$k] = [$foto->subject($k), $foto->global];
                }
            }
        }
        // Por persona: la prioridad entre lo que califica hoy (etapa 3) y después la escalera de la ganadora (etapas 4 y 5).
        $porPersona = [];
        foreach ($porVersion as $clave => $filas) {
            foreach ($filas as $e) {
                $porPersona[(string) $e->subject_key][] = [$e, $vivas[$clave], $fotos[$clave][(string) $e->subject_key] ?? [null, null]];
            }
        }
        foreach ($porPersona as $sujeto => $grupo) {
            $finalidadDeHoy = $this->todayPurpose($sujeto, $modo, $ahora, $grupo[0][2][0]);
            $candidatas = [];
            foreach ($grupo as $i => [$e, $live]) {
                $candidatas[$live->strategyUuid] = ['uuid' => $live->strategyUuid, 'purpose' => $live->purpose, 'priority' => $live->priority(), 'goal' => $live->goal()];
            }
            $arbitraje = PriorityArbiter::arbitrate(array_values($candidatas), $finalidadDeHoy);
            $aplazadas = array_column($arbitraje['deferred'], 'reason', 'uuid');
            foreach ($grupo as [$e, $live, [$s, $global]]) {
                if ($s === null || ! $s->resolved()) {
                    $this->cancelEnrollment($e, 'unknown_subject', 'N1', $ahora);
                    $r['canceled']++;

                    continue;
                }
                if (isset($aplazadas[$live->strategyUuid])) {
                    $this->deferEnrollment($e, $live, $s, $ahora, 'N6', [$aplazadas[$live->strategyUuid]], PolicyLadder::nextBogotaMidnight($ahora), ['priority' => $arbitraje]);
                    $r['deferred']++;

                    continue;
                }
                $resultado = $this->plan($e, $live, $s, $global, $ahora, $modo, $abiertos, $finalidadDeHoy, $arbitraje);
                $r[$resultado] = ($r[$resultado] ?? 0) + 1;
            }
        }

        return $r;
    }

    /**
     * Etapas 4 y 5 para UNA inscripción ganadora. Devuelve qué pasó:
     * `planned`, `deferred`, `waiting`, `denied`, `converted`, `handed_off`,
     * `exited`, `collisions`.
     *
     * @param  array{global: bool, channels: list<string>, strategies: list<string>}  $abiertos
     * @param  array<string, mixed>  $arbitraje
     */
    private function plan(object $e, LiveStrategy $live, SubjectFacts $s, ?array $global, DateTimeImmutable $ahora, string $modo, array $abiertos, ?string $finalidadDeHoy, array $arbitraje): string
    {
        $indice = (int) $e->step_index;
        $paso = $live->step($indice);
        if ($paso === null) {
            $this->store->closeEnrollment((int) $e->id, 'exited', 'sequence_done', $ahora);

            return 'exited';
        }
        $hechos = CoreProjection::snapshot($s, $live->strategyUuid);
        $puerta = EnrollmentGate::evaluate($live->audience(), $live->exclusions(), $live->success(), $hechos, $ahora);
        $guarda = $live->guard($indice);
        $guardaResultado = $guarda === null ? null : ConditionEvaluator::evaluate($guarda, $hechos, $ahora, 'guard');
        $ventana = OutreachStore::instant($e->window_ends_at);
        $ctx = [
            'stepIndex' => $indice,
            'gate' => EnrollmentGate::context($puerta),
            'stepGuard' => $guardaResultado?->label(),
            'humanRequestedRecently' => LadderInputBuilder::humanRequestedRecently($hechos->events, $ahora),
            'otherPurposeToday' => $finalidadDeHoy !== null && $finalidadDeHoy !== $live->purpose,
            'lowerPriority' => false,
            'touches' => (int) $e->touches,
            'enrollmentWindowEndsAt' => RelativeTime::format($ventana),
            'cooldownUntil' => null,
            'callbackAt' => (string) $e->last_outcome === 'callback_requested' && $e->next_due_at !== null ? RelativeTime::format(OutreachStore::instant($e->next_due_at)) : null,
            'brakes' => $abiertos,
            'extraHistory' => $this->store->recentStartedWithoutStaffOutcome((string) $e->subject_key, $ahora),
            'budgetDay' => RelativeTime::bogotaDate($ahora),
        ];
        $porCanal = [];
        $politica = [];
        foreach ($paso['channels'] as $canal) {
            $entrada = $this->inputs->build($live, $s, $global, $canal, $ahora, $modo, $ctx);
            $salida = PolicyLadder::decide($entrada);
            $porCanal[$canal] = $salida;
            $politica[$canal] = ['input' => $entrada, 'output' => $salida];
        }
        $eleccion = ChannelSelector::choose($paso['channels'], $porCanal, null);
        $condiciones = ['audience' => $puerta['audience'], 'exclusions' => $puerta['exclusions'], 'success' => $puerta['success'], 'guard' => $guardaResultado];
        $partes = ['policy' => $politica, 'channel' => $eleccion, 'priority' => $arbitraje, 'gate' => ['reason' => $puerta['reason']]];
        $primero = $porCanal[$eleccion['order'][0]] ?? $porCanal[$paso['channels'][0]];

        if ($eleccion['verdict'] === ChannelSelector::SELECTED) {
            return $this->planJob($e, $live, $s, $hechos, $ahora, $modo, $paso, $indice, (string) $eleccion['channel'], $porCanal[$eleccion['channel']], $condiciones, $partes, $ventana);
        }
        if ($eleccion['verdict'] === ChannelSelector::DEFER) {
            $reintento = RelativeTime::parseInstant((string) $eleccion['retryAt']);
            $this->decide($e, $live, $hechos, $ahora, 'defer', $primero['level'], $primero['reasons'], $reintento, $condiciones, $partes);
            if ($reintento >= $ventana) {
                $this->windowOver($e, $ahora);

                return 'exited';
            }
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($reintento)], $ahora);

            return 'deferred';
        }
        // `deny`: el primer canal del orden manda el nivel y los motivos; el efecto de N6 se aplica si algún canal lo trae.
        $efecto = null;
        foreach ($eleccion['order'] as $canal) {
            if (($porCanal[$canal]['effect'] ?? null) !== null) {
                $efecto = $porCanal[$canal]['effect'];

                break;
            }
        }
        if ($efecto === 'exit_converted') {
            $this->decide($e, $live, $hechos, $ahora, 'exit', $primero['level'], $primero['reasons'], null, $condiciones, $partes);
            $this->outcomes->record($e, 'converted', 'system', 'success:'.$indice.':'.$ahora->format('Ymd'), $ahora);

            return 'converted';
        }
        if ($efecto === 'handoff') {
            $this->decide($e, $live, $hechos, $ahora, 'handoff', $primero['level'], $primero['reasons'], null, $condiciones, $partes);
            $this->store->closeEnrollment((int) $e->id, 'handed_off', 'human_requested_recently', $ahora);

            return 'handed_off';
        }
        $this->decide($e, $live, $hechos, $ahora, 'deny', $primero['level'], $primero['reasons'], null, $condiciones, $partes);
        if (in_array('global_suppression', $primero['reasons'], true)) {
            // La persona está suprimida (opt-out o número equivocado): no se espera a nada; la inscripción sale.
            $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => 'global_suppression', 'on' => ['blocked' => 'end_exit'], 'exitReason' => 'global_suppression']);

            return 'exited';
        }
        if ($primero['waitsForRelease']) {
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);

            return 'waiting';
        }
        $motivo = (string) ($primero['reasons'][0] ?? 'denied');
        if ($efecto === 'cancel_context_changed') {
            // La persona dejó la audiencia: `blocked` que SALE (no espera), con su motivo en la inscripción.
            $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => 'context_changed', 'on' => ['blocked' => 'end_exit'], 'exitReason' => 'context_changed']);

            return 'exited';
        }
        // Negativa permanente: un `blocked` con su motivo; `on.blocked` decide (por defecto espera y se vuelve a mirar).
        $this->outcomes->record($e, 'blocked', 'system', 'blocked:'.$indice.':'.$ahora->format('YmdHis'), $ahora, ['reasonCode' => $motivo]);

        return 'denied';
    }

    /**
     * Etapa 5: el hueco, el desfase, la reserva atómica y el trabajo.
     *
     * @param  array<string, mixed>  $paso
     * @param  array<string, mixed>  $decision  la salida de la escalera del canal elegido
     * @param  array<string, mixed>  $condiciones
     * @param  array<string, mixed>  $partes
     */
    private function planJob(object $e, LiveStrategy $live, SubjectFacts $s, FactSnapshot $hechos, DateTimeImmutable $ahora, string $modo, array $paso, int $indice, string $canalPaso, array $decision, array $condiciones, array $partes, DateTimeImmutable $ventana): string
    {
        $base = $ahora;
        if ($e->next_due_at !== null && OutreachStore::instant($e->next_due_at) > $base) {
            $base = OutreachStore::instant($e->next_due_at);
        }
        if ($indice === 0 && is_array($paso['delay'] ?? null) && ($paso['delay']['from'] ?? '') === 'enrollment') {
            $conRetraso = OutreachStore::instant($e->enrolled_at)->modify('+'.((int) $paso['delay']['n'] * (OutreachCatalog::UNIT_HOURS[$paso['delay']['unit']] ?? 24)).' hours');
            if ($conRetraso > $base) {
                $base = $conRetraso;
            }
        }
        $hueco = ContactCalendar::nextSlot($base, OutreachConfig::ownHours(), $live->schedule());
        if ($hueco === null || $hueco >= $ventana) {
            $this->decide($e, $live, $hechos, $ahora, 'exit', 'N5', ['enrollment_window_over'], null, $condiciones, $partes);
            $this->windowOver($e, $ahora);

            return 'exited';
        }
        $desfase = hexdec(substr((string) $e->subject_ref, 0, 2)) % self::OFFSET_MINUTES;
        $conDesfase = $hueco->modify("+{$desfase} minutes");
        if ($desfase > 0 && $conDesfase < $ventana && ContactCalendar::allows($conDesfase, OutreachConfig::ownHours(), $live->schedule())) {
            $hueco = $conDesfase;
        }
        $vence = min($ventana, $hueco->modify('+'.self::JOB_TTL_HOURS.' hours'));
        if ($vence <= $hueco) {
            $this->decide($e, $live, $hechos, $ahora, 'exit', 'N5', ['enrollment_window_over'], null, $condiciones, $partes);
            $this->windowOver($e, $ahora);

            return 'exited';
        }

        $sombra = $modo === OutreachConfig::MODE_SHADOW;
        $canalTrabajo = $sombra ? 'simulated' : OutreachSendGate::jobChannelOf($canalPaso);
        $medio = $canalTrabajo === 'assisted' ? OutreachCatalog::mediumOf($canalPaso) : null;
        $plantilla = $this->bridge->templateState($paso['templates'][$canalPaso] ?? ['uuid' => '', 'hash' => ''], $canalPaso, $live->purpose);
        $dia = RelativeTime::bogotaDate($hueco);
        $intento = $this->store->nextAttempt((int) $e->id, $indice);
        if ($intento > OutreachStore::MAX_ATTEMPTS) {
            $this->decide($e, $live, $hechos, $ahora, 'exit', null, ['attempts_exhausted'], null, $condiciones, $partes);
            $this->store->closeEnrollment((int) $e->id, 'exited', 'attempts_exhausted', $ahora);

            return 'exited';
        }
        $parcelas = $sombra ? [] : $this->parcels($live, $canalTrabajo, $plantilla['category'] ?? null, $s->phoneRef);
        $reservado = ['units' => $parcelas === [] ? 0 : 1, 'micros' => 0];
        foreach ($parcelas as $p) {
            $reservado['micros'] = max($reservado['micros'], $p['micros']);
        }

        $huellaPlantilla = $plantilla['id'] !== null ? (string) ($paso['templates'][$canalPaso]['hash'] ?? '') : null;
        try {
            DB::transaction(function () use ($e, $hechos, $ahora, $modo, $indice, $intento, $canalTrabajo, $medio, $plantilla, $huellaPlantilla, $s, $hueco, $vence, $dia, $parcelas, $reservado, $condiciones, $partes): void {
                if ($parcelas !== []) {
                    $agotado = $this->budgets->reserve($dia, $modo, $parcelas, $ahora);
                    if ($agotado !== null) {
                        throw new BudgetExhausted($agotado);
                    }
                }
                $trabajo = $this->store->insertJob($e, $indice, $intento, $canalTrabajo, $medio, $plantilla['id'], $huellaPlantilla, $s->phoneRef, $hueco, $vence, $dia, $reservado, $ahora);
                if ($trabajo === null) {
                    throw new JobCollision;
                }
                $traza = DecisionTrace::build(['kind' => 'plan'], $hechos, $ahora, array_filter($condiciones), $partes + ['followUp' => ['stepIndex' => $indice, 'attempt' => $intento, 'notBefore' => RelativeTime::format($hueco), 'expiresAt' => RelativeTime::format($vence), 'jobChannel' => $canalTrabajo]]);
                $this->store->insertDecision(OutreachStore::sealOf($e), (string) $e->subject_key, (string) $e->subject_ref, 'plan', null, [], null, $hechos, $traza, $ahora, null, (int) $e->id, $trabajo['id'], $plantilla['id'], $huellaPlantilla);
                // Con trabajo planificado la inscripción está activa: una que esperaba (`waiting`) vuelve a `active` (D-1, L9-65).
                $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => null] + ((string) $e->status === 'waiting' ? ['status' => 'active'] : []), $ahora);
            });
        } catch (BudgetExhausted $x) {
            $this->decide($e, $live, $hechos, $ahora, 'defer', 'N4', ['budget_exhausted'], PolicyLadder::nextBogotaMidnight($ahora), $condiciones, $partes + ['followUp' => ['exhausted' => $x->scope]]);
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql(PolicyLadder::nextBogotaMidnight($ahora))], $ahora);

            return 'deferred';
        } catch (JobCollision) {
            // La persona ya tiene un trabajo vivo (otra estrategia o la misma, en otra pasada): se vuelve a mirar después.
            $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($ahora->modify('+'.self::WAIT_MINUTES.' minutes'))], $ahora);

            return 'collisions';
        }

        return 'planned';
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Las parcelas de la reserva de un trabajo en vivo (canal, estrategia y
     * teléfono, L9-2 y L9-30).
     *
     * @return list<array{scope: string, units: int, micros: int, capUnits: int, capMicros: int}>
     */
    private function parcels(LiveStrategy $live, string $canalTrabajo, ?string $categoria, ?string $phoneRef): array
    {
        $parcelas = [];
        if ($canalTrabajo === 'assisted') {
            $parcelas[] = BudgetReservations::parcel(BudgetReservations::SCOPE_CHANNEL.'assisted', 1, 0, OutreachConfig::assistedDailyCap(), 0);
        } elseif ($canalTrabajo === OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE) {
            $coste = OutreachConfig::whatsappPriceMicros((string) $categoria);
            $parcelas[] = BudgetReservations::parcel(BudgetReservations::SCOPE_CHANNEL.$canalTrabajo, 1, $coste, OutreachConfig::whatsappDailyCap(), OutreachConfig::whatsappDailyBudgetMicros());
        } elseif ($canalTrabajo === OutreachCatalog::CHANNEL_VOICE_CALL) {
            // V9 (G-5): la reserva por llamada contra el cupo y el techo de la voz; sin reserva, cupo y techo 0 (la escalera ya negó `budget_zero`; esto es la red).
            $voz = OutreachConfig::voiceBudget();
            $parcelas[] = BudgetReservations::parcel(BudgetReservations::SCOPE_CHANNEL.$canalTrabajo, 1, $voz['reserveMicros'], $voz['dailyCap'], $voz['dailyBudgetMicros']);
        }
        $parcelas[] = BudgetReservations::parcel(BudgetReservations::SCOPE_STRATEGY.$live->strategyUuid, 1, 0, $live->limit('contactsPerDay', 1), 0);
        if ($phoneRef !== null) {
            $parcelas[] = BudgetReservations::parcel(BudgetReservations::SCOPE_PHONE.$phoneRef, 1, 0, 1, 0);
        }

        return $parcelas;
    }

    /** La finalidad que la persona ya tiene hoy (un trabajo que salió o vivo, o un contacto contado): la etapa 3 no admite otra. */
    private function todayPurpose(string $sujeto, string $modo, DateTimeImmutable $ahora, ?SubjectFacts $s): ?string
    {
        foreach ($this->store->startedTodayOf($sujeto, $modo, RelativeTime::bogotaDate($ahora)) as $t) {
            if (in_array($t->purpose, OutreachCatalog::PURPOSES, true)) {
                return (string) $t->purpose;
            }
        }
        if ($s?->contacts !== null) {
            $hoy = RelativeTime::bogotaDate($ahora);
            foreach ($s->contacts as $c) {
                if (($c['source'] ?? null) !== 'simulated' && RelativeTime::bogotaDate(RelativeTime::parseInstant((string) $c['at'])) === $hoy && in_array($c['purpose'] ?? null, OutreachCatalog::PURPOSES, true)) {
                    return (string) $c['purpose'];
                }
            }
        }

        return null;
    }

    /** Un hecho del CRM que es un resultado de negocio (D9-10): `appointment_created` → `appointment_booked` en las inscripciones abiertas de la persona. */
    private function appointmentBooked(string $clave, int $eventId, string $modo, DateTimeImmutable $ahora): int
    {
        $n = 0;
        foreach ($this->store->openEnrollmentsOf($clave, $modo) as $e) {
            if ($this->outcomes->record($e, 'appointment_booked', 'crm_fact', 'ce:'.$eventId, $ahora)['result'] === OutcomeRecorder::RESULT_RECORDED) {
                $n++;
            }
        }

        return $n;
    }

    private function sweptRecently(LiveStrategy $live, string $disparador, DateTimeImmutable $ahora): bool
    {
        $cadencia = TriggerPrefilters::cadenceMinutes($disparador) ?? 60;
        $ultima = DB::table(OutreachTables::RUNS)->where('kind', 'sweep')->where('strategy_id', $live->strategyId)->where('trigger_key', $disparador)
            ->where('status', 'completed')->max('started_at');

        return $ultima !== null && OutreachStore::instant($ultima) > $ahora->modify("-{$cadencia} minutes");
    }

    /** @param  list<string>  $reasons */
    private function deferEnrollment(object $e, LiveStrategy $live, SubjectFacts $s, DateTimeImmutable $ahora, string $level, array $reasons, DateTimeImmutable $retryAt, array $partes): void
    {
        $hechos = CoreProjection::snapshot($s, $live->strategyUuid);
        $this->decide($e, $live, $hechos, $ahora, 'defer', $level, $reasons, $retryAt, [], $partes);
        $this->store->updateOpenEnrollment((int) $e->id, ['next_due_at' => OutreachStore::sql($retryAt)], $ahora);
    }

    /** Una decisión sobre una inscripción (la traza con lo que se evaluó). @param  list<string>  $reasons */
    private function decide(object $e, LiveStrategy $live, FactSnapshot $hechos, DateTimeImmutable $ahora, string $kind, ?string $level, array $reasons, ?DateTimeImmutable $retryAt, array $condiciones, array $partes): void
    {
        $traza = DecisionTrace::build(
            ['kind' => $kind, 'level' => $level, 'reasons' => $reasons, 'retryAt' => $retryAt === null ? null : RelativeTime::format($retryAt)],
            $hechos, $ahora, array_filter($condiciones), $partes,
        );
        $this->store->insertDecision(OutreachStore::sealOf($e), (string) $e->subject_key, (string) $e->subject_ref, $kind, $level, $reasons, $retryAt, $hechos, $traza, $ahora, null, (int) $e->id);
    }

    /**
     * La inscripción se cierra `canceled` con su motivo Y deja la decisión
     * `cancel` que lo explica en el historial de la persona, como el
     * despachador (REV9 m-9): las dos o ninguna. Si otra pasada ya la cerró,
     * no se escribe nada (la decisión es de quien la cerró).
     */
    private function cancelEnrollment(object $e, string $reason, string $level, DateTimeImmutable $ahora): void
    {
        DB::transaction(function () use ($e, $reason, $level, $ahora): void {
            if (! $this->store->closeEnrollment((int) $e->id, 'canceled', $reason, $ahora)) {
                return;
            }
            $vacia = FactSnapshot::fromArray(['facts' => []]);
            $traza = DecisionTrace::build(['kind' => 'cancel', 'level' => $level, 'reasons' => [$reason], 'retryAt' => null], $vacia, $ahora, [], ['gate' => ['recheck' => 'plan']]);
            $this->store->insertDecision(OutreachStore::sealOf($e), (string) $e->subject_key, (string) $e->subject_ref, 'cancel', $level, [$reason], null, $vacia, $traza, $ahora, null, (int) $e->id);
        });
    }

    /** La ventana de la inscripción se acabó sin contacto: `expired` con `no_response` (D9-8 «Durabilidad»). */
    private function windowOver(object $e, DateTimeImmutable $ahora): void
    {
        $this->outcomes->record($e, 'no_response', 'system', 'window:'.$e->id, $ahora);
        $abierta = $this->store->enrollment((int) $e->id);
        if ($abierta !== null && in_array((string) $abierta->status, OutreachTables::ENROLLMENT_OPEN_STATUSES, true)) {
            $this->store->closeEnrollment((int) $e->id, 'expired', 'enrollment_window_over', $ahora, 'no_response');
        }
    }

    private static function utc(DateTimeInterface $now): DateTimeImmutable
    {
        return RelativeTime::utc($now);
    }
}
