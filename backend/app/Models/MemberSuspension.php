<?php

namespace App\Models;

use App\Support\Moderation\ModerationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sanción SOCIAL viva de un miembro.
 *
 * Independiente de `members.status` (suspensión de SEGURIDAD) y de membresías,
 * pagos y facturación. Una fila aquí no cancela nada del gimnasio.
 *
 * Caducidad: NO hay job que "desactive" nada. El estado efectivo se calcula
 * siempre con `status = active AND (ends_at IS NULL OR ends_at > now())`. Así
 * una suspensión temporal expira sola aunque las colas estén caídas.
 *
 * @property string $scope
 * @property string $status
 * @property Carbon|null $ends_at
 */
class MemberSuspension extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'public_id',
        'member_id',
        'scope',
        'status',
        'starts_at',
        'ends_at',
        'reason_code',
        'public_reason',
        'internal_reason',
        'moderation_action_id',
        'created_by_admin_id',
        'revoked_at',
        'revoked_by_admin_id',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Motivo interno: jamás viaja a la app. */
    protected $hidden = [
        'internal_reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (MemberSuspension $suspension): void {
            $suspension->public_id ??= (string) Str::uuid();
            $suspension->starts_at ??= now();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class, 'moderation_action_id');
    }

    /**
     * Sanciones EFECTIVAS ahora mismo. Es el único predicado que la app debe
     * usar: cubre revocación y caducidad sin depender de un job.
     */
    public function scopeEffective(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where(function (Builder $inner) {
                $inner->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    public function isEffective(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function isPermanent(): bool
    {
        return $this->ends_at === null;
    }

    /** Capacidades que esta sanción retira. */
    public function impliedScopes(): array
    {
        return ModerationScope::implies($this->scope);
    }

    public function scopeLabel(): string
    {
        return ModerationScope::label($this->scope);
    }
}
