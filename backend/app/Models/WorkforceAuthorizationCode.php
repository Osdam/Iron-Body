<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Autorización que un administrador concede desde el CRM a una persona:
 * enrolar su rostro o registrar una operación sin rostro (respaldo). Un solo
 * uso; se guarda el HMAC del código. Ver `create_workforce_codes_and_tokens_tables`.
 */
class WorkforceAuthorizationCode extends Model
{
    public const PURPOSE_ENROLLMENT = 'enrollment';

    public const PURPOSE_FALLBACK_WORKDAY = 'fallback_workday';

    public const PURPOSE_FALLBACK_CLASS = 'fallback_class';

    protected $fillable = [
        'identity_id', 'purpose', 'code_hash', 'reason',
        'created_by_admin_id', 'created_by_name',
        'expires_at', 'attempts', 'consumed_at', 'consumed_device_id', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('workforce.codes.max_attempts', 5);
    }
}
