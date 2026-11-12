<?php

namespace App\Services\Outreach\Run;

use App\Models\Admin;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\SubjectKey;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * LA BANDEJA ASISTIDA (contrato H9, D9-9.5, §7 E11–E13; §13.1 #22; T6): los
 * trabajos `assisted` que SALIERON (`started`) en vivo y aún no tienen el
 * resultado del personal. Una persona con `commercial_automation.assisted_work`
 * los ve, RECLAMA uno (una sola vez: la base lo fija) y registra el resultado
 * del conjunto cerrado (fuente `staff`), idempotente por `clientRequestId`.
 * Solo quien lo reclamó registra su resultado (BOLA).
 *
 * LA API NO DEVUELVE TELÉFONOS NI NOMBRES: la estrategia, el objetivo, el
 * motivo (las frases de la traza), el guion de la plantilla, el plazo y la
 * referencia a la ficha de la persona en el CRM (`person: {kind, id}`), que
 * protegen los permisos que ya existen. La persona sigue siendo responsable
 * del contacto real: la política aplicó horario, frecuencia y consentimiento
 * DEL MEDIO.
 *
 * LA QUEJA SE VUELVE A MIRAR AL OFRECER Y AL RECLAMAR (M-5 de security; N6
 * `complaint_recent`): una inscripción que espera el resultado asistido no
 * se reevalúa, así que una queja posterior a la salida la ve la bandeja. Un
 * trabajo SIN reclamar de una persona que se quejó dentro de la ventana, o de
 * la que no hay datos de eventos (falla cerrado, como N6), no se ofrece ni se
 * reclama (409 `not_in_queue`). Quien ya lo reclamó lo sigue viendo y registra
 * su resultado. No se escribe nada: el trabajo y la inscripción siguen como
 * estaban hasta el resultado o el fin de la ventana. `human_requested` no se
 * mira aquí (L9-66: una derivada a persona sigue viva en la bandeja).
 */
final class AssistedQueue
{
    public const WORK = 'commercial_automation.assisted_work';

    public const MAX_PAGE = 50;

    /** Lo que el personal puede registrar (D9-10, columna «asistido»). */
    public const STAFF_OUTCOMES = ['no_answer', 'answered', 'callback_requested', 'human_requested', 'not_interested', 'wrong_person', 'opted_out', 'appointment_booked'];

    public function __construct(
        private readonly OutreachStore $store = new OutreachStore,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
    ) {}

    /**
     * Los trabajos de la bandeja: `started`, asistidos, en vivo, sin resultado
     * del personal; `mine` = los que reclamó quien mira, `unclaimed` = sin
     * reclamar, `all` = los dos. Los más urgentes (menor plazo) primero.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Estados de la inscripción con los que un trabajo asistido sigue vivo en la bandeja: abierta
     * (`active`/`waiting`) o derivada a una persona (`handed_off`, el caso `human_requested`).
     * Cerrada de otro modo (vencida, cancelada, completada, salida, opt-out) el trabajo no se
     * reclama ni admite resultado (REV9 m-7, L9-66).
     */
    public const QUEUE_ENROLLMENT_STATUSES = ['active', 'waiting', 'handed_off'];

    public function queue(Admin $viewer, string $filter = 'all', int $limit = 20): array
    {
        $q = DB::table(OutreachTables::JOBS.' as j')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->where('j.channel', 'assisted')->where('j.mode', OutreachConfig::MODE_LIVE)->where('j.status', 'started')
            ->whereIn('e.status', self::QUEUE_ENROLLMENT_STATUSES)
            ->whereNotExists(fn ($w) => $w->from(OutreachTables::OUTCOMES.' as o')->whereColumn('o.job_id', 'j.id')->where('o.source', 'staff'))
            ->orderBy('j.expires_at')->orderBy('j.id')
            ->limit(max(1, min(self::MAX_PAGE, $limit)));
        if ($filter === 'mine') {
            $q->where('j.assisted_claimed_by_admin_id', (int) $viewer->id);
        } elseif ($filter === 'unclaimed') {
            $q->whereNull('j.assisted_claimed_by_admin_id');
        }

        $filas = $q->get(['j.*', 'e.purpose', 'e.uuid as enrollment_uuid', 'e.step_index as enrollment_step', 's.uuid as strategy_uuid', 's.key as strategy_key'])->all();
        // M-5: lo SIN reclamar se vuelve a mirar (una foto para toda la página); lo reclamado sigue para quien lo tiene.
        $retenidos = $this->withheldByComplaint(array_values(array_filter($filas, static fn (object $j): bool => $j->assisted_claimed_by_admin_id === null)), self::now());

        return array_values(array_map(
            fn (object $j): array => $this->dto($j, (int) $viewer->id),
            array_filter($filas, static fn (object $j): bool => ! isset($retenidos[(int) $j->id])),
        ));
    }

    /** El detalle de un trabajo de la bandeja (404 si no es asistido en vivo). @return array<string, mixed> */
    public function show(string $jobUuid): array
    {
        return $this->dto($this->assistedJob($jobUuid));
    }

    /**
     * Reclamar UNA vez. 404 si no existe; 409 si no está en la bandeja
     * (`not_in_queue`, también si la persona se quejó hace poco o no hay datos
     * de eventos: M-5) o ya lo reclamó otra persona (`already_claimed`);
     * idempotente para quien lo reclamó.
     *
     * @return array{job: array<string, mixed>, replayed: bool}
     */
    public function claim(Admin $by, string $jobUuid, string $clientRequestId): array
    {
        if (! Str::isUuid($clientRequestId)) {
            throw OutreachException::invalid(['clientRequestId' => ['uuid']]);
        }
        $trabajo = $this->assistedJob($jobUuid);
        if ((string) $trabajo->status !== 'started') {
            throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
        }
        if ($trabajo->assisted_claimed_by_admin_id !== null) {
            if ((int) $trabajo->assisted_claimed_by_admin_id === (int) $by->id) {
                return ['job' => $this->dto($this->assistedJob($jobUuid), (int) $by->id), 'replayed' => true];
            }
            throw OutreachException::conflict('already_claimed', 'Otra persona ya reclamó ese trabajo.');
        }
        $this->requireOpenEnrollment((int) $trabajo->enrollment_id);
        $this->requireNoRecentComplaint($trabajo);
        $ahora = RelativeTime::utc(now());
        $cambiadas = DB::table(OutreachTables::JOBS)->where('id', $trabajo->id)->where('status', 'started')->whereNull('assisted_claimed_by_admin_id')->update([
            'assisted_claimed_by_admin_id' => (int) $by->id,
            'assisted_claimed_by_name' => mb_substr((string) $by->name, 0, 120),
            'assisted_claimed_at' => OutreachStore::sql($ahora),
            'updated_at' => OutreachStore::sql($ahora),
        ]);
        if ($cambiadas !== 1) {
            throw OutreachException::conflict('already_claimed', 'Otra persona ya reclamó ese trabajo.');
        }

        return ['job' => $this->dto($this->assistedJob($jobUuid), (int) $by->id), 'replayed' => false];
    }

    /**
     * El resultado del personal (fuente `staff`), solo de quien reclamó el
     * trabajo (403 `not_claimer`), del conjunto cerrado del asistido (422), e
     * idempotente por `clientRequestId` (la misma petición devuelve
     * `replayed`). `callbackAt` acompaña a `callback_requested` (N7: la
     * escalera lo aplaza dentro de la ley y la ventana).
     *
     * @return array{result: string, outcome: string, transition: string|null, replayed: bool}
     */
    public function recordOutcome(Admin $by, string $jobUuid, string $outcome, string $clientRequestId, ?string $callbackAt = null): array
    {
        $errores = [];
        if (! Str::isUuid($clientRequestId)) {
            $errores['clientRequestId'] = ['uuid'];
        }
        if (! in_array($outcome, self::STAFF_OUTCOMES, true)) {
            $errores['outcome'] = ['not_allowed_for_staff'];
        }
        if ($callbackAt !== null && (! RelativeTime::isInstant($callbackAt) || RelativeTime::parseInstant($callbackAt) <= now())) {
            $errores['callbackAt'] = ['future_instant'];
        }
        if ($callbackAt !== null && $outcome !== 'callback_requested') {
            $errores['callbackAt'] = ['only_with_callback_requested'];
        }
        if ($errores !== []) {
            throw OutreachException::invalid($errores);
        }
        $trabajo = $this->assistedJob($jobUuid);
        if ((string) $trabajo->status !== 'started') {
            throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
        }
        if ($trabajo->assisted_claimed_by_admin_id === null || (int) $trabajo->assisted_claimed_by_admin_id !== (int) $by->id) {
            throw new OutreachException('Solo quien reclamó el trabajo registra su resultado.', 'not_claimer', 403);
        }
        $e = $this->store->enrollment((int) $trabajo->enrollment_id);
        if ($e === null) {
            throw OutreachException::notFound('enrollment');
        }
        $clave = 'staff:'.strtolower($clientRequestId);
        $previo = DB::table(OutreachTables::OUTCOMES)->where('enrollment_id', $e->id)->where('source', 'staff')->where('source_key', $clave)->first(['outcome', 'next_action']);
        if ($previo !== null) {
            return ['result' => OutcomeRecorder::RESULT_DUPLICATE, 'outcome' => (string) $previo->outcome, 'transition' => $previo->next_action, 'replayed' => true];
        }
        // Tras la repetición idempotente (la misma petición devuelve lo mismo aunque la inscripción ya se haya cerrado con ese resultado).
        if (! in_array((string) $e->status, self::QUEUE_ENROLLMENT_STATUSES, true)) {
            throw OutreachException::conflict('enrollment_closed', 'La inscripción de ese trabajo ya se cerró.');
        }
        if (DB::table(OutreachTables::OUTCOMES)->where('job_id', $trabajo->id)->where('source', 'staff')->exists()) {
            throw OutreachException::conflict('outcome_already_recorded', 'Ese trabajo ya tiene el resultado del personal.');
        }
        $r = $this->outcomes->record($e, $outcome, 'staff', $clave, RelativeTime::utc(now()), ['job' => $trabajo, 'recordedBy' => $by, 'callbackAt' => $callbackAt]);
        if ($r['result'] === OutcomeRecorder::RESULT_IGNORED) {
            throw OutreachException::invalid(['outcome' => ['not_allowed_for_staff']]);
        }

        return ['result' => $r['result'], 'outcome' => (string) $r['outcome'], 'transition' => $r['transition'], 'replayed' => false];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** m-7 (REV9, L9-66): un trabajo asistido cuya inscripción ya se cerró (vencida, cancelada) no se reclama ni admite resultado. */
    private function requireOpenEnrollment(int $enrollmentId): void
    {
        $e = $this->store->enrollment($enrollmentId);
        if ($e === null || ! in_array((string) $e->status, self::QUEUE_ENROLLMENT_STATUSES, true)) {
            throw OutreachException::conflict('enrollment_closed', 'La inscripción de ese trabajo ya se cerró.');
        }
    }

    /**
     * M-5: un trabajo sin reclamar de una persona que se quejó hace poco (o de la que no hay datos de eventos)
     * no se reclama. Al CRM, `not_in_queue` (la bandeja ya no lo ofrece); el motivo, al registro, sin datos personales.
     */
    private function requireNoRecentComplaint(object $trabajo): void
    {
        $retenido = $this->withheldByComplaint([$trabajo], self::now());
        if ($retenido !== []) {
            Log::warning('outreach.assisted.claim_withheld', ['job' => (string) $trabajo->uuid, 'reason' => 'complaint_recent', 'known' => $retenido[(int) $trabajo->id]]);

            throw OutreachException::conflict('not_in_queue', 'Ese trabajo ya no está en la bandeja.');
        }
    }

    /**
     * M-5 (N6 `complaint_recent`): ¿se quejó hace poco la persona de cada trabajo? Por el MISMO camino que el
     * despachador (etapa 6.5): la foto del lote con SOLO `complaint_created` (una consulta a `commercial_events`
     * para todas, sin política) y {@see LadderInputBuilder::complaintRecently()} con la finalidad de la
     * inscripción (la ventana y las finalidades de `OutreachConfig`). Se retiene con `true` y con `null` (sin
     * datos de eventos o persona sin resolver: falla cerrado, como N6). Una clave que ya no es de una persona
     * (la supresión 1581 la deja en `x:<id>`) no se lee y cuenta como sin datos.
     *
     * @param  list<object>  $trabajos  filas con `id`, `subject_key` y `purpose`
     * @return array<int, bool> por id de trabajo retenido: ¿había datos? (true = queja reciente; false = no se sabe)
     */
    private function withheldByComplaint(array $trabajos, DateTimeImmutable $ahora): array
    {
        if ($trabajos === []) {
            return [];
        }
        $claves = array_values(array_unique(array_filter(
            array_map(static fn (object $j): string => (string) $j->subject_key, $trabajos),
            static fn (string $c): bool => SubjectKey::isValid($c),
        )));
        $foto = $claves === [] ? null : $this->snapshots->build($claves, $ahora, CoreProjection::selectionFor(['events' => ['complaint_created']], false));
        $retenidos = [];
        foreach ($trabajos as $j) {
            $s = $foto?->subject((string) $j->subject_key);
            $queja = LadderInputBuilder::complaintRecently($s === null ? null : CoreProjection::snapshot($s)->events, $ahora, (string) $j->purpose);
            if ($queja !== false) {
                $retenidos[(int) $j->id] = $queja === true;
            }
        }

        return $retenidos;
    }

    private function assistedJob(string $jobUuid): object
    {
        $j = DB::table(OutreachTables::JOBS.' as j')
            ->join(OutreachTables::ENROLLMENTS.' as e', 'e.id', '=', 'j.enrollment_id')
            ->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->where('j.uuid', strtolower($jobUuid))->where('j.channel', 'assisted')->where('j.mode', OutreachConfig::MODE_LIVE)
            ->first(['j.*', 'e.purpose', 'e.uuid as enrollment_uuid', 'e.step_index as enrollment_step', 's.uuid as strategy_uuid', 's.key as strategy_key']);
        if ($j === null) {
            throw OutreachException::notFound('job');
        }

        return $j;
    }

    /** @return array<string, mixed> */
    /**
     * La fila de la bandeja. `person` (seudónimo → identidad) y `script` (el guion) solo los ve
     * quien RECLAMÓ el trabajo (modelo de amenazas §11; REV9 m-2, L9-63): en las vistas `all` y
     * `unclaimed`, y para cualquier otra persona, van en null. El CRM los trata como opcionales.
     */
    private function dto(object $j, ?int $viewerId = null): array
    {
        $reclamante = $viewerId !== null && $j->assisted_claimed_by_admin_id !== null && (int) $j->assisted_claimed_by_admin_id === $viewerId;
        $persona = SubjectKey::isValid((string) $j->subject_key) ? SubjectKey::parse((string) $j->subject_key) : null;
        $contenido = $this->bridge->contentOfVersion((int) $j->strategy_version_id);

        return [
            'uuid' => (string) $j->uuid,
            'enrollment' => (string) $j->enrollment_uuid,
            'strategy' => ['uuid' => (string) $j->strategy_uuid, 'key' => (string) $j->strategy_key, 'goal' => $contenido['goal'] ?? null, 'purpose' => (string) $j->purpose],
            'step' => (int) $j->step_index,
            'attempt' => (int) $j->attempt,
            'medium' => (string) $j->medium,
            'status' => (string) $j->status,
            'subjectRef' => (string) $j->subject_ref,
            'person' => ($reclamante && $persona !== null) ? ['kind' => $persona['kind'] === SubjectKey::MEMBER ? 'member' : 'lead', 'id' => $persona['id']] : null,
            'script' => ($reclamante && $j->template_version_id !== null) ? $this->bridge->assistedScript((int) $j->template_version_id) : null,
            'reason' => $this->reasonOf((int) $j->id),
            'startedAt' => self::iso($j->status_changed_at),
            'dueBy' => self::iso($j->expires_at),
            'claimedBy' => $j->assisted_claimed_by_admin_id === null ? null : ['id' => (int) $j->assisted_claimed_by_admin_id, 'name' => (string) $j->assisted_claimed_by_name, 'at' => self::iso($j->assisted_claimed_at)],
            'outcomes' => self::STAFF_OUTCOMES,
        ];
    }

    /** Las frases de la decisión que planificó el trabajo (sin datos personales). @return list<string> */
    private function reasonOf(int $jobId): array
    {
        $plan = DB::table(OutreachTables::DECISIONS)->where('job_id', $jobId)->where('kind', 'plan')->orderBy('id')->first(['trace_json']);
        if ($plan === null) {
            return [];
        }
        $traza = json_decode((string) $plan->trace_json, true);
        $t = is_array($traza) && array_is_list($traza) ? ($traza[0] ?? []) : (array) $traza;
        $canal = is_string($t['channel']['channel'] ?? null) ? $t['channel']['channel'] : null;
        $salida = $canal !== null && is_array($t['policy'][$canal]['output'] ?? null) ? $t['policy'][$canal]['output'] : null;

        return $salida === null ? [] : DecisionTraceText::explain($salida, is_array($t['channel'] ?? null) ? $t['channel'] : null);
    }

    private static function iso(mixed $at): ?string
    {
        return $at === null ? null : RelativeTime::format(OutreachStore::instant($at));
    }

    /** El estado de la inscripción tras un resultado, para la respuesta. */
    public static function enrollmentStatusAfter(array $r): ?string
    {
        return $r['next']['status'] ?? null;
    }

    public static function now(): DateTimeImmutable
    {
        return RelativeTime::utc(now());
    }

    /** Las transiciones que el personal verá como posibles para un resultado (la tabla, no la estrategia). @return list<string> */
    public static function transitionsFor(string $outcome): array
    {
        return OutcomeTable::TABLE[$outcome]['allowed'] ?? [];
    }
}
