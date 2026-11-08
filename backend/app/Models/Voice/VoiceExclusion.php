<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una exclusión de voz (contrato H8, D8-18; `voice_exclusions`, SOLO
 * INSERCIÓN), por la huella `voice-phone`: vale para cualquier contacto o
 * canario con ese número. Se consulta antes de cada saliente y solo la quita
 * el procedimiento de supresión (OPS8).
 */
class VoiceExclusion extends Model
{
    public const SOURCE_RNE = 'rne';

    public const SOURCE_SPOKEN_OPT_OUT = 'spoken_opt_out';

    public const SOURCE_LEAD_DO_NOT_CONTACT = 'lead_do_not_contact';

    public const SOURCE_LEAD_CONSENT_DENIED = 'lead_consent_denied';

    public const SOURCE_ADMIN = 'admin';

    public const UPDATED_AT = null;

    protected $table = 'voice_exclusions';

    protected $guarded = ['*'];

    protected $hidden = ['phone_hash'];

    protected function casts(): array
    {
        return [
            'call_id' => 'integer',
            'contact_id' => 'integer',
            'rne_check_id' => 'integer',
            'actor_admin_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $exclusion): void {
            $exclusion->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: las exclusiones de voz son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('voice: las exclusiones de voz son de solo inserción.'));
    }
}
