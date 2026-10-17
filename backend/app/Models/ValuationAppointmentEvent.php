<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Historial APPEND-ONLY de una cita de valoración: qué pasó, quién y por qué.
 *
 * Como `WorkdayEvent`: se crea y no se toca. Modificar o borrar una fila lanza,
 * para que ningún camino del código pueda reescribir lo que pasó. La única
 * baja es la de la base: al borrar al SOCIO, sus citas y su historial se van
 * con él en cascada (como sus valoraciones físicas).
 */
class ValuationAppointmentEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';

    public const ACTION_RESCHEDULED = 'rescheduled';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_CONFIRMED = 'confirmed';

    public const ACTION_STARTED = 'started';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_NO_SHOW = 'no_show';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_LABELS = [
        self::ACTION_CREATED => 'Agendada',
        self::ACTION_RESCHEDULED => 'Reprogramada',
        self::ACTION_UPDATED => 'Editada',
        self::ACTION_CONFIRMED => 'Confirmada',
        self::ACTION_STARTED => 'Iniciada',
        self::ACTION_COMPLETED => 'Completada',
        self::ACTION_NO_SHOW => 'No asistió',
        self::ACTION_CANCELLED => 'Cancelada',
        self::ACTION_IMPORTED => 'Importada',
    ];

    protected $fillable = [
        'appointment_id', 'action', 'actor_admin_id', 'actor_name', 'from_status', 'to_status',
        'from_starts_at', 'to_starts_at', 'from_trainer_id', 'to_trainer_id', 'reason', 'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_starts_at' => 'datetime',
            'to_starts_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de una cita de valoración es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de una cita de valoración es inmutable.'));
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ValuationAppointment::class, 'appointment_id');
    }
}
