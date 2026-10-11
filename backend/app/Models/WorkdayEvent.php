<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Historial INMUTABLE de una jornada: entrada, salida, incidencia, corrección.
 * Una fila no se edita ni se borra: una corrección es un evento nuevo.
 */
class WorkdayEvent extends Model
{
    public const UPDATED_AT = null;

    public const CLOCK_IN = 'clock_in';

    public const CLOCK_OUT = 'clock_out';

    public const INCIDENT_FLAGGED = 'incident_flagged';

    public const INCIDENT_RESOLVED = 'incident_resolved';

    public const ACTOR_EMPLOYEE = 'employee';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'uuid', 'workday_id', 'identity_id', 'type', 'occurred_at', 'method', 'face_distance',
        'authorized_device_id', 'device_verified', 'actor_type', 'actor_id', 'actor_name',
        'operation_id', 'ip_address', 'user_agent', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'device_verified' => 'boolean',
            'face_distance' => 'float',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkdayEvent $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('El historial de jornada es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de jornada es inmutable.'));
    }

    public function workday(): BelongsTo
    {
        return $this->belongsTo(Workday::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AuthorizedDevice::class, 'authorized_device_id');
    }
}
