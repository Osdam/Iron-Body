<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una fila del FRENO de voz (contrato H8, D8-19; `voice_brakes`, SOLO
 * INSERCIÓN): ABRIR (`open`, lo hace el sistema solo) y SOLTAR (`release`,
 * una persona, con su nota). Una apertura se suelta una vez (`vbr_release_once`).
 * El freno está echado mientras quede una apertura sin soltar.
 */
class VoiceBrakeEntry extends Model
{
    public const KIND_OPEN = 'open';

    public const KIND_RELEASE = 'release';

    public const REASONS = ['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call'];

    public const UPDATED_AT = null;

    protected $table = 'voice_brakes';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'brake_id' => 'integer',
            'call_id' => 'integer',
            'event_id' => 'integer',
            'evidence' => 'array',
            'actor_admin_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $fila): void {
            $fila->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: el freno de voz es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('voice: el freno de voz es de solo inserción.'));
    }
}
