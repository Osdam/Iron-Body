<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Cita comercial con un lead de marketing (Fase 4B). Vinculable a lead y/o
 * conversación del Inbox. No se borra físicamente: se cancela (status).
 */
class MarketingAppointment extends Model
{
    public const TYPE_VISIT = 'visit';
    public const TYPE_CALL = 'call';
    public const TYPE_ASSESSMENT = 'assessment';
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_VISIT, self::TYPE_CALL, self::TYPE_ASSESSMENT,
        self::TYPE_FOLLOW_UP, self::TYPE_OTHER,
    ];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUSES = [
        self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW, self::STATUS_RESCHEDULED,
    ];

    protected $fillable = [
        'uuid', 'marketing_lead_id', 'marketing_conversation_id',
        'assigned_to_admin_id', 'created_by_admin_id',
        'type', 'status', 'title', 'notes',
        'scheduled_at', 'duration_minutes', 'location',
        'contact_phone', 'contact_name',
        'reminder_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'reminder_at'      => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'duration_minutes' => 'integer',
        'metadata'         => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingAppointment $appointment): void {
            $appointment->uuid ??= (string) Str::uuid();
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'marketing_conversation_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_to_admin_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
