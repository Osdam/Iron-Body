<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Llamada comercial (cimiento de la futura integración Twilio Voice — Fase 6).
 * Hoy NO inicia llamadas reales: solo persiste intención/estado para que el
 * agente pueda programar "llamar en 2 horas" sin duplicar. La activación de
 * membresía NUNCA depende de una llamada.
 */
class MarketingCall extends Model
{
    public const STATUS_PENDING     = 'pending';
    public const STATUS_QUEUED      = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_NO_ANSWER   = 'no_answer';
    public const STATUS_CANCELED    = 'canceled';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND  = 'inbound';

    protected $fillable = [
        'marketing_lead_id', 'marketing_followup_id', 'provider', 'provider_call_sid',
        'to_phone', 'from_phone', 'status', 'direction', 'reason',
        'scheduled_at', 'started_at', 'ended_at', 'duration_seconds',
        'transcript', 'summary', 'outcome', 'metadata',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'duration_seconds' => 'integer',
        'metadata'         => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(MarketingFollowup::class, 'marketing_followup_id');
    }
}
