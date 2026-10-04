<?php

namespace App\Models;

use App\Services\Marketing\MarketingAgendaVersion;
use App\Services\Marketing\MarketingAppointmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Cita comercial con un lead de marketing (Fase 4B). Vinculable a lead y/o
 * conversación del Inbox. No se borra físicamente: se cancela (status).
 *
 * Es la ÚNICA fuente de la Agenda comercial, también de las visitas que pide
 * ULTRON por WhatsApp: nacen `requested` y una persona del equipo las confirma
 * (`scheduled`). Las transiciones válidas las decide
 * {@see MarketingAppointmentService}.
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

    /** Solicitada y SIN confirmar (p. ej. la cortesía que recoge ULTRON). No es una cita en firme. */
    public const STATUS_REQUESTED = 'requested';

    /** Confirmada: así la lee también ULTRON («te esperamos»). */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW, self::STATUS_RESCHEDULED,
    ];

    /** Vivas: todavía pueden confirmarse, moverse o cancelarse. `rescheduled` es heredado y cuenta como confirmada. */
    /** Una visita en firme: confirmada, o reprogramada (heredado: también estaba confirmada). */
    public const CONFIRMED_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_RESCHEDULED];

    public const ACTIVE_STATUSES = [self::STATUS_REQUESTED, self::STATUS_SCHEDULED, self::STATUS_RESCHEDULED];

    /** Cerradas: el historial no se reescribe. */
    public const CLOSED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW];

    public const SOURCE_ULTRON = 'ultron';

    public const SOURCE_CRM = 'crm';

    public const SOURCE_COMMERCIAL_TOOL = 'commercial_tool';

    protected $fillable = [
        'uuid', 'marketing_lead_id', 'marketing_conversation_id',
        'assigned_to_admin_id', 'created_by_admin_id',
        'type', 'status', 'title', 'notes',
        'scheduled_at', 'duration_minutes', 'location',
        'contact_phone', 'contact_name',
        'reminder_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'metadata', 'source',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingAppointment $appointment): void {
            $appointment->uuid ??= (string) Str::uuid();
        });

        // Cada escritura de una cita mueve la versión de la agenda que escucha
        // el canal SSE, venga del camino que venga (CRM, ULTRON, herramientas).
        static::saved(fn () => MarketingAgendaVersion::bump());
        static::deleted(fn () => MarketingAgendaVersion::bump());
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
