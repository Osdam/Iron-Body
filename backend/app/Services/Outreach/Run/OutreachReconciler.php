<?php

namespace App\Services\Outreach\Run;

use App\Models\Incident;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA CONCILIACIÓN (`outreach:reconcile`; contrato H9, D9-8 «Durabilidad»;
 * §13.1 #24): si un proceso muere, las filas quedan en estados no finales y
 * esto las cierra SIN REENVIAR NADA:
 *
 *  - `claimed` o `dispatching` con más de 10 min → `ambiguous`, con su
 *    resultado `ambiguous` (fuente `system`, detalle `stale`) y un incidente
 *    de IRON GUARD: nadie sabe si salió; una persona lo resuelve con un
 *    resultado (asistido) o lo resolverán los normalizadores de W9/V9;
 *  - `planned` con `expires_at` vencido → `expired`, con la reserva liberada,
 *    y la inscripción vuelve a tocar (otro trabajo, otro intento);
 *  - inscripciones abiertas con `window_ends_at` vencido → `expired` con el
 *    resultado `no_response`, y sus trabajos vivos vencidos.
 *
 * Solo trabaja con la maestra encendida y un modo distinto de `off`; deja su
 * pasada. `outreach:replay` es otra pieza ({@see OutreachReplay}).
 */
final class OutreachReconciler
{
    public const INCIDENT_KIND = 'outreach_job_stale';

    public function __construct(
        private readonly OutreachStore $store = new OutreachStore,
        private readonly BudgetReservations $budgets = new BudgetReservations,
        private readonly OutreachRuns $runs = new OutreachRuns,
        private readonly OutcomeRecorder $outcomes = new OutcomeRecorder,
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
    ) {}

    /** @return array<string, mixed> */
    public function run(DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        if (! OutreachConfig::active()) {
            return ['skipped' => true, 'reason' => OutreachConfig::enabled() ? 'mode_off' : 'master_flag_off', 'ambiguous' => 0, 'expiredJobs' => 0, 'expiredEnrollments' => 0, 'canceledByChain' => 0];
        }
        $modo = OutreachConfig::mode();
        $run = $this->runs->open('reconcile', $modo, $ahora);
        $r = ['skipped' => false, 'ambiguous' => 0, 'expiredJobs' => 0, 'expiredEnrollments' => 0, 'canceledByChain' => 0, 'run' => $run];
        try {
            $r['ambiguous'] = $this->staleToAmbiguous($modo, $ahora);
            $r['expiredJobs'] = $this->expirePlanned($modo, $ahora);
            $r['canceledByChain'] = $this->cancelOrphanedByChain($modo, $ahora, $run);
            $r['expiredEnrollments'] = $this->expireWindows($modo, $ahora);
            $this->runs->complete($run, $ahora, ['ambiguous' => $r['ambiguous'], 'expiredJobs' => $r['expiredJobs'], 'expiredEnrollments' => $r['expiredEnrollments'], 'canceledByChain' => $r['canceledByChain']]);
        } catch (Throwable $e) {
            $this->runs->fail($run, $ahora, ['ambiguous' => $r['ambiguous'], 'expiredJobs' => $r['expiredJobs'], 'expiredEnrollments' => $r['expiredEnrollments'], 'canceledByChain' => $r['canceledByChain']], OutreachRuns::errorCode($e));

            throw $e;
        }

        return $r;
    }

    private function staleToAmbiguous(string $modo, DateTimeImmutable $ahora): int
    {
        $limite = OutreachStore::sql($ahora->modify('-'.OutreachConfig::staleMinutes().' minutes'));
        $n = 0;
        $filas = DB::table(OutreachTables::JOBS)->where('mode', $modo)->whereIn('status', ['claimed', 'dispatching'])->where('status_changed_at', '<=', $limite)->orderBy('id')->get();
        foreach ($filas as $trabajo) {
            if (! $this->store->transitionJob((int) $trabajo->id, ['claimed', 'dispatching'], 'ambiguous', $ahora)) {
                continue;
            }
            $n++;
            $e = $this->store->enrollment((int) $trabajo->enrollment_id);
            if ($e !== null) {
                $this->outcomes->record($e, 'ambiguous', 'system', 'job:'.$trabajo->uuid, $ahora, ['job' => $this->store->job((int) $trabajo->id), 'detailCode' => 'stale']);
            }
            $this->incident($trabajo);
        }

        return $n;
    }

    private function expirePlanned(string $modo, DateTimeImmutable $ahora): int
    {
        $n = 0;
        $filas = DB::table(OutreachTables::JOBS.' as j')->join(OutreachTables::STRATEGIES.' as s', 's.id', '=', 'j.strategy_id')
            ->where('j.mode', $modo)->where('j.status', 'planned')->where('j.expires_at', '<=', OutreachStore::sql($ahora))->orderBy('j.id')->get(['j.*', 's.uuid as strategy_uuid']);
        foreach ($filas as $trabajo) {
            if (! $this->store->transitionJob((int) $trabajo->id, ['planned'], 'expired', $ahora)) {
                continue;
            }
            $n++;
            $this->release($trabajo, (string) $trabajo->strategy_uuid, $ahora);
            // La inscripción vuelve a tocar: el planificador decide otra vez (otro intento, otra fila).
            $this->store->updateOpenEnrollment((int) $trabajo->enrollment_id, ['next_due_at' => OutreachStore::sql($ahora)], $ahora);
        }

        return $n;
    }

    /**
     * D9-8 y #25 (REV9 M-4 y M-5, L9-66 y L9-69): las inscripciones ABIERTAS cuya versión/parte ya no está
     * viva en la cadena (reversión, retirada, promoción de la parte canario) o cuyo contenido no se puede
     * releer se cierran `canceled` (`strategy_moved` / `catalog_incompatible`) aunque esperen la ventana
     * sin `next_due_at`, que el barrido no mira. Una entrada que NO VERIFICA no cancela nada: N5 niega y el
     * monitor frena; el estado se conserva hasta que la cadena vuelva a leerse. Las que tienen un trabajo
     * vivo las cierra el despachador al reclamarlo. La pasada normal solo lee los pares (versión, parte)
     * con inscripciones abiertas (índice `oenr_version_idx`) y trae filas únicamente de los huérfanos.
     * Con la cadena ilegible por completo la excepción sube y la pasada falla en voz alta. Cada cancelación
     * deja su decisión `cancel` (N5, con el motivo y esta pasada) para el historial de la persona (REV9 m-9).
     */
    private function cancelOrphanedByChain(string $modo, DateTimeImmutable $ahora, int $runId): int
    {
        $vivas = [];
        foreach ($this->bridge->liveStrategies() as $l) {
            $vivas[$l->versionId.':'.$l->part] = $l;
        }
        $ilegibles = $this->bridge->unverifiedStrategyIds();
        $n = 0;
        $pares = DB::table(OutreachTables::ENROLLMENTS)->where('mode', $modo)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)
            ->groupBy('strategy_id', 'strategy_version_id', 'part')->orderBy('strategy_version_id')->orderBy('part')->get(['strategy_id', 'strategy_version_id', 'part']);
        foreach ($pares as $par) {
            $live = $vivas[$par->strategy_version_id.':'.$par->part] ?? null;
            if ($live === null && in_array((int) $par->strategy_id, $ilegibles, true)) {
                // El par falta porque la cabeza de ESA estrategia no verifica (REV9 m-10): no es una huérfana, es una cadena ilegible; se espera.
                continue;
            }
            $motivo = $live === null ? 'strategy_moved' : $live->orphanReason();
            if ($motivo === null) {
                continue;
            }
            $abiertas = DB::table(OutreachTables::ENROLLMENTS)->where('mode', $modo)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)
                ->where('strategy_version_id', (int) $par->strategy_version_id)->where('part', (string) $par->part)->orderBy('id')->get();
            foreach ($abiertas as $e) {
                $conTrabajoVivo = false;
                foreach ($this->store->liveJobsOf((string) $e->subject_key, $modo) as $trabajo) {
                    if ((int) $trabajo->enrollment_id === (int) $e->id) {
                        $conTrabajoVivo = true;
                        break;
                    }
                }
                if ($conTrabajoVivo) {
                    continue;
                }
                if ($this->cancelWithDecision($e, $motivo, $ahora, $runId)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * Cierra la inscripción `canceled` con su motivo y escribe la decisión
     * `cancel` (N5) que lo explica, las dos o ninguna, como el despachador
     * (REV9 m-9: sin ella la persona desaparece de su historial sin
     * explicación). Si otro proceso ya la cerró, nada.
     */
    private function cancelWithDecision(object $e, string $motivo, DateTimeImmutable $ahora, int $runId): bool
    {
        return DB::transaction(function () use ($e, $motivo, $ahora, $runId): bool {
            if (! $this->store->closeEnrollment((int) $e->id, 'canceled', $motivo, $ahora)) {
                return false;
            }
            $vacia = FactSnapshot::fromArray(['facts' => []]);
            $traza = DecisionTrace::build(['kind' => 'cancel', 'level' => 'N5', 'reasons' => [$motivo], 'retryAt' => null], $vacia, $ahora, [], ['gate' => ['recheck' => 'reconcile']]);
            $this->store->insertDecision(OutreachStore::sealOf($e), (string) $e->subject_key, (string) $e->subject_ref, 'cancel', 'N5', [$motivo], null, $vacia, $traza, $ahora, $runId, (int) $e->id);

            return true;
        });
    }

    private function expireWindows(string $modo, DateTimeImmutable $ahora): int
    {
        $n = 0;
        $vencidas = DB::table(OutreachTables::ENROLLMENTS)->where('mode', $modo)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)
            ->where('window_ends_at', '<=', OutreachStore::sql($ahora))->orderBy('id')->get();
        foreach ($vencidas as $e) {
            foreach ($this->store->liveJobsOf((string) $e->subject_key, $modo) as $trabajo) {
                if ((int) $trabajo->enrollment_id === (int) $e->id && $this->store->transitionJob((int) $trabajo->id, ['planned', 'claimed'], 'expired', $ahora)) {
                    $uuid = (string) DB::table(OutreachTables::STRATEGIES)->where('id', (int) $trabajo->strategy_id)->value('uuid');
                    $this->release($trabajo, $uuid, $ahora);
                }
            }
            $this->outcomes->record($e, 'no_response', 'system', 'window:'.$e->id, $ahora);
            $abierta = $this->store->enrollment((int) $e->id);
            if ($abierta !== null && in_array((string) $abierta->status, OutreachTables::ENROLLMENT_OPEN_STATUSES, true)) {
                $this->store->closeEnrollment((int) $e->id, 'expired', 'enrollment_window_over', $ahora, 'no_response');
            }
            $n++;
        }

        return $n;
    }

    private function release(object $trabajo, string $uuid, DateTimeImmutable $ahora): void
    {
        if ((int) $trabajo->reserved_units === 0 && (int) $trabajo->reserved_micros === 0) {
            return;
        }
        $this->budgets->release((string) $trabajo->budget_day, (string) $trabajo->mode, BudgetReservations::parcelsOfJob($trabajo, $uuid), $ahora);
        $this->store->markBudgetReleased((int) $trabajo->id, $ahora);
    }

    private function incident(object $trabajo): void
    {
        try {
            app(IncidentRecorder::class)->record([
                'source' => OutreachStop::INCIDENT_SOURCE,
                'kind' => self::INCIDENT_KIND,
                'fingerprint_keys' => [(string) $trabajo->channel],
                'title' => 'Un trabajo de la automatización comercial quedó a medias (reclamado sin ejecutar más de '.OutreachConfig::staleMinutes().' min): ambiguo, nadie lo reenvía solo.',
                'severity' => Incident::SEVERITY_HIGH,
                'evidence' => ['job' => (string) $trabajo->uuid, 'channel' => (string) $trabajo->channel, 'mode' => (string) $trabajo->mode, 'runbook' => 'backend/docs/ultron-studio/ULTRON_STUDIO_ROLLBACK.md'],
            ]);
        } catch (Throwable $e) {
            Log::error('outreach.reconcile.incident_failed', ['job' => (string) $trabajo->uuid, 'error_class' => $e::class]);
        }
    }
}
