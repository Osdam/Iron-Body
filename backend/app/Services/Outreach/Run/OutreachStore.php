<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\SubjectKey;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EL ALMACÉN de las filas de la ejecución (contrato H9, D9-8 «todas filas»,
 * §5 el sello, §6.2): el único sitio que escribe `outreach_enrollments`,
 * `outreach_jobs`, `outreach_decisions`, `outreach_outcomes` (por
 * `OutcomeRecorder`) y `outreach_cursors`, con el constructor de consultas
 * (sin modelos: lo que manda es la base y sus disparadores). Toda escritura
 * respeta lo que la base exige y la ejecución promete:
 *
 *  - el sello viaja con cada fila y no cambia (lo copia de la inscripción);
 *  - las decisiones y los resultados son de SOLO INSERCIÓN: una traza se
 *    guarda como una LISTA (`[traza]`, el CHECK `odec_trace_chk` pide un
 *    array JSON; la huella es la de esos bytes) y la foto de hechos como un
 *    objeto ≤ 4 KB (si no cabe, se recorta de forma determinista y se marca);
 *  - los estados solo avanzan: cada cambio es un UPDATE CONDICIONADO al
 *    estado anterior (0 filas = otro proceso ganó);
 *  - sin teléfonos, nombres ni textos: la persona es `subject_key` y
 *    `subject_ref`; `phone_ref` es un seudónimo.
 */
final class OutreachStore
{
    /** Lo que hay que reintentar con otro intento (la fila de un intento es única: `ojob_attempt_unique`). */
    public const MAX_ATTEMPTS = 3;

    // ── Inscripciones ───────────────────────────────────────────────────────

    /**
     * Nace `active`, en el paso 0, lista para planificarse ahora. null si la
     * ocurrencia ya estaba inscrita (`oenr_occurrence_unique`) o ya hay una
     * abierta de esa estrategia para la persona (`oenr_one_open`): las dos
     * carreras de §13.1 #13 y #14 se resuelven en la base.
     */
    public function insertEnrollment(LiveStrategy $live, string $mode, string $subjectKey, string $subjectRef, string $cycleKey, DateTimeImmutable $now, DateTimeImmutable $windowEndsAt): ?int
    {
        $p = SubjectKey::parse($subjectKey);
        $fila = $live->seal($mode) + [
            'uuid' => (string) Str::uuid(),
            'subject_key' => $subjectKey,
            'subject_ref' => $subjectRef,
            'member_id' => $p['kind'] === SubjectKey::MEMBER ? $p['id'] : null,
            'lead_id' => $p['kind'] === SubjectKey::LEAD ? $p['id'] : null,
            'cycle_key' => $cycleKey,
            'purpose' => $live->purpose,
            'status' => 'active',
            'step_index' => 0,
            'touches' => 0,
            'next_due_at' => self::sql($now),
            'enrolled_at' => self::sql($now),
            'window_ends_at' => self::sql($windowEndsAt),
            'last_outcome' => null,
            'exit_reason' => null,
            'ended_at' => null,
            'status_changed_at' => self::sql($now),
            'created_at' => self::sql($now),
            'updated_at' => self::sql($now),
        ];
        try {
            return (int) DB::table(OutreachTables::ENROLLMENTS)->insertGetId($fila);
        } catch (UniqueConstraintViolationException) {
            // La ocurrencia ya está inscrita o la persona ya tiene una abierta: lo decide la base (una sola inscripción).
            return null;
        }
    }

    public function enrollment(int $id): ?object
    {
        return DB::table(OutreachTables::ENROLLMENTS)->where('id', $id)->first();
    }

    public function enrollmentByUuid(string $uuid): ?object
    {
        return DB::table(OutreachTables::ENROLLMENTS)->where('uuid', strtolower($uuid))->first();
    }

    public function occurrenceEnrolled(int $strategyId, string $subjectKey, string $cycleKey, string $mode): bool
    {
        return DB::table(OutreachTables::ENROLLMENTS)->where('strategy_id', $strategyId)->where('subject_key', $subjectKey)->where('cycle_key', $cycleKey)->where('mode', $mode)->exists();
    }

    public function hasOpenEnrollment(int $strategyId, string $subjectKey, string $mode): bool
    {
        return DB::table(OutreachTables::ENROLLMENTS)->where('strategy_id', $strategyId)->where('subject_key', $subjectKey)->where('mode', $mode)
            ->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)->exists();
    }

    /** El enfriamiento tras salir (D9-6 `limits.cooldownDays`): hasta cuándo no se vuelve a inscribir, o null. */
    public function cooldownUntil(int $strategyId, string $subjectKey, string $mode, int $cooldownDays, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($cooldownDays <= 0) {
            return null;
        }
        $fin = DB::table(OutreachTables::ENROLLMENTS)->where('strategy_id', $strategyId)->where('subject_key', $subjectKey)->where('mode', $mode)
            ->whereNotNull('ended_at')->max('ended_at');
        if ($fin === null) {
            return null;
        }
        $hasta = self::instant($fin)->modify("+{$cooldownDays} days");

        return $hasta > $now ? $hasta : null;
    }

    public function enrollmentsCreatedOn(int $strategyId, string $mode, string $bogotaDay): int
    {
        [$desde, $hasta] = self::dayBounds($bogotaDay);

        return (int) DB::table(OutreachTables::ENROLLMENTS)->where('strategy_id', $strategyId)->where('mode', $mode)
            ->where('enrolled_at', '>=', $desde)->where('enrolled_at', '<', $hasta)->count();
    }

    /**
     * Las inscripciones ABIERTAS que ya tocan (sin trabajo vivo), las más antiguas primero.
     * Abiertas son `active` Y `waiting` (D-1 de TEST9-c, L9-65): una inscripción que espera
     * (negativa permanente con `on.blocked = wait`, `callback_requested`, `no_answer` con
     * `wait`) vuelve a tocar cuando llega su `next_due_at`; si no, nunca aprovecharía una
     * autorización tardía ni haría la llamada pedida. Al planificar un trabajo vuelve a `active`.
     *
     * @return list<object>
     */
    public function dueEnrollments(string $mode, DateTimeImmutable $now, int $limit): array
    {
        return DB::table(OutreachTables::ENROLLMENTS.' as e')
            ->where('e.mode', $mode)
            ->whereIn('e.status', OutreachTables::ENROLLMENT_OPEN_STATUSES)
            ->whereNotNull('e.next_due_at')
            ->where('e.next_due_at', '<=', self::sql($now))
            ->whereNotExists(fn ($q) => $q->from(OutreachTables::JOBS.' as j')->whereColumn('j.enrollment_id', 'e.id')->whereIn('j.status', OutreachTables::JOB_LIVE_STATUSES))
            ->orderBy('e.next_due_at')->orderBy('e.id')
            ->limit(max(1, $limit))
            ->get(['e.*'])->all();
    }

    /** Las inscripciones abiertas de una persona (cualquier estrategia) en un modo. @return list<object> */
    public function openEnrollmentsOf(string $subjectKey, string $mode): array
    {
        return DB::table(OutreachTables::ENROLLMENTS)->where('subject_key', $subjectKey)->where('mode', $mode)
            ->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)->orderBy('id')->get()->all();
    }

    /**
     * Cambia una inscripción ABIERTA (condicionado a que siga abierta). `$changes`
     * solo con columnas mutables. Devuelve si cambió.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateOpenEnrollment(int $id, array $changes, DateTimeImmutable $now): bool
    {
        $changes['updated_at'] = self::sql($now);
        if (isset($changes['status'])) {
            $changes['status_changed_at'] = self::sql($now);
        }

        return DB::table(OutreachTables::ENROLLMENTS)->where('id', $id)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)->update($changes) === 1;
    }

    /** Cierra una inscripción abierta en un estado final con su motivo (código). */
    public function closeEnrollment(int $id, string $status, string $exitReason, DateTimeImmutable $now, ?string $lastOutcome = null): bool
    {
        $cambios = [
            'status' => $status,
            'exit_reason' => $exitReason,
            'ended_at' => self::sql($now),
            'next_due_at' => null,
        ];
        if ($lastOutcome !== null) {
            $cambios['last_outcome'] = $lastOutcome;
        }

        return $this->updateOpenEnrollment($id, $cambios, $now);
    }

    // ── Trabajos ────────────────────────────────────────────────────────────

    public function job(int $id): ?object
    {
        return DB::table(OutreachTables::JOBS)->where('id', $id)->first();
    }

    public function jobByUuid(string $uuid): ?object
    {
        return DB::table(OutreachTables::JOBS)->where('uuid', strtolower($uuid))->first();
    }

    /** El trabajo vivo de una persona en un modo (como mucho uno: `ojob_one_live`). */
    public function liveJobOf(string $subjectKey, string $mode): ?object
    {
        return DB::table(OutreachTables::JOBS)->where('subject_key', $subjectKey)->where('mode', $mode)->whereIn('status', OutreachTables::JOB_LIVE_STATUSES)->first();
    }

    /** El intento siguiente de un paso de la inscripción (cada intento es una fila). */
    public function nextAttempt(int $enrollmentId, int $stepIndex): int
    {
        return ((int) DB::table(OutreachTables::JOBS)->where('enrollment_id', $enrollmentId)->where('step_index', $stepIndex)->max('attempt')) + 1;
    }

    /**
     * Nace `planned`. `$reserved` es lo que se reservó (0 en sombra o por el
     * canal simulado). null si la persona ya tiene un trabajo vivo
     * (`ojob_one_live`) o el intento ya existe (`ojob_attempt_unique`).
     *
     * @param  array{units: int, micros: int}  $reserved
     */
    public function insertJob(object $enrollment, int $stepIndex, int $attempt, string $channel, ?string $medium, ?int $templateVersionId, ?string $templateHash,
        ?string $phoneRef, DateTimeImmutable $notBefore, DateTimeImmutable $expiresAt, string $budgetDay, array $reserved, DateTimeImmutable $now): ?array
    {
        $uuid = (string) Str::uuid();
        $fila = self::sealOf($enrollment) + [
            'uuid' => $uuid,
            'enrollment_id' => (int) $enrollment->id,
            'subject_key' => (string) $enrollment->subject_key,
            'subject_ref' => (string) $enrollment->subject_ref,
            'phone_ref' => $phoneRef,
            'step_index' => $stepIndex,
            'attempt' => $attempt,
            'channel' => $channel,
            'medium' => $medium,
            'template_version_id' => $templateVersionId,
            'template_hash' => $templateHash,
            'idempotency_key' => hash('sha256', $enrollment->uuid.'|'.$stepIndex.'|'.$attempt),
            'status' => 'planned',
            'not_before' => self::sql($notBefore),
            'expires_at' => self::sql($expiresAt),
            'budget_day' => $budgetDay,
            'reserved_units' => $reserved['units'],
            'reserved_micros' => $reserved['micros'],
            'budget_released_at' => null,
            'cancel_reason' => null,
            'claimed_at' => null,
            'marketing_message_id' => null,
            'voice_call_id' => null,
            'assisted_claimed_by_admin_id' => null,
            'assisted_claimed_by_name' => null,
            'assisted_claimed_at' => null,
            'status_changed_at' => self::sql($now),
            'created_at' => self::sql($now),
            'updated_at' => self::sql($now),
        ];
        try {
            $id = (int) DB::table(OutreachTables::JOBS)->insertGetId($fila);
        } catch (UniqueConstraintViolationException) {
            // Otro trabajo vivo de la persona, o este intento ya existe: lo decide la base.
            return null;
        }

        return ['id' => $id, 'uuid' => $uuid];
    }

    /**
     * Avanza un trabajo SOLO si sigue en uno de `$from` (0 filas = otro
     * proceso ganó o el estado ya no era ese). `$extra` solo con columnas
     * mutables que la base admite en ese paso.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    public function transitionJob(int $id, array $from, string $to, DateTimeImmutable $now, array $extra = []): bool
    {
        return DB::table(OutreachTables::JOBS)->where('id', $id)->whereIn('status', $from)
            ->update($extra + ['status' => $to, 'status_changed_at' => self::sql($now), 'updated_at' => self::sql($now)]) === 1;
    }

    /** La liberación de la reserva, una vez (`budget_released_at`); solo sobre `canceled`, `expired` o `failed_to_start`. */
    public function markBudgetReleased(int $jobId, DateTimeImmutable $now): bool
    {
        return DB::table(OutreachTables::JOBS)->where('id', $jobId)->whereNull('budget_released_at')
            ->whereIn('status', ['canceled', 'expired', 'failed_to_start'])
            ->update(['budget_released_at' => self::sql($now), 'updated_at' => self::sql($now)]) === 1;
    }

    /** Los trabajos de una estrategia que CUENTAN en un día de Bogotá (vivos o que salieron; no los cancelados ni vencidos): `strategy.usedToday`. */
    public function strategyJobsOn(int $strategyId, string $mode, string $bogotaDay): int
    {
        return (int) DB::table(OutreachTables::JOBS)->where('strategy_id', $strategyId)->where('mode', $mode)->where('budget_day', $bogotaDay)
            ->whereNotIn('status', ['canceled', 'expired'])->count();
    }

    /** Los trabajos vivos de una persona (cualquier estrategia) en un modo. @return list<object> */
    public function liveJobsOf(string $subjectKey, string $mode): array
    {
        return DB::table(OutreachTables::JOBS)->where('subject_key', $subjectKey)->where('mode', $mode)->whereIn('status', OutreachTables::JOB_LIVE_STATUSES)->orderBy('id')->get()->all();
    }

    /** Los trabajos que SALIERON hoy (día de Bogotá) de la persona en vivo, con su finalidad: la del día y el presupuesto. @return list<object> */
    public function startedTodayOf(string $subjectKey, string $mode, string $bogotaDay): array
    {
        [$desde, $hasta] = self::dayBounds($bogotaDay);

        return DB::table(OutreachTables::JOBS.' as j')->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->where('j.subject_key', $subjectKey)->where('j.mode', $mode)
            ->whereIn('j.status', ['started', ...OutreachTables::JOB_LIVE_STATUSES])
            ->where('j.not_before', '>=', $desde)->where('j.not_before', '<', $hasta)
            ->orderBy('j.id')->get(['j.*', 'e.purpose'])->all();
    }

    /**
     * Los trabajos de la persona que salieron en vivo en los últimos 7 días
     * y todavía no tienen resultado del personal (el adaptador de B9-facts
     * cuenta el contacto asistido cuando el personal lo registra; hasta
     * entonces lo cuenta esto, sin duplicar).
     *
     * @return list<array{at: string, medium: string, purpose: string, source: string}>
     */
    public function recentStartedWithoutStaffOutcome(string $subjectKey, DateTimeImmutable $now): array
    {
        $filas = DB::table(OutreachTables::JOBS.' as j')->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->where('j.subject_key', $subjectKey)->where('j.mode', OutreachConfig::MODE_LIVE)->where('j.status', 'started')
            ->where('j.status_changed_at', '>=', self::sql($now->modify('-8 days')))
            ->whereNotExists(fn ($q) => $q->from(OutreachTables::OUTCOMES.' as o')->whereColumn('o.job_id', 'j.id')->where('o.source', 'staff'))
            ->get(['j.channel', 'j.medium', 'j.status_changed_at', 'e.purpose']);
        $salida = [];
        foreach ($filas as $f) {
            $medio = SubjectSuppressions::mediumOfJob($f->channel, $f->medium);
            if ($medio === null) {
                continue;
            }
            $salida[] = ['at' => RelativeTime::format(self::instant($f->status_changed_at)), 'medium' => $medio, 'purpose' => (string) $f->purpose, 'source' => 'outreach'];
        }

        return $salida;
    }

    /**
     * Los contactos de HOY por teléfono (L9-30): los trabajos en vivo de hoy
     * (vivos o que salieron) a esos seudónimos, de quien sea.
     *
     * @param  list<string>  $phoneRefs
     * @return list<array{at: string, phoneRef: string}>
     */
    public function phoneContactsToday(array $phoneRefs, string $bogotaDay): array
    {
        if ($phoneRefs === []) {
            return [];
        }
        $salida = [];
        foreach (DB::table(OutreachTables::JOBS)->whereIn('phone_ref', $phoneRefs)->where('budget_day', $bogotaDay)->where('mode', OutreachConfig::MODE_LIVE)
            ->whereIn('status', ['started', ...OutreachTables::JOB_LIVE_STATUSES])->get(['phone_ref', 'not_before']) as $f) {
            $salida[] = ['at' => RelativeTime::format(self::instant($f->not_before)), 'phoneRef' => (string) $f->phone_ref];
        }

        return $salida;
    }

    // ── Decisiones (solo inserción) ─────────────────────────────────────────

    /**
     * Una decisión con su traza (una LISTA de un elemento: el CHECK de la
     * base pide un array) y su foto de hechos derivados (≤ 4 KB, recortada
     * de forma determinista si no cabe: `facts_truncated` en la traza).
     *
     * @param  array<string, int|string|null>  $seal
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $trace  lo que devuelve `DecisionTrace::build()`
     */
    public function insertDecision(array $seal, string $subjectKey, string $subjectRef, string $kind, ?string $level, array $reasons, ?DateTimeImmutable $retryAt,
        FactSnapshot $facts, array $trace, DateTimeImmutable $now, ?int $runId = null, ?int $enrollmentId = null, ?int $jobId = null, ?int $templateVersionId = null, ?string $templateHash = null): array
    {
        $hechos = self::factsForStorage($facts);
        if ($hechos['truncated']) {
            $trace['factsTruncated'] = true;
        }
        $traza = DecisionTrace::json([$trace]);
        $uuid = (string) Str::uuid();
        $id = (int) DB::table(OutreachTables::DECISIONS)->insertGetId(array_intersect_key($seal, array_flip(OutreachTables::SEAL_COLUMNS)) + [
            'uuid' => $uuid,
            'run_id' => $runId,
            'enrollment_id' => $enrollmentId,
            'job_id' => $jobId,
            'subject_key' => $subjectKey,
            'subject_ref' => $subjectRef,
            'kind' => $kind,
            'level' => $level,
            'reasons' => json_encode(array_values(array_slice($reasons, 0, 16)), JSON_UNESCAPED_SLASHES),
            'retry_at' => $retryAt === null ? null : self::sql($retryAt),
            'facts_json' => $hechos['json'],
            'facts_hash' => $hechos['hash'],
            'trace_json' => $traza,
            'trace_hash' => hash('sha256', $traza),
            'template_version_id' => $templateVersionId,
            'template_hash' => $templateHash,
            'evaluated_at' => self::sql($now),
            'created_at' => self::sql($now),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'traceHash' => hash('sha256', $traza)];
    }

    public function decisionByUuid(string $uuid): ?object
    {
        return DB::table(OutreachTables::DECISIONS)->where('uuid', strtolower($uuid))->first();
    }

    /**
     * La foto que se guarda: el objeto canónico entero si cabe en 4 KB; si no,
     * sin las listas (primero los eventos, después las transiciones, después
     * los contactos), en ese orden, hasta que quepa.
     *
     * @return array{json: string, hash: string, truncated: bool}
     */
    public static function factsForStorage(FactSnapshot $facts): array
    {
        $datos = $facts->toArray();
        $json = CanonicalJson::encode($datos);
        $recortada = false;
        foreach (['events', 'transitions', 'contacts'] as $lista) {
            if (strlen($json) <= DecisionTrace::MAX_FACTS_BYTES) {
                break;
            }
            $datos[$lista] = null;
            $recortada = true;
            $json = CanonicalJson::encode($datos);
        }

        return ['json' => $json, 'hash' => hash('sha256', $json), 'truncated' => $recortada];
    }

    // ── Cursores ────────────────────────────────────────────────────────────

    public function cursor(string $source): int
    {
        return (int) (DB::table(OutreachTables::CURSORS)->where('source', $source)->value('last_id') ?? 0);
    }

    /** Solo hacia delante: un valor menor no se escribe (la base lo rechazaría igualmente). */
    public function advanceCursor(string $source, int $lastId, DateTimeImmutable $now): void
    {
        $actual = DB::table(OutreachTables::CURSORS)->where('source', $source)->first(['last_id']);
        if ($actual === null) {
            DB::table(OutreachTables::CURSORS)->insertOrIgnore(['source' => $source, 'last_id' => max(0, $lastId), 'created_at' => self::sql($now), 'updated_at' => self::sql($now)]);

            return;
        }
        if ($lastId > (int) $actual->last_id) {
            DB::table(OutreachTables::CURSORS)->where('source', $source)->where('last_id', '<', $lastId)->update(['last_id' => $lastId, 'updated_at' => self::sql($now)]);
        }
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** El sello de una fila (inscripción o trabajo), para las filas que nacen de ella. @return array<string, int|string|null> */
    public static function sealOf(object $row): array
    {
        $sello = [];
        foreach (OutreachTables::SEAL_COLUMNS as $c) {
            $sello[$c] = $row->{$c};
        }

        return $sello;
    }

    public static function sql(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Un `timestamp` leído de la base (hora de pared UTC) como instante. */
    public static function instant(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    /** [desde, hasta) en UTC de un día de Bogotá. @return array{0: string, 1: string} */
    public static function dayBounds(string $bogotaDay): array
    {
        $inicio = new DateTimeImmutable($bogotaDay.' 00:00:00', new \DateTimeZone(RelativeTime::ZONE));

        return [self::sql($inicio), self::sql($inicio->modify('+1 day'))];
    }
}
