<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sesión del panel/CRM. El bearer real es un token opaco cuyo hash se guarda en
 * `token_hash`. Espejo reducido de {@see TrainerDeviceSession}.
 */
class AdminSession extends Model
{
    protected $fillable = [
        'uuid',
        'admin_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'expires_at',
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
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdminSession $session): void {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /** Sesión utilizable: no revocada y no caducada. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
