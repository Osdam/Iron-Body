<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un hecho comercial que Laravel le cuenta a ULTRON.
 *
 * Es un outbox, no una cola de trabajo: la fila se escribe primero y el envío
 * se intenta después. Si n8n está caído, el mensaje del prospecto no se pierde
 * —sigue en el Inbox— y el evento queda `failed` con su motivo, a la vista.
 */
class MarketingAutomationEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** El flag estaba apagado: ni se intentó. No es un fallo. */
    public const STATUS_SKIPPED = 'skipped';

    public const TYPE_MESSAGE_RECEIVED = 'marketing.message.received';

    /**
     * Cómo cerró el decide el turno cuando lo cerró sin commit (`decide_outcome`):
     * lo cortó la pausa del agente o lo relevó un mensaje más nuevo. Solo lo
     * escribe el decide; el job no lo toca. Lo lee el vigía de turnos.
     */
    public const OUTCOME_AGENT_PAUSED = 'agent_paused';

    public const OUTCOME_SUPERSEDED = 'superseded';

    protected $fillable = [
        'event_type', 'lead_id', 'conversation_id', 'message_id',
        'payload_json', 'status', 'idempotency_key', 'attempts',
        'last_error', 'correlation_id', 'processed_at', 'decide_outcome',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }
}
