<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo social entre miembros.
 *
 * @property int $id
 * @property int $blocker_member_id
 * @property int $blocked_member_id
 * @property string|null $reason
 */
class UserBlock extends Model
{
    protected $fillable = [
        'blocker_member_id',
        'blocked_member_id',
        'reason',
    ];

    protected $casts = [
        'blocker_member_id' => 'integer',
        'blocked_member_id' => 'integer',
    ];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'blocker_member_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'blocked_member_id');
    }

    /**
     * Bloqueos que involucran a este miembro en CUALQUIER sentido.
     *
     * El efecto de un bloqueo es simétrico: si A bloqueó a B, ninguno ve al
     * otro. Por eso el feed nunca consulta una sola dirección.
     */
    public function scopeInvolving(Builder $q, int $memberId): Builder
    {
        return $q->where(function (Builder $inner) use ($memberId) {
            $inner->where('blocker_member_id', $memberId)
                ->orWhere('blocked_member_id', $memberId);
        });
    }
}
