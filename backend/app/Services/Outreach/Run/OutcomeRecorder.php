<?php

namespace App\Services\Outreach\Run;

use App\Models\Admin;
use App\Models\Incident;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * LA INGESTA DE RESULTADOS (contrato H9, D9-8 etapa 8, D9-10; §13.1 #10, #22;
 * T14): normaliza lo que llega al conjunto cerrado de 18 resultados con la
 * tabla PURA (`OutcomeTable::resolve()`), lo registra UNA vez en
 * `outreach_outcomes` (solo inserción, `insertOrIgnore` sobre
 * `(enrollment, source, source_key)`: un duplicado de la misma fuente se
 * descarta sin avanzar la secuencia) y aplica el seguimiento que la regla
 * del paso permite ({@see FollowUpScheduler}) y los EFECTOS del resultado:
 *
 *  - `opted_out` → `end_exit` diga lo que diga la estrategia, cancelación de
 *    TODOS los trabajos pendientes de la persona en TODAS las estrategias (con
 *    su reserva liberada) y supresión por finalidad y canal (derivada del
 *    libro: {@see SubjectSuppressions});
 *  - `human_requested` y el veredicto `needs_review` → `handoff_human`: la
 *    secuencia para y la inscripción queda `handed_off`;
 *  - un código desconocido, una fuente que ese resultado no admite o una
 *    fuente fuera del conjunto → se IGNORA y queda un incidente (IRON GUARD,
 *    fuente `outreach`): nunca se guarda lo que no se sabe leer.
 *
 * Las fuentes del 15-11 son `simulated`, `staff`, `crm_fact` y `system`
 * (`OutcomeTable::SOURCES_AVAILABLE`); las demás las añaden W9, V9 y AI9.
 * Lo que no viene de `crm_fact` ni de `system` lleva su trabajo y el canal
 * de ese trabajo (DB9).
 */
final class OutcomeRecorder
{
    public const RESULT_RECORDED = 'recorded';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_IGNORED = 'ignored';

    public const RESULT_NEEDS_REVIEW = 'needs_review';

    public const INCIDENT_KIND = 'outreach_outcome_ignored';

    public function __construct(
        private readonly OutreachStore $store = new OutreachStore,
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly FollowUpScheduler $followUps = new FollowUpScheduler,
        private readonly BudgetReservations $budgets = new BudgetReservations,
    ) {}

    /**
     * Registra un resultado de una inscripción.
     *
     * `on` (opcional) fuerza la transición de un resultado DEL SISTEMA dentro
     * de las que la tabla admite (p. ej. un `blocked` por `context_changed`
     * sale con `end_exit`, no espera); `exitReason` es el código de salida que
     * queda en la inscripción si se cierra (por defecto, el resultado).
     *
     * @param  array{job?: object|null, detailCode?: string|null, reasonCode?: string|null, confidence?: int|null, recordedBy?: Admin|null, occurredAt?: DateTimeInterface|null, callbackAt?: string|null, on?: array<string, string>, exitReason?: string|null}  $o
     * @return array{result: string, outcome: string|null, transition: string|null, next: array<string, mixed>|null, incident: bool}
     */
    public function record(object $enrollment, string $outcome, string $source, string $sourceKey, DateTimeInterface $now, array $o = []): array
    {
        $now = RelativeTime::utc($now);
        $job = $o['job'] ?? null;
        $contenido = $this->bridge->contentOfVersion((int) $enrollment->strategy_version_id);
        $paso = $contenido['sequence'][(int) $enrollment->step_index] ?? null;
        $on = is_array($paso['on'] ?? null) ? $paso['on'] : null;
        if (is_array($o['on'] ?? null)) {
            $on = $o['on'] + ($on ?? []);
        }
        $intento = $job !== null ? (int) $job->attempt : max(1, $this->store->nextAttempt((int) $enrollment->id, (int) $enrollment->step_index) - 1);
        $confianza = $o['confidence'] ?? null;

        $resolucion = OutcomeTable::resolve($outcome, $source, $confianza, $on, $intento);
        if ($resolucion['verdict'] === OutcomeTable::VERDICT_IGNORE) {
            $this->incident($enrollment, $outcome, $source, $sourceKey);

            return ['result' => self::RESULT_IGNORED, 'outcome' => null, 'transition' => null, 'next' => null, 'incident' => true];
        }
        $revision = $resolucion['verdict'] === OutcomeTable::VERDICT_NEEDS_REVIEW;
        // Un veredicto `needs_review` no es un resultado del conjunto cerrado (L9-12): la secuencia para y se deriva a una persona.
        // Se guarda como `human_requested` (la derivación, que el clasificador sí admite) con el detalle `needs_review`: un `ambiguous`
        // del clasificador no cabe en la base (no tiene transición y el clasificador tiene que frenar o derivar, P9-6).
        $guardado = $revision ? 'human_requested' : (string) $resolucion['outcome'];
        $siguiente = $this->followUps->decide($enrollment, $contenido, $resolucion, $now, ['callbackAt' => $o['callbackAt'] ?? null]);
        $accion = $resolucion['transition'];
        $cuando = $siguiente['nextJob'] !== null && in_array($accion, [OutcomeTable::NEXT_STEP, OutcomeTable::RETRY_STEP, OutcomeTable::WAIT], true)
            ? OutreachStore::sql(RelativeTime::parseInstant($siguiente['nextJob']['notBefore'])) : null;
        $ocurrio = isset($o['occurredAt']) ? RelativeTime::utc($o['occurredAt']) : $now;
        $persona = $o['recordedBy'] ?? null;

        $fila = [
            'uuid' => (string) Str::uuid(),
            'job_id' => $job?->id,
            'enrollment_id' => (int) $enrollment->id,
            'strategy_id' => (int) $enrollment->strategy_id,
            'subject_key' => (string) $enrollment->subject_key,
            'subject_ref' => (string) $enrollment->subject_ref,
            'channel' => $job?->channel,
            'outcome' => $guardado,
            'source' => $source,
            'source_key' => substr($sourceKey, 0, 96),
            'deterministic' => $source !== OutcomeTable::SOURCE_CLASSIFIER,
            // El clasificador es el único probabilístico (P9-6): su confianza (0–100 entera) va como fracción; sin ella, 0.
            'confidence' => $source === OutcomeTable::SOURCE_CLASSIFIER ? (is_int($confianza) ? round(max(0, min(100, $confianza)) / 100, 3) : 0.0) : null,
            'detail_code' => $revision ? 'needs_review' : ($o['detailCode'] ?? null),
            'reason_code' => $o['reasonCode'] ?? null,
            'next_action' => $revision ? OutcomeTable::HANDOFF_HUMAN : $accion,
            'next_action_at' => $revision ? null : $cuando,
            'recorded_by_admin_id' => $persona?->id,
            'recorded_by_name' => $persona !== null ? mb_substr((string) $persona->name, 0, 120) : null,
            'occurred_at' => OutreachStore::sql($ocurrio),
            'created_at' => OutreachStore::sql($now),
        ];

        $salida = is_string($o['exitReason'] ?? null) ? $o['exitReason'] : ($guardado === OutcomeTable::OPTED_OUT ? 'opted_out' : null);

        return DB::transaction(function () use ($fila, $enrollment, $guardado, $siguiente, $resolucion, $revision, $now, $accion, $salida): array {
            $insertadas = DB::table(OutreachTables::OUTCOMES)->insertOrIgnore($fila);
            if ($insertadas !== 1) {
                // El mismo resultado de la misma fuente ya estaba: se descarta sin avanzar nada (T14).
                return ['result' => self::RESULT_DUPLICATE, 'outcome' => $guardado, 'transition' => null, 'next' => null, 'incident' => false];
            }
            if ($revision) {
                $this->store->closeEnrollment((int) $enrollment->id, 'handed_off', 'needs_review', $now, $guardado);
                $this->cancelLiveJobsOf($enrollment, 'needs_review', $now, false);

                return ['result' => self::RESULT_NEEDS_REVIEW, 'outcome' => $guardado, 'transition' => OutcomeTable::HANDOFF_HUMAN, 'next' => $siguiente, 'incident' => false];
            }
            $this->followUps->apply($enrollment, $siguiente, $guardado, $now, $salida);
            foreach ($resolucion['effects'] as $efecto) {
                $this->effect($efecto, $enrollment, $now);
            }

            return ['result' => self::RESULT_RECORDED, 'outcome' => $guardado, 'transition' => $accion, 'next' => $siguiente, 'incident' => $resolucion['incident']];
        });
    }

    /** `cancel_pending_all_strategies` (D9-10 `opted_out`, §13.1 #10): nada pendiente sale, en ninguna estrategia. */
    private function effect(string $effect, object $enrollment, DateTimeImmutable $now): void
    {
        if ($effect !== 'cancel_pending_all_strategies') {
            // `suppress_*` se deriva del libro ({@see SubjectSuppressions}); `cooldown` lo aplica el enfriamiento de la estrategia;
            // `assisted_task` lo abre el canal asistido cuando la estrategia lo pida (Q para el lead); `reconcile` es de la conciliación.
            return;
        }
        $this->cancelLiveJobsOf($enrollment, 'opted_out', $now, true);
    }

    /** Cancela los trabajos vivos de la persona (en su modo) y, si se pide, cierra sus otras inscripciones abiertas. */
    private function cancelLiveJobsOf(object $enrollment, string $reason, DateTimeImmutable $now, bool $closeOthers): void
    {
        $modo = (string) $enrollment->mode;
        $clave = (string) $enrollment->subject_key;
        foreach ($this->store->liveJobsOf($clave, $modo) as $trabajo) {
            if ($this->store->transitionJob((int) $trabajo->id, ['planned', 'claimed'], 'canceled', $now, ['cancel_reason' => $reason])) {
                $this->releaseReservation($trabajo, $now);
            }
        }
        if ($closeOthers) {
            foreach ($this->store->openEnrollmentsOf($clave, $modo) as $otra) {
                if ((int) $otra->id !== (int) $enrollment->id) {
                    $this->store->closeEnrollment((int) $otra->id, 'exited', $reason, $now);
                }
            }
        }
    }

    private function releaseReservation(object $job, DateTimeImmutable $now): void
    {
        if ((int) $job->reserved_units === 0 && (int) $job->reserved_micros === 0) {
            return;
        }
        $uuid = DB::table(OutreachTables::STRATEGIES)->where('id', (int) $job->strategy_id)->value('uuid');
        $this->budgets->release((string) $job->budget_day, (string) $job->mode, BudgetReservations::parcelsOfJob($job, (string) $uuid), $now);
        $this->store->markBudgetReleased((int) $job->id, $now);
    }

    private function incident(object $enrollment, string $outcome, string $source, string $sourceKey): void
    {
        $codigo = preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $outcome) === 1 ? $outcome : 'unparseable';
        try {
            app(IncidentRecorder::class)->record([
                'source' => OutreachStop::INCIDENT_SOURCE,
                'kind' => self::INCIDENT_KIND,
                'fingerprint_keys' => [$source, $codigo],
                'title' => 'Resultado de la automatización comercial fuera del conjunto cerrado o de una fuente que no lo admite: se ignoró.',
                'severity' => Incident::SEVERITY_MEDIUM,
                'evidence' => ['outcome' => $codigo, 'source' => preg_match('/^[a-z_]{1,16}$/D', $source) === 1 ? $source : 'unknown', 'source_key' => substr($sourceKey, 0, 96), 'enrollment' => (string) $enrollment->uuid],
            ]);
        } catch (Throwable $e) {
            // El resultado ya se ignoró (lo que protege); el incidente es el aviso.
            Log::error('outreach.outcome.incident_failed', ['enrollment' => (string) $enrollment->uuid, 'error_class' => $e::class]);
        }
    }
}
