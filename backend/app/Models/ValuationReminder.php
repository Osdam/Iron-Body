<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbox de avisos de una cita de valoración. Una fila = un aviso para UNA
 * versión de la cita: reprogramar crea las de la versión nueva y cancela las
 * pendientes de la anterior. El procesador (`valuations:process-reminders`)
 * las reclama, comprueba que siguen valiendo y las envía.
 *
 * `accepted` = la notificación quedó persistida (y su push aceptado por el
 * proceso); NO es «entregado».
 */
class ValuationReminder extends Model
{
    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_REMINDER_LONG = 'reminder_long';

    public const KIND_REMINDER_SHORT = 'reminder_short';

    public const KIND_CANCELLATION = 'cancellation';

    public const KIND_RESCHEDULED = 'rescheduled';

    public const KIND_INTERNAL_UPCOMING = 'internal_upcoming';

    public const KIND_INTERNAL_UNATTENDED = 'internal_unattended';

    public const CHANNEL_MEMBER_APP = 'member_app';

    public const CHANNEL_CRM = 'crm';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Aún pueden enviarse: lo que reprogramar o cancelar deja sin efecto. */
    public const WAITING_STATUSES = [self::STATUS_PENDING, self::STATUS_ERROR];

    protected $fillable = [
        'appointment_id', 'kind', 'channel', 'appointment_version', 'due_at', 'status',
        'attempts', 'last_error', 'processed_at', 'provider_ref',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
            'appointment_version' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ValuationAppointment::class, 'appointment_id');
    }
}
