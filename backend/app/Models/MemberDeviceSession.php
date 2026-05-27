<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sesión viva de un miembro en un dispositivo concreto. El bearer real (session
 * token) no se guarda en claro: sólo su hash en {@see $token_hash}.
 */
class MemberDeviceSession extends Model
{
    protected $fillable = [
        'uuid',
        'member_id',
        'device_id',
        'device_name',
        'platform',
        'app_version',
        'token_hash',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'trusted_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'trusted_at'   => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberDeviceSession $session): void {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Hash determinista del token usado como bearer. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Representación pública para el listado de "Mis dispositivos". */
    public function toPublicArray(?string $currentDeviceId = null): array
    {
        return [
            'uuid'         => $this->uuid,
            'device_id'    => $this->device_id,
            'device_name'  => $this->device_name ?: 'Dispositivo',
            'platform'     => $this->platform,
            'app_version'  => $this->app_version,
            'ip_address'   => $this->ip_address,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'trusted_at'   => $this->trusted_at?->toIso8601String(),
            'is_current'   => $currentDeviceId !== null && $this->device_id === $currentDeviceId,
        ];
    }
}
