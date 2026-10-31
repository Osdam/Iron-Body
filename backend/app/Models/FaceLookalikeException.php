<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Autorización presencial de un administrador para registrar el rostro de una
 * persona pese a su parecido con la otra del par (contrato §10.5). Solo
 * interviene en el enrolamiento, nunca al verificar. Un uso, caduca.
 */
class FaceLookalikeException extends Model
{
    protected $fillable = [
        'identity_id',
        'other_identity_id',
        'subject_verified_in_person',
        'other_verified_in_person',
        'reason',
        'authorized_by_admin_id',
        'authorized_by_name',
        'expires_at',
        'consumed_at',
        'consumed_reference_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_verified_in_person' => 'boolean',
            'other_verified_in_person' => 'boolean',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
