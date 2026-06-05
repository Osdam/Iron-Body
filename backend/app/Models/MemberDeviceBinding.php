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
        'last_otp_reauth_at',
    ];

    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
            'last_otp_reauth_at' => 'datetime',
        ];
    }

    /**
     * ¿El dispositivo confiable debe revalidar por OTP? Es así si nunca registró
     * una revalidación (null) o si la última supera `trusted_reauth_days`.
     * Con `trusted_reauth_days = 0` la revalidación periódica queda desactivada.
     */
    public function needsOtpReauth(): bool
    {
        $days = (int) config('security.trusted_reauth_days', 30);
        if ($days <= 0) {
            return false;
        }

        return $this->last_otp_reauth_at === null
            || $this->last_otp_reauth_at->lt(now()->subDays($days));
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
