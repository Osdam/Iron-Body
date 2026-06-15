<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Reto OTP del acceso profesional. Equivalente a {@see MemberAuthChallenge} para
 * entrenadores. El código real nunca se persiste: solo su hash.
 */
class TrainerAuthChallenge extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_BLOCKED = 'blocked';

    public const PURPOSE_LOGIN = 'trainer_login';

    public const PURPOSE_PROFILE_LINK = 'profile_link';

    public const PURPOSE_WORKSPACE_SWITCH = 'workspace_switch';

    protected $fillable = [
        'uuid',
        'trainer_id',
        'purpose',
        'code_hash',
        'channel',
        'destination',
        'device_id',
        'device_name',
        'platform',
        'ip_address',
        'user_agent',
        'attempts',
        'resend_count',
        'status',
        'last_sent_at',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'resend_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainerAuthChallenge $challenge): void {
            $challenge->uuid ??= (string) Str::uuid();
        });
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerifiable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    public function maskedDestination(): ?string
    {
        return MemberAuthChallenge::maskPhone($this->destination);
    }
}
