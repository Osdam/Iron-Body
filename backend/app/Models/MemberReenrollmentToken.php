<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de re-enrolamiento biométrico de UN SOLO USO. Autoriza reemplazar la
 * referencia facial tras un segundo factor (OTP). Vida corta, ligado a un
 * miembro + ticket facial. El valor real nunca se persiste: sólo su hash.
 */
class MemberReenrollmentToken extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_USED    = 'used';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'member_id',
        'challenge_uuid',
        'token_hash',
        'reason',
        'status',
        'attempts',
        'device_id',
        'ip_address',
        'expires_at',
        'used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts'   => 'integer',
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Usable = pendiente y no vencido. */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }
}
