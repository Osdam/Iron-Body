<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\OperationalBrakes;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Facts\SubjectTimeline;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LAS LECTURAS del panel (contrato H9, D9-14 «Panel», §7 E1–E7 y E10; D9-13
 * BOLA): el estado, las métricas, las listas paginadas (≤ 50) y los detalles,
 * todo por `uuid` y SIN DATOS PERSONALES: la persona es su `subjectRef`
 * (seudónimo, no enumerable); nunca `subject_key`, teléfonos, nombres ni
 * importes. Solo lectura.
 */
final class OutreachReadModels
{
    public const PER_PAGE_MAX = 50;

    public function __construct(
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly OutreachStop $stop = new OutreachStop,
        private readonly OperationalBrakes $foreign = new OperationalBrakes,
        private readonly SubjectTimeline $timeline = new SubjectTimeline,
    ) {}

    // ── E1 · status ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function status(OutreachAccess $access, DateTimeImmutable $now): array
    {
        $vivas = $this->bridge->liveStrategies();
        $ajenos = $this->foreign->read();
        $ultimas = [];
        foreach (OutreachRuns::KINDS as $kind) {
            $r = DB::table(OutreachTables::RUNS)->where('kind', $kind)->orderByDesc('id')->first(['uuid', 'status', 'started_at', 'finished_at', 'error_code', 'counts']);
            $ultimas[$kind] = $r === null ? null : [
                'uuid' => (string) $r->uuid, 'status' => (string) $r->status, 'startedAt' => self::iso($r->started_at), 'finishedAt' => self::iso($r->finished_at),
                // I-4: la posición del barrido lleva ids internos; se guarda, pero no sale.
                'errorCode' => $r->error_code, 'counts' => OutreachRuns::publicCounts(is_string($r->counts) ? json_decode($r->counts, true) : $r->counts),
            ];
        }
        $cursores = DB::table(OutreachTables::CURSORS)->get(['source', 'last_id', 'updated_at'])->map(static fn (object $c): array => ['source' => (string) $c->source, 'lastId' => (int) $c->last_id, 'updatedAt' => self::iso($c->updated_at)])->all();
        $modo = OutreachConfig::active() ? OutreachConfig::mode() : null;

        return [
            'config' => OutreachConfig::status(),
            'brakes' => [
                'own' => $this->stop->openScopes(),
                'open' => array_values(array_filter($this->stop->list(50), static fn (array $b): bool => ! $b['released'])),
                'foreign' => $ajenos['brakes'],
                'unreadable' => $ajenos['unreadable'],
            ],
            'chain' => ['head' => $this->bridge->headVerified(), 'strategies' => array_map(static fn (LiveStrategy $l): array => $l->toArray(), $vivas)],
            'sources' => [
                'commercialEvents' => config('commercial.events_enabled') === true,
                'cursors' => $cursores,
                'latestRuns' => $ultimas,
            ],
            'live' => $modo === null ? null : [
                'jobs' => (int) DB::table(OutreachTables::JOBS)->where('mode', $modo)->whereIn('status', OutreachTables::JOB_LIVE_STATUSES)->count(),
                'openEnrollments' => (int) DB::table(OutreachTables::ENROLLMENTS)->where('mode', $modo)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)->count(),
                'assistedQueue' => (int) DB::table(OutreachTables::JOBS.' as j')->where('j.mode', OutreachConfig::MODE_LIVE)->where('j.channel', 'assisted')->where('j.status', 'started')
                    ->whereNotExists(fn ($w) => $w->from(OutreachTables::OUTCOMES.' as o')->whereColumn('o.job_id', 'j.id')->where('o.source', 'staff'))->count(),
            ],
            // Aditivo (cierre del H9): solo un recuento, para quien ya puede leer este estado.
            'personBindings' => $this->bridge->verifiedBindingsCount(),
            'can' => $access->capabilities(),
            'at' => RelativeTime::format($now),
        ];
    }

    // ── E2 · metrics ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function metrics(DateTimeImmutable $now, ?string $mode = null): array
    {
        $modo = $mode ?? (OutreachConfig::active() ? OutreachConfig::mode() : OutreachConfig::MODE_SHADOW);
        $dia = RelativeTime::bogotaDate($now);
        $desde = OutreachStore::sql($now->modify('-24 hours'));
        $estrategias = DB::table(OutreachTables::STRATEGIES)->pluck('uuid', 'id')->all();
        $porEstrategia = [];
        $fila = static function (int $id) use (&$porEstrategia, $estrategias): void {
            $porEstrategia[$id] ??= ['strategy' => (string) ($estrategias[$id] ?? ''), 'enrollments' => [], 'jobs' => [], 'outcomes' => [], 'denials' => [], 'budgetToday' => ['units' => 0, 'micros' => 0]];
        };
        foreach (DB::table(OutreachTables::ENROLLMENTS)->where('mode', $modo)->groupBy('strategy_id', 'status')->selectRaw('strategy_id, status, count(*) as n')->get() as $f) {
            $fila((int) $f->strategy_id);
            $porEstrategia[(int) $f->strategy_id]['enrollments'][(string) $f->status] = (int) $f->n;
        }
        foreach (DB::table(OutreachTables::JOBS)->where('mode', $modo)->groupBy('strategy_id', 'status')->selectRaw('strategy_id, status, count(*) as n')->get() as $f) {
            $fila((int) $f->strategy_id);
            $porEstrategia[(int) $f->strategy_id]['jobs'][(string) $f->status] = (int) $f->n;
        }
        foreach (DB::table(OutreachTables::OUTCOMES.' as o')->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'o.enrollment_id')->where('e.mode', $modo)
            ->groupBy('o.strategy_id', 'o.outcome')->selectRaw('o.strategy_id, o.outcome, count(*) as n')->get() as $f) {
            $fila((int) $f->strategy_id);
            $porEstrategia[(int) $f->strategy_id]['outcomes'][(string) $f->outcome] = (int) $f->n;
        }
        foreach (DB::table(OutreachTables::DECISIONS)->where('mode', $modo)->whereIn('kind', ['deny', 'defer', 'cancel'])->where('evaluated_at', '>=', $desde)
            ->groupBy('strategy_id', 'kind', 'level')->selectRaw('strategy_id, kind, level, count(*) as n')->get() as $f) {
            $fila((int) $f->strategy_id);
            $porEstrategia[(int) $f->strategy_id]['denials'][(string) $f->kind][(string) ($f->level ?? 'none')] = (int) $f->n;
        }
        foreach (DB::table(OutreachTables::BUDGETS)->where('budget_day', $dia)->where('mode', $modo)->where('scope', 'like', 'strategy:%')->get(['scope', 'reserved_units', 'reserved_micros']) as $b) {
            $uuid = substr((string) $b->scope, 9);
            $id = array_search($uuid, $estrategias, true);
            if ($id !== false) {
                $fila((int) $id);
                $porEstrategia[(int) $id]['budgetToday'] = ['units' => (int) $b->reserved_units, 'micros' => (int) $b->reserved_micros];
            }
        }
        $canales = [];
        foreach (DB::table(OutreachTables::BUDGETS)->where('budget_day', $dia)->where('mode', $modo)->where('scope', 'like', 'channel:%')->get(['scope', 'cap_units', 'cap_micros', 'reserved_units', 'reserved_micros']) as $b) {
            $canales[substr((string) $b->scope, 8)] = ['capUnits' => (int) $b->cap_units, 'capMicros' => (int) $b->cap_micros, 'reservedUnits' => (int) $b->reserved_units, 'reservedMicros' => (int) $b->reserved_micros];
        }
        $motivos = DB::table(OutreachTables::DECISIONS)->where('mode', $modo)->whereIn('kind', ['deny', 'defer', 'cancel'])->where('evaluated_at', '>=', $desde)->get(['reasons']);
        $porMotivo = [];
        foreach ($motivos as $m) {
            $lista = json_decode((string) $m->reasons, true);
            if (is_array($lista) && is_string($lista[0] ?? null)) {
                $porMotivo[$lista[0]] = ($porMotivo[$lista[0]] ?? 0) + 1;
            }
        }
        arsort($porMotivo);

        return [
            'mode' => $modo,
            'day' => $dia,
            'strategies' => array_values($porEstrategia),
            'channelsToday' => $canales,
            'topReasons24h' => array_slice($porMotivo, 0, 20, true),
            'at' => RelativeTime::format($now),
        ];
    }

    // ── E3, E4, E5, E6 · listas y detalles ──────────────────────────────────

    /** @param  array{strategy?: string|null, status?: string|null, mode?: string|null}  $filters */
    public function enrollments(array $filters, int $perPage): LengthAwarePaginator
    {
        $q = DB::table(OutreachTables::ENROLLMENTS.' as e')->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'e.strategy_id')->orderByDesc('e.id');
        if (! empty($filters['strategy'])) {
            $q->where('s.uuid', strtolower((string) $filters['strategy']));
        }
        if (! empty($filters['status'])) {
            $q->where('e.status', (string) $filters['status']);
        }
        if (! empty($filters['mode'])) {
            $q->where('e.mode', (string) $filters['mode']);
        }

        return $q->paginate(min(max(1, $perPage), self::PER_PAGE_MAX), ['e.*', 's.uuid as strategy_uuid', 's.key as strategy_key'])->through(fn (object $e): array => $this->enrollmentDto($e));
    }

    /** @return array<string, mixed> */
    public function enrollment(string $uuid): array
    {
        $e = DB::table(OutreachTables::ENROLLMENTS.' as e')->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'e.strategy_id')->where('e.uuid', strtolower($uuid))->first(['e.*', 's.uuid as strategy_uuid', 's.key as strategy_key']);
        if ($e === null) {
            throw OutreachException::notFound('enrollment');
        }
        $trabajos = DB::table(OutreachTables::JOBS)->where('enrollment_id', $e->id)->orderBy('id')->get()->map(fn (object $j): array => $this->jobDto($j))->all();
        $decisiones = DB::table(OutreachTables::DECISIONS.' as d')->leftJoin(OutreachTables::JOBS.' as j', 'j.id', '=', 'd.job_id')->where('d.enrollment_id', $e->id)->orderBy('d.id')
            ->get(['d.uuid', 'd.kind', 'd.level', 'd.reasons', 'd.retry_at', 'd.evaluated_at', 'd.trace_hash', 'j.uuid as job_uuid'])
            ->map(static fn (object $d): array => [
                'uuid' => (string) $d->uuid, 'kind' => (string) $d->kind, 'level' => $d->level, 'reasons' => json_decode((string) $d->reasons, true) ?? [],
                'retryAt' => self::iso($d->retry_at), 'evaluatedAt' => self::iso($d->evaluated_at), 'traceHash' => (string) $d->trace_hash, 'job' => $d->job_uuid,
            ])->all();
        $resultados = DB::table(OutreachTables::OUTCOMES.' as o')->leftJoin(OutreachTables::JOBS.' as j', 'j.id', '=', 'o.job_id')->where('o.enrollment_id', $e->id)->orderBy('o.id')
            ->get(['o.uuid', 'o.outcome', 'o.source', 'o.channel', 'o.detail_code', 'o.reason_code', 'o.next_action', 'o.next_action_at', 'o.occurred_at', 'o.recorded_by_name', 'j.uuid as job_uuid'])
            ->map(static fn (object $o): array => [
                'uuid' => (string) $o->uuid, 'outcome' => (string) $o->outcome, 'source' => (string) $o->source, 'channel' => $o->channel, 'detailCode' => $o->detail_code,
                'reasonCode' => $o->reason_code, 'nextAction' => $o->next_action, 'nextActionAt' => self::iso($o->next_action_at), 'occurredAt' => self::iso($o->occurred_at),
                'recordedBy' => $o->recorded_by_name, 'job' => $o->job_uuid,
            ])->all();

        return $this->enrollmentDto($e) + ['jobs' => $trabajos, 'decisions' => $decisiones, 'outcomes' => $resultados];
    }

    /** @return array<string, mixed> */
    public function decision(string $uuid): array
    {
        $d = DB::table(OutreachTables::DECISIONS.' as d')->leftJoin(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'd.enrollment_id')->leftJoin(OutreachTables::JOBS.' as j', 'j.id', '=', 'd.job_id')
            ->leftJoin(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'd.strategy_id')->where('d.uuid', strtolower($uuid))
            ->first(['d.*', 'e.uuid as enrollment_uuid', 'j.uuid as job_uuid', 's.uuid as strategy_uuid']);
        if ($d === null) {
            throw OutreachException::notFound('decision');
        }
        $traza = json_decode((string) $d->trace_json, true);
        $t = is_array($traza) && array_is_list($traza) ? ($traza[0] ?? []) : (array) $traza;
        $canal = is_string($t['channel']['channel'] ?? null) ? $t['channel']['channel'] : (is_string($t['channel']['order'][0] ?? null) ? $t['channel']['order'][0] : null);
        $salida = $canal !== null && is_array($t['policy'][$canal]['output'] ?? null) ? $t['policy'][$canal]['output'] : null;

        return [
            'uuid' => (string) $d->uuid,
            'kind' => (string) $d->kind,
            'level' => $d->level,
            'reasons' => json_decode((string) $d->reasons, true) ?? [],
            'retryAt' => self::iso($d->retry_at),
            'evaluatedAt' => self::iso($d->evaluated_at),
            'mode' => (string) $d->mode,
            'subjectRef' => (string) $d->subject_ref,
            'strategy' => $d->strategy_uuid,
            'enrollment' => $d->enrollment_uuid,
            'job' => $d->job_uuid,
            'seal' => ['versionId' => (int) $d->strategy_version_id, 'contentHash' => (string) $d->content_hash, 'publicationSeq' => (int) $d->publication_seq, 'part' => (string) $d->part,
                'studioRefKind' => (string) $d->studio_ref_kind, 'studioVersion' => $d->studio_version_uuid, 'studioContentHash' => (string) $d->studio_content_hash,
                'catalogVersion' => (string) $d->catalog_version, 'evaluatorVersion' => (string) $d->evaluator_version],
            'facts' => json_decode((string) $d->facts_json, true),
            'factsHash' => (string) $d->facts_hash,
            'trace' => $t,
            'traceHash' => (string) $d->trace_hash,
            'text' => $salida === null ? [] : DecisionTraceText::explain($salida, is_array($t['channel'] ?? null) ? $t['channel'] : null),
        ];
    }

    /** @param  array{strategy?: string|null, status?: string|null, mode?: string|null, channel?: string|null}  $filters */
    public function jobs(array $filters, int $perPage): LengthAwarePaginator
    {
        $q = DB::table(OutreachTables::JOBS.' as j')->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')->orderByDesc('j.id');
        if (! empty($filters['strategy'])) {
            $q->where('s.uuid', strtolower((string) $filters['strategy']));
        }
        if (! empty($filters['status'])) {
            $q->where('j.status', (string) $filters['status']);
        }
        if (! empty($filters['mode'])) {
            $q->where('j.mode', (string) $filters['mode']);
        }
        if (! empty($filters['channel'])) {
            $q->where('j.channel', (string) $filters['channel']);
        }

        return $q->paginate(min(max(1, $perPage), self::PER_PAGE_MAX), ['j.*', 's.uuid as strategy_uuid'])->through(fn (object $j): array => $this->jobDto($j));
    }

    // ── E10 · historial por persona ─────────────────────────────────────────

    /**
     * El historial unificado de una persona por su SEUDÓNIMO (no enumerable):
     * se busca la clave interna en las filas del H9 (si la persona nunca tuvo
     * una, el historial está vacío y no se revela nada). El acceso queda en el
     * log de la aplicación con el actor y el seudónimo.
     *
     * @return array{entries: list<array<string, int|string|null>>, next: ?string, available: bool}
     */
    public function timeline(OutreachAccess $access, string $subjectRef, int $limit, ?string $cursor): array
    {
        if (! SubjectRef::isValid($subjectRef)) {
            throw OutreachException::invalid(['subjectRef' => ['invalid']]);
        }
        $clave = DB::table(OutreachTables::ENROLLMENTS)->where('subject_ref', $subjectRef)->value('subject_key')
            ?? DB::table(OutreachTables::DECISIONS)->where('subject_ref', $subjectRef)->value('subject_key');
        Log::info('outreach.timeline.viewed', ['admin_id' => $access->id(), 'subject_ref' => $subjectRef, 'found' => $clave !== null]);
        if (! is_string($clave)) {
            return ['entries' => [], 'next' => null, 'available' => $this->timeline->available()];
        }

        return $this->timeline->forSubject($clave, $limit, $cursor);
    }

    // ── DTO ─────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function enrollmentDto(object $e): array
    {
        return [
            'uuid' => (string) $e->uuid,
            'strategy' => ['uuid' => (string) $e->strategy_uuid, 'key' => (string) $e->strategy_key],
            'subjectRef' => (string) $e->subject_ref,
            'purpose' => (string) $e->purpose,
            'status' => (string) $e->status,
            'step' => (int) $e->step_index,
            'touches' => (int) $e->touches,
            'mode' => (string) $e->mode,
            'part' => (string) $e->part,
            'publicationSeq' => (int) $e->publication_seq,
            'cycleKey' => (string) $e->cycle_key,
            'enrolledAt' => self::iso($e->enrolled_at),
            'windowEndsAt' => self::iso($e->window_ends_at),
            'nextDueAt' => self::iso($e->next_due_at),
            'lastOutcome' => $e->last_outcome,
            'exitReason' => $e->exit_reason,
            'endedAt' => self::iso($e->ended_at),
        ];
    }

    /** @return array<string, mixed> */
    private function jobDto(object $j): array
    {
        return [
            'uuid' => (string) $j->uuid,
            'strategy' => $j->strategy_uuid ?? null,
            'subjectRef' => (string) $j->subject_ref,
            'step' => (int) $j->step_index,
            'attempt' => (int) $j->attempt,
            'channel' => (string) $j->channel,
            'medium' => $j->medium,
            'status' => (string) $j->status,
            'mode' => (string) $j->mode,
            'notBefore' => self::iso($j->not_before),
            'expiresAt' => self::iso($j->expires_at),
            'budgetDay' => (string) $j->budget_day,
            'reserved' => ['units' => (int) $j->reserved_units, 'micros' => (int) $j->reserved_micros, 'releasedAt' => self::iso($j->budget_released_at)],
            'cancelReason' => $j->cancel_reason,
            'claimedAt' => self::iso($j->claimed_at),
            'assisted' => $j->assisted_claimed_by_admin_id === null ? null : ['by' => (string) $j->assisted_claimed_by_name, 'at' => self::iso($j->assisted_claimed_at)],
            'statusChangedAt' => self::iso($j->status_changed_at),
        ];
    }

    private static function iso(mixed $at): ?string
    {
        return $at === null ? null : RelativeTime::format(OutreachStore::instant($at));
    }
}
