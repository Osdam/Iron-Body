<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de notificaciones push (FCM) de un dispositivo del miembro.
 */
class MemberDeviceToken extends Model
{
    protected $fillable = [
        'member_id',
        'token',
        'device_id',
        'platform',
        'device_name',
        'app_version',
        'notification_permission',
        'is_active',
        'last_used_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
