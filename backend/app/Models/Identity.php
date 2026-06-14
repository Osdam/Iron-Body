<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Identidad central de una persona. Agrupa, de forma opcional e independiente,
 * su perfil de miembro y sus perfiles profesionales (entrenador). Ser miembro y
 * ser entrenador son estados independientes: ninguno implica el otro y la baja
 * de uno no elimina el otro.
 *
 * Esta clase NO autoriza por sí misma; solo describe la relación entre perfiles
 * para soportar perfiles dobles y el cambio de espacio sin duplicar personas.
 */
class Identity extends Model
{
    protected $fillable = [
        'uuid',
        'document_normalized',
        'phone_normalized',
    ];

    protected static function booted(): void
    {
        static::creating(function (Identity $identity): void {
            $identity->uuid ??= (string) Str::uuid();
        });
    }

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class);
    }

    /** ¿La identidad tiene un perfil de miembro? */
    public function hasMemberProfile(): bool
    {
        return $this->member()->exists();
    }

    /** ¿La identidad tiene al menos un perfil profesional ACTIVO? */
    public function hasActiveTrainerProfile(): bool
    {
        return $this->trainers()
            ->whereIn('status', ['active', 'activo'])
            ->exists();
    }
}
