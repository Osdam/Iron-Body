<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una jornada laboral. Las horas son las del servidor (UTC); `business_date`
 * es el día del gimnasio de la entrada. Ver `create_workdays_tables`.
 */
class Workday extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_INCIDENT = 'incident';

    public const INCIDENT_MISSING_CLOCK_OUT = 'missing_clock_out';

    /** Desactivado con la jornada abierta: ya no puede registrar la salida. */
    public const INCIDENT_PROFILE_DEACTIVATED = 'profile_deactivated';

    public const INCIDENT_LABELS = [
        self::INCIDENT_MISSING_CLOCK_OUT => 'Salida no registrada',
        self::INCIDENT_PROFILE_DEACTIVATED => 'Desactivado con la jornada abierta',
    ];

    public const RESOLUTION_SET_CLOCK_OUT = 'set_clock_out';

    public const RESOLUTION_DISMISSED = 'dismissed';

    protected $fillable = [
        'uuid', 'identity_id', 'position', 'status', 'business_date',
        'clock_in_at', 'clock_in_method', 'clock_in_device_id',
        'clock_out_at', 'clock_out_method', 'clock_out_device_id', 'clock_out_source', 'duration_seconds',
        'incident_reason', 'incident_at', 'resolved_at', 'resolution',
        'resolved_by_admin_id', 'resolved_by_name', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'incident_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workday $workday): void {
            $workday->uuid ??= (string) Str::uuid();
        });
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkdayEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function clockInDevice(): BelongsTo
    {
        return $this->belongsTo(AuthorizedDevice::class, 'clock_in_device_id');
    }

    public function clockOutDevice(): BelongsTo
    {
        return $this->belongsTo(AuthorizedDevice::class, 'clock_out_device_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
