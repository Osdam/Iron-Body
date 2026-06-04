<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo de seguridad de una cuenta (Fase 10). Mientras haya un bloqueo
 * "vivo" (activo y no vencido) el miembro no puede iniciar sesión ni usar la app.
 */
class MemberRiskLock extends Model
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_EXPIRED  = 'expired';

    public const BY_SYSTEM = 'system';
    public const BY_ADMIN  = 'admin';

    protected $fillable = [
        'member_id',
        'reason',
        'status',
        'locked_until',
        'created_by',
        'resolved_by',
        'resolution_note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** ¿El bloqueo sigue vigente? (activo y, si tiene fecha, no vencida). */
    public function isLive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->locked_until === null || $this->locked_until->isFuture();
    }

    /** Bloqueos vivos: activos y no vencidos. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q): void {
                $q->whereNull('locked_until')->orWhere('locked_until', '>', now());
            });
    }
}
