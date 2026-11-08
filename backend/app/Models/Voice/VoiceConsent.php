<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un consentimiento de voz (contrato H8, D8-18; E3-4; `voice_consents`, SOLO
 * INSERCIÓN). `granted` o `revoked`, canal `voice_call` y finalidad
 * `commercial`. Uno cargado por una persona lo confirma OTRA (cuatro ojos: lo
 * exige también la base).
 */
class VoiceConsent extends Model
{
    public const CHANNEL = 'voice_call';

    public const PURPOSE = 'commercial';

    public const UPDATED_AT = null;

    protected $table = 'voice_consents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'recorded_by_admin_id' => 'integer',
            'confirmed_by_admin_id' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $consentimiento): void {
            $consentimiento->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: los consentimientos de voz son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('voice: los consentimientos de voz son de solo inserción.'));
    }
}
