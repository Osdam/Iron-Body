<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Señal de cambio real-time dirigida a un entrenador (efímera). Ver la migración
 * `create_trainer_realtime_events_table`. Espejo de {@see MemberRealtimeEvent}.
 */
class TrainerRealtimeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'trainer_id',
        'type',
        'changed',
        'version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changed' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
