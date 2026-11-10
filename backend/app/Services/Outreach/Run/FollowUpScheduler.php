<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\FollowUpMachine;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;

/**
 * LOS SEGUIMIENTOS (contrato H9, D9-8 etapa 9, D9-10; T23): aplica a una
 * inscripción lo que dice la máquina PURA (`FollowUpMachine`) sobre un
 * resultado YA resuelto (`OutcomeTable::resolve()`). Solo si la regla del
 * paso lo permite; la máquina nunca acorta un intervalo mínimo.
 *
 * Lo que escribe: el estado, el paso y `next_due_at` de la inscripción (el
 * planificador crea el trabajo siguiente cuando toque: un reintento de
 * negocio es una fila nueva, intento + 1) o su cierre con el motivo. Un
 * `blocked` que espera (`wait`) se vuelve a mirar como pronto en
 * {@see BLOCKED_RECHECK_HOURS}: así una negativa permanente no gasta una
 * decisión por pasada y una autorización que llegue después (C9) sí se
 * aprovecha dentro de la ventana.
 */
final class FollowUpScheduler
{
    public const BLOCKED_RECHECK_HOURS = 24;

    public function __construct(private readonly OutreachStore $store = new OutreachStore) {}

    /**
     * Lo que la máquina decide, SIN escribir (para componer la fila del
     * resultado antes de insertarla).
     *
     * @param  array<string, mixed>|null  $content  el contenido de la versión (sus pasos y límites)
     * @param  array{verdict: string, outcome: string|null, transition: string|null, enrollmentStatus: string|null}  $resolution
     * @param  array{callbackAt?: string|null}  $extras
     * @return array{status: string, stepIndex: int, attempt: int, nextJob: array{stepIndex: int, attempt: int, notBefore: string}|null, reason: string}
     */
    public function decide(object $enrollment, ?array $content, array $resolution, DateTimeImmutable $now, array $extras = []): array
    {
        $pasos = self::machineSteps($content);
        $indice = (int) $enrollment->step_index;
        if (! isset($pasos[$indice])) {
            // Sin secuencia legible no hay seguimiento: lo que diga la transición y nada más.
            $estado = $resolution['enrollmentStatus'] ?? (string) $enrollment->status;

            return ['status' => in_array($resolution['transition'] ?? null, [OutcomeTable::NEXT_STEP, OutcomeTable::RETRY_STEP, OutcomeTable::WAIT], true) ? 'waiting' : $estado,
                'stepIndex' => $indice, 'attempt' => max(1, $this->store->nextAttempt((int) $enrollment->id, $indice) - 1), 'nextJob' => null, 'reason' => 'sequence_unreadable'];
        }
        $paso = $content['sequence'][$indice] ?? [];
        $espera = is_array($paso['waitFor'] ?? null) ? $paso['waitFor'] : null;
        if ($espera === null && ($resolution['outcome'] ?? null) === 'blocked' && ($resolution['transition'] ?? null) === OutcomeTable::WAIT) {
            $espera = ['n' => self::BLOCKED_RECHECK_HOURS, 'unit' => 'hours'];
        }

        return FollowUpMachine::apply(
            [
                'status' => (string) $enrollment->status,
                'stepIndex' => $indice,
                'attempt' => max(1, $this->store->nextAttempt((int) $enrollment->id, $indice) - 1),
                'touches' => (int) $enrollment->touches,
                'enrolledAt' => RelativeTime::format(OutreachStore::instant($enrollment->enrolled_at)),
                'windowEndsAt' => RelativeTime::format(OutreachStore::instant($enrollment->window_ends_at)),
            ],
            $pasos,
            $resolution,
            $now,
            [
                'waitFor' => $espera,
                'callbackAt' => $extras['callbackAt'] ?? null,
                'maxTouches' => is_int($content['limits']['maxTouches'] ?? null) ? $content['limits']['maxTouches'] : FollowUpMachine::DEFAULT_MAX_TOUCHES,
                'ownHours' => OutreachConfig::ownHours(),
                'schedule' => is_array($content['schedule'] ?? null) ? $content['schedule'] : null,
            ],
        );
    }

    /**
     * Escribe lo decidido en la inscripción (abierta): el estado, el paso,
     * el último resultado y cuándo toca; o su cierre.
     *
     * @param  array{status: string, stepIndex: int, attempt: int, nextJob: array{stepIndex: int, attempt: int, notBefore: string}|null, reason: string}  $next
     */
    public function apply(object $enrollment, array $next, string $outcome, DateTimeImmutable $now, ?string $exitReason = null): void
    {
        if (in_array($next['status'], OutcomeTable::FINAL_STATUSES, true)) {
            $this->store->closeEnrollment((int) $enrollment->id, $next['status'], $exitReason ?? self::exitReasonFor($outcome, $next), $now, $outcome);

            return;
        }
        $cambios = ['last_outcome' => $outcome, 'step_index' => $next['stepIndex']];
        if ($next['status'] !== (string) $enrollment->status) {
            $cambios['status'] = $next['status'];
        }
        $cambios['next_due_at'] = $next['nextJob'] !== null ? OutreachStore::sql(RelativeTime::parseInstant($next['nextJob']['notBefore'])) : null;
        $this->store->updateOpenEnrollment((int) $enrollment->id, $cambios, $now);
    }

    /** El motivo de salida (código) que deja un cierre por resultado. */
    public static function exitReasonFor(string $outcome, array $next): string
    {
        return match ($next['reason'] ?? '') {
            'max_touches_reached' => 'max_touches_reached',
            default => $outcome,
        };
    }

    /**
     * Los pasos con la forma que pide la máquina.
     *
     * @param  array<string, mixed>|null  $content
     * @return list<array{id: string, delay: array{n: int, unit: string, from: string}}>
     */
    public static function machineSteps(?array $content): array
    {
        $pasos = [];
        foreach ($content['sequence'] ?? [] as $s) {
            if (is_array($s) && is_string($s['id'] ?? null) && is_array($s['delay'] ?? null)) {
                $pasos[] = ['id' => $s['id'], 'delay' => $s['delay']];
            }
        }

        return $pasos;
    }
}
