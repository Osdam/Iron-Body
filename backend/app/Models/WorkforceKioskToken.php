<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Identidad verificada en el kiosko para UNA operación (`verification`) o un
 * enrolamiento aceptado pendiente de completar (`enrollment`). Atada a persona,
 * dispositivo y propósito; se guarda el HMAC del token.
 */
class WorkforceKioskToken extends Model
{
    public const UPDATED_AT = null;

    public const KIND_VERIFICATION = 'verification';

    public const KIND_ENROLLMENT = 'enrollment';

    protected $fillable = [
        'token_hash', 'kind', 'purpose', 'identity_id', 'authorized_device_id',
        'method', 'face_distance', 'authorization_code_id', 'expires_at', 'consumed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'face_distance' => 'float',
        ];
    }
}
