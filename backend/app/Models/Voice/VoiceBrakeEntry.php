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
 *
 * Los motivos son un conjunto CERRADO, el mismo que `vbr_reason_chk` en
 * PostgreSQL: los seis de DB8 (`BRAKE_REASONS` de `2026_11_10_100002`) y
 * `webhook_unhealthy` (SC-3 del cierre, R8-12; migración `2026_11_14_100001`):
 * el webhook post-llamada o sus secretos tienen un problema DEFINITIVO
 * (`VoiceWebhookHealth::BRAKING`, de la telefonía) y las llamadas se quedarían
 * sin la guarda posterior.
 */
class VoiceBrakeEntry extends Model
{
    public const KIND_OPEN = 'open';

    public const KIND_RELEASE = 'release';

    /** SC-3 (R8-12): el webhook post-llamada o sus secretos, mal sin duda; lo abre `voice:webhook-health`. */
    public const REASON_WEBHOOK_UNHEALTHY = 'webhook_unhealthy';

    public const REASONS = ['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call', self::REASON_WEBHOOK_UNHEALTHY];

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
