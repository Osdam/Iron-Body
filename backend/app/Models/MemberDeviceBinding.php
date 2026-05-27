<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asociación dispositivo ↔ miembro titular (anti-uso-compartido por equipo).
 */
class MemberDeviceBinding extends Model
{
    protected $fillable = [
        'device_id',
        'member_id',
        'device_name',
        'platform',
        'bound_at',
    ];

    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Vínculo vigente de un dispositivo (o null si está libre). */
    public static function forDevice(?string $deviceId): ?self
    {
        if ($deviceId === null || trim($deviceId) === '') {
            return null;
        }

        return self::query()->where('device_id', $deviceId)->first();
    }
}
