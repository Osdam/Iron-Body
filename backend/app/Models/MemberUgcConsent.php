<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aceptación versionada de los Lineamientos de Comunidad.
 *
 * Es independiente del contrato de membresía: quien no publica Stories nunca
 * necesita esta fila y jamás se le bloquea la app por no tenerla.
 */
class MemberUgcConsent extends Model
{
    protected $fillable = [
        'member_id',
        'community_guidelines_version',
        'accepted_at',
        'platform',
        'app_version',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** ¿Este miembro aceptó la versión vigente de los lineamientos? */
    public static function hasAcceptedCurrent(int $memberId): bool
    {
        return static::query()
            ->where('member_id', $memberId)
            ->where('community_guidelines_version', (string) config('ugc.guidelines_version'))
            ->exists();
    }
}
