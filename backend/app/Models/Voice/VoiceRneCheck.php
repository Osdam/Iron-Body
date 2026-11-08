<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una consulta al Registro de Números Excluidos (contrato H8, D8-18, [P-14];
 * `voice_rne_checks`, SOLO INSERCIÓN). Manual (el portal de la CRC) hasta que
 * se confirme una API: el conector queda «no configurado».
 */
class VoiceRneCheck extends Model
{
    public const METHOD_MANUAL_PORTAL = 'manual_portal';

    public const RESULTS = ['listed', 'not_listed'];

    public const UPDATED_AT = null;

    protected $table = 'voice_rne_checks';

    protected $guarded = ['*'];

    protected $hidden = ['phone_hash'];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'actor_admin_id' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $consulta): void {
            $consulta->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: las consultas al RNE son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('voice: las consultas al RNE son de solo inserción.'));
    }
}
