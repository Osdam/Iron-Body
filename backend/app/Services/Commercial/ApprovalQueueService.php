<?php

namespace App\Services\Commercial;

use App\Models\Admin;
use App\Models\CommercialApproval;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La bandeja donde muere la autonomía.
 *
 * Todo lo que devuelve dinero, corrige un documento fiscal o fusiona dos fichas
 * de personas pasa por aquí y espera a que alguien lo mire. No es una capa de
 * cortesía: es la diferencia entre un sistema que opera y uno que decide por su
 * cuenta reembolsar.
 *
 * Las tres reglas que sostienen la bandeja, y por qué:
 *
 *  · **Se decide UNA vez.** La transición se hace con la fila bloqueada y
 *    comprobando el estado dentro del bloqueo. Dos supervisores pulsando
 *    aprobar a la vez —o el mismo dos veces porque no vio el spinner— es el
 *    caso normal, no el raro.
 *
 *  · **Caducada es caducada aunque nadie la haya marcado.** Se calcula sobre la
 *    fecha, no sobre una columna que actualiza un job: si ese job se para una
 *    noche, una autorización vencida no puede volverse aprobable.
 *
 *  · **Aprobar no es ejecutar.** Son dos momentos y dos marcas de tiempo.
 *    Confundirlos impide distinguir «lo autorizaron y falló» de «nadie lo
 *    miró», que es justo lo que hay que saber cuando un cliente reclama.
 */
class ApprovalQueueService
{
    /** Horas que vive una autorización sin que nadie la mire. */
    private const DEFAULT_TTL_HOURS = 72;

    /**
     * Pide autorización para una operación excepcional.
     *
     * Idempotente por `idempotencyKey`: el mismo hecho pedido dos veces —un
     * reintento del agente, un webhook repetido— no abre dos solicitudes que
     * después alguien aprobaría por separado.
     */
    public function request(
        string $type,
        string $justification,
        string $idempotencyKey,
        array $context = [],
    ): CommercialApproval {
        if (! in_array($type, CommercialApproval::TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de aprobación desconocido: {$type}");
        }

        $existing = CommercialApproval::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $approval = CommercialApproval::create([
                'type' => $type,
                'status' => CommercialApproval::STATUS_PENDING,
                'marketing_lead_id' => $context['lead_id'] ?? null,
                'member_id' => $context['member_id'] ?? null,
                'marketing_conversation_id' => $context['conversation_id'] ?? null,
                'requested_by' => $context['requested_by'] ?? 'agent',
                'requested_by_admin_id' => $context['requested_by_admin_id'] ?? null,
                'amount' => $context['amount'] ?? null,
                'currency' => isset($context['amount']) ? ($context['currency'] ?? 'COP') : null,
                'justification' => $justification,
                'evidence' => $context['evidence'] ?? null,
                'risk' => $context['risk'] ?? $this->defaultRiskFor($type),
                'impact' => $context['impact'] ?? null,
                'expires_at' => now()->addHours((int) ($context['ttl_hours'] ?? self::DEFAULT_TTL_HOURS)),
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $context['correlation_id'] ?? null,
            ]);
        } catch (QueryException $e) {
            // Carrera: dos procesos pidieron lo mismo a la vez. Gana el primero
            // y el segundo recibe esa misma solicitud, no una nueva.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return CommercialApproval::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        ChannelLog::info('approval.requested', [
            'approval_id' => $approval->id,
            'type' => $type,
            'risk' => $approval->risk,
            'has_amount' => $approval->amount !== null,
        ]);

        return $approval;
    }

    /**
     * Autoriza. Devuelve el resultado, no lanza.
     *
     * @return array{ok:bool,code:?string,approval:CommercialApproval}
     */
    public function approve(CommercialApproval $approval, Admin $admin, ?string $comment = null): array
    {
        return $this->decide($approval, $admin, CommercialApproval::STATUS_APPROVED, $comment);
    }

    /** @return array{ok:bool,code:?string,approval:CommercialApproval} */
    public function reject(CommercialApproval $approval, Admin $admin, ?string $comment = null): array
    {
        return $this->decide($approval, $admin, CommercialApproval::STATUS_REJECTED, $comment);
    }

    /**
     * Ni sí ni no: falta algo.
     *
     * Sigue abierta a propósito. Rechazarla obligaría a crear otra solicitud
     * desde cero y se perdería el hilo de por qué se pidió.
     */
    public function requestChanges(CommercialApproval $approval, Admin $admin, string $comment): array
    {
        return $this->decide($approval, $admin, CommercialApproval::STATUS_CHANGES_REQUESTED, $comment);
    }

    public function cancel(CommercialApproval $approval, Admin $admin, ?string $comment = null): array
    {
        return $this->decide($approval, $admin, CommercialApproval::STATUS_CANCELLED, $comment);
    }

    /**
     * La transición, con la fila bloqueada.
     *
     * El bloqueo no es defensivo de más: la bandeja la miran varias personas a
     * la vez y el botón de aprobar está a un clic. Comprobar el estado FUERA
     * del bloqueo deja una ventana entre la comprobación y la escritura por la
     * que caben dos aprobaciones de la misma solicitud.
     *
     * @return array{ok:bool,code:?string,approval:CommercialApproval}
     */
    private function decide(
        CommercialApproval $approval,
        Admin $admin,
        string $status,
        ?string $comment,
    ): array {
        return DB::transaction(function () use ($approval, $admin, $status, $comment) {
            $fresh = CommercialApproval::query()
                ->where('id', $approval->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return ['ok' => false, 'code' => 'not_found', 'approval' => $approval];
            }

            // Ya ejecutada: no se toca. Ni para rechazarla, ni para comentarla.
            // Lo que ya ocurrió no se puede desautorizar cambiando una fila.
            if ($fresh->status === CommercialApproval::STATUS_EXECUTED) {
                return ['ok' => false, 'code' => 'already_executed', 'approval' => $fresh];
            }

            /*
             * Solo se decide sobre lo que sigue ABIERTO.
             *
             * Comprobar `isTerminal()` no bastaba: `approved` no es terminal
             * -queda pendiente de ejecutarse- pero tampoco se puede volver a
             * decidir. Con esa comprobacion, dos supervisores podian aprobar
             * la misma solicitud uno detras de otro y el segundo se llevaba la
             * autoria de una decision que ya estaba tomada.
             */
            if (! in_array($fresh->status, CommercialApproval::OPEN_STATUSES, true)) {
                return ['ok' => false, 'code' => 'already_decided', 'approval' => $fresh];
            }

            if ($fresh->hasExpired()) {
                // Se deja constancia del vencimiento en la propia fila, para
                // que la bandeja no siga enseñándola como pendiente.
                $fresh->forceFill(['status' => CommercialApproval::STATUS_EXPIRED])->save();

                return ['ok' => false, 'code' => 'expired', 'approval' => $fresh];
            }

            $fresh->forceFill([
                'status' => $status,
                'decided_by_admin_id' => $admin->id,
                'decided_at' => now(),
                'decision_comment' => $comment,
            ])->save();

            ChannelLog::info('approval.decided', [
                'approval_id' => $fresh->id,
                'type' => $fresh->type,
                'status' => $status,
                'admin_id' => $admin->id,
            ]);

            return ['ok' => true, 'code' => null, 'approval' => $fresh->fresh()];
        });
    }

    /**
     * Marca una autorización como ejecutada.
     *
     * Solo desde `approved`, y una sola vez. Es el segundo cerrojo: aunque dos
     * procesos crean que les toca ejecutar, únicamente el que gane el bloqueo
     * escribe, y el otro se entera de que ya está hecho.
     *
     * @return array{ok:bool,code:?string,approval:CommercialApproval}
     */
    public function markExecuted(CommercialApproval $approval, ?string $result = null): array
    {
        return DB::transaction(function () use ($approval, $result) {
            $fresh = CommercialApproval::query()
                ->where('id', $approval->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return ['ok' => false, 'code' => 'not_found', 'approval' => $approval];
            }

            if ($fresh->status === CommercialApproval::STATUS_EXECUTED) {
                return ['ok' => false, 'code' => 'already_executed', 'approval' => $fresh];
            }

            if ($fresh->status !== CommercialApproval::STATUS_APPROVED) {
                return ['ok' => false, 'code' => 'not_approved', 'approval' => $fresh];
            }

            /*
             * Una autorización con fecha caduca aunque ya estuviera aprobada.
             *
             * `decide()` comprobaba el vencimiento y aquí no, y esa asimetría
             * abría el hueco entero: basta con que el ejecutor tarde —una cola
             * cargada, un reintento con backoff, un worker caído— para que la
             * acción se ejecute pasada la fecha. El permiso existía cuando se
             * pidió; ejecutarlo tarde es actuar sin él.
             */
            if ($fresh->isPastDeadline()) {
                $fresh->forceFill(['status' => CommercialApproval::STATUS_EXPIRED])->save();

                ChannelLog::warning('approval.execution_blocked_expired', [
                    'approval_id' => $fresh->id,
                    'type' => $fresh->type,
                    'expired_at' => $fresh->expires_at?->toIso8601String(),
                ]);

                return ['ok' => false, 'code' => 'expired', 'approval' => $fresh];
            }

            $fresh->forceFill([
                'status' => CommercialApproval::STATUS_EXECUTED,
                'executed_at' => now(),
                'execution_result' => $result,
            ])->save();

            return ['ok' => true, 'code' => null, 'approval' => $fresh->fresh()];
        });
    }

    /** La ejecución falló. Queda a la vista con el motivo, no desaparece. */
    public function markFailed(CommercialApproval $approval, string $reason): CommercialApproval
    {
        $approval->forceFill([
            'status' => CommercialApproval::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ])->save();

        ChannelLog::warning('approval.execution_failed', [
            'approval_id' => $approval->id,
            'type' => $approval->type,
        ]);

        return $approval->fresh();
    }

    /**
     * Cierra las que vencieron sin que nadie las mirara.
     *
     * Lo llama el planificador. No es imprescindible para la corrección —el
     * modelo ya considera caducada una fila vencida— pero mantiene la bandeja
     * limpia y hace visible cuántas se quedaron sin mirar.
     */
    public function expireStale(): int
    {
        $stale = CommercialApproval::query()
            ->whereIn('status', CommercialApproval::OPEN_STATUSES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $approval) {
            $approval->forceFill(['status' => CommercialApproval::STATUS_EXPIRED])->save();
        }

        if ($stale->isNotEmpty()) {
            ChannelLog::info('approval.expired_batch', ['count' => $stale->count()]);
        }

        return $stale->count();
    }

    /** Clave de idempotencia sugerida para una operación sobre un sujeto. */
    public static function keyFor(string $type, ?int $leadId, ?int $memberId, ?string $discriminator = null): string
    {
        return implode(':', array_filter([
            'approval', $type,
            $leadId !== null ? "lead:{$leadId}" : null,
            $memberId !== null ? "member:{$memberId}" : null,
            $discriminator ?? Str::random(8),
        ]));
    }

    /**
     * Riesgo por defecto según lo que se pide.
     *
     * Devolver dinero y tocar documentos fiscales son altos porque revertirlos
     * exige papeleo con la DIAN; una campaña masiva es alta porque llega a
     * mucha gente a la vez y no se puede recoger.
     */
    private function defaultRiskFor(string $type): string
    {
        return match ($type) {
            CommercialApproval::TYPE_REFUND,
            CommercialApproval::TYPE_CREDIT_NOTE,
            CommercialApproval::TYPE_FISCAL_CORRECTION,
            CommercialApproval::TYPE_IDENTITY_MERGE,
            CommercialApproval::TYPE_BULK_CAMPAIGN,
            CommercialApproval::TYPE_EXTRAORDINARY_FINANCIAL => 'high',

            CommercialApproval::TYPE_DISCOUNT,
            CommercialApproval::TYPE_EXCEPTIONAL_PROMO,
            CommercialApproval::TYPE_OFF_CATALOG_BENEFIT => 'medium',

            default => 'low',
        };
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true);
    }
}
