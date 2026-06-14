<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sesión por dispositivo del portal profesional. Equivalente a
 * {@see MemberDeviceSession} para entrenadores. El bearer real es un token opaco
 * cuyo hash se guarda en `token_hash`.
 */
class TrainerDeviceSession extends Model
{
    protected $fillable = [
        'uuid',
        'trainer_id',
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
            'trusted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainerDeviceSession $session): void {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Representación pública para "Mis dispositivos" del portal/CRM. */
    public function toPublicArray(?string $currentDeviceId = null): array
    {
        return [
            'uuid' => $this->uuid,
            'device_id' => $this->device_id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at,
            'is_current' => $currentDeviceId !== null && $this->device_id === $currentDeviceId,
        ];
    }
}
