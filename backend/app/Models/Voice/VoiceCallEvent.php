<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un evento de una llamada (contrato H8, D8-13; `voice_call_events`, SOLO
 * INSERCIÓN). Deduplicado por (`provider`, `kind`, `provider_event_key`). Sin
 * texto de transcripción: solo códigos, números de turno y cifras.
 */
class VoiceCallEvent extends Model
{
    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const PROVIDER_TWILIO = 'twilio';

    public const PROVIDER_LARAVEL = 'laravel';

    public const KINDS = [
        'outbound_requested', 'outbound_started', 'outbound_rejected', 'outbound_ambiguous', 'initiation_received',
        'post_call_transcription', 'post_call_audio_discarded', 'call_initiation_failure',
        'tool_call', 'guard_verdict', 'hangup_requested',
        'reconciled', 'seal_mismatch', 'cost_recorded', 'unknown_provider_call',
    ];

    public const ACTOR_PROVIDER = 'provider';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_ADMIN = 'admin';

    public const UPDATED_AT = null;

    protected $table = 'voice_call_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'codes' => 'array',
            'call_id' => 'integer',
            'actor_admin_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evento): void {
            $evento->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: los eventos de llamada son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('voice: los eventos de llamada son de solo inserción.'));
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class, 'call_id');
    }
}
