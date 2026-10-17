<?php

namespace App\Models;

use App\Services\Valuations\ValuationAgenda;
use App\Services\Valuations\ValuationTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una cita de valoración física entre un SOCIO (`members.id`) y un ENTRENADOR.
 *
 * Dominio propio, no la Agenda comercial (`marketing_appointments`, prospectos
 * y ULTRON). Las horas son instantes UTC; la de pared de Bogotá la calcula
 * {@see ValuationTime}.
 *
 * Toda escritura pasa por {@see ValuationAgenda}: crear,
 * mover, cancelar y cambiar de estado bloquean filas, suben `version`, dejan el
 * evento en el historial y replanifican los avisos. Un `save()` suelto desde
 * otro sitio se saltaría todo eso.
 */
class ValuationAppointment extends Model
{
    public const TYPE_INITIAL = 'initial';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_INITIAL, self::TYPE_FOLLOW_UP, self::TYPE_OTHER];

    public const TYPE_LABELS = [
        self::TYPE_INITIAL => 'Valoración inicial',
        self::TYPE_FOLLOW_UP => 'Seguimiento',
        self::TYPE_OTHER => 'Otra',
    ];

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED, self::STATUS_CONFIRMED, self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED, self::STATUS_NO_SHOW, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_SCHEDULED => 'Programada',
        self::STATUS_CONFIRMED => 'Confirmada',
        self::STATUS_IN_PROGRESS => 'En curso',
        self::STATUS_COMPLETED => 'Completada',
        self::STATUS_NO_SHOW => 'No asistió',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    /** Ocupan la agenda del entrenador y del socio (y la restricción EXCLUDE). */
    public const ACTIVE_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED, self::STATUS_IN_PROGRESS];

    /** Se pueden mover y cancelar. */
    public const OPEN_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED];

    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_NO_SHOW, self::STATUS_CANCELLED];

    public const SOURCE_CRM = 'crm';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'uuid', 'client_request_id', 'member_id', 'trainer_id', 'type', 'status',
        'starts_at', 'ends_at', 'duration_minutes', 'notes', 'cancellation_reason',
        'cancelled_at', 'cancelled_by_admin_id', 'confirmed_at', 'started_at',
        'completed_at', 'no_show_at', 'outcome_by_admin_id', 'professional_assessment_id',
        'source', 'version', 'created_by_admin_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show_at' => 'datetime',
            'duration_minutes' => 'integer',
            'version' => 'integer',
            'member_id' => 'integer',
            'trainer_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ValuationAppointment $a): void {
            $a->uuid ??= (string) Str::uuid();
            $a->version ??= 1;
            $a->source ??= self::SOURCE_CRM;
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ValuationAppointmentEvent::class, 'appointment_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ValuationReminder::class, 'appointment_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
