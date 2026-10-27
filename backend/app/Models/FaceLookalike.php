<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Dos personas cuyo rostro el modelo no separa (contrato §10.5). Quien está en
 * un par necesita SIEMPRE rostro + PIN: con el rostro solo, una podría pasar
 * por la otra. Par sin orden (el id menor primero), sin distancias.
 */
class FaceLookalike extends Model
{
    public const SOURCE_DUPLICATE_REJECTED = 'duplicate_rejected';

    protected $fillable = [
        'identity_low_id',
        'identity_high_id',
        'source',
        'first_detected_at',
        'last_detected_at',
    ];

    protected function casts(): array
    {
        return [
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
        ];
    }

    /** La otra persona del par, vista desde $identityId. */
    public function otherThan(int $identityId): int
    {
        return (int) ($this->identity_low_id === $identityId ? $this->identity_high_id : $this->identity_low_id);
    }
}
