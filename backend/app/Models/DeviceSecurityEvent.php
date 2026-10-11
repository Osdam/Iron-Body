<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intento rechazado o sospechoso de un dispositivo: desconocido, revocado,
 * firma inválida, desafío reutilizado o caducado. Append-only.
 */
class DeviceSecurityEvent extends Model
{
    public const UPDATED_AT = null;

    public const UNKNOWN_DEVICE = 'device_unknown';

    public const REVOKED_DEVICE = 'device_revoked';

    public const SIGNATURE_REQUIRED = 'device_signature_required';

    public const SIGNATURE_INVALID = 'device_signature_invalid';

    public const CHALLENGE_INVALID = 'device_challenge_invalid';

    public const PAIRING_CODE_INVALID = 'pairing_code_invalid';

    public const LABELS = [
        self::UNKNOWN_DEVICE => 'Dispositivo desconocido',
        self::REVOKED_DEVICE => 'Dispositivo revocado',
        self::SIGNATURE_REQUIRED => 'Petición sin firma de dispositivo',
        self::SIGNATURE_INVALID => 'Firma inválida',
        self::CHALLENGE_INVALID => 'Desafío inválido, caducado o reutilizado',
        self::PAIRING_CODE_INVALID => 'Código de vinculación inválido',
    ];

    protected $fillable = [
        'authorized_device_id', 'claimed_device_id', 'event', 'operation',
        'ip_address', 'user_agent', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AuthorizedDevice::class, 'authorized_device_id');
    }
}
