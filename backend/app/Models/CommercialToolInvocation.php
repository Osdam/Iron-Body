<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una ejecución concreta de una herramienta del agente.
 *
 * Es a la vez el acta de auditoría y la barrera de idempotencia: la fila existe
 * ANTES de que la herramienta salga a la red, así que un segundo intento con la
 * misma clave se encuentra con que el trabajo ya está reclamado.
 */
class CommercialToolInvocation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** Rechazada antes de ejecutarse: sin permiso, sin flag, o datos inválidos. */
    public const STATUS_REJECTED = 'rejected';

    /** No hacía falta hacer nada (ya estaba hecho). */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'uuid', 'tool', 'idempotency_key',
        'marketing_lead_id', 'member_id', 'commercial_opportunity_id', 'marketing_conversation_id',
        'requested_by', 'approved_by_admin_id',
        'goal', 'decision_action', 'reason',
        'arguments', 'result', 'status', 'error_code', 'error_message',
        'retryable', 'attempts', 'duration_ms',
        'correlation_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'retryable' => 'boolean',
        'attempts' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function opportunity()
    {
        return $this->belongsTo(CommercialOpportunity::class, 'commercial_opportunity_id');
    }

    /** ¿Terminó de una forma que permite volver a intentarlo? */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->retryable;
    }
}
