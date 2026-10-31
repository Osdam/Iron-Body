<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PIN personal: el segundo factor del rostro cuando el modelo no basta
 * (contrato §10). Solo vale JUNTO a un rostro que coincide; nunca solo. Se
 * guarda el bcrypt del HMAC del PIN con la APP_KEY y nunca sale del servidor.
 */
class WorkforcePin extends Model
{
    public const STATUS_NOT_SET = 'not_set';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'identity_id',
        'pin_hash',
        'set_at',
        'set_device_id',
        'set_authorization_code_id',
        'failed_attempts',
        'failures_window_started_at',
        'locked_until',
        'lock_level',
        'last_locked_at',
        'revoked_at',
        'revoked_by_admin_id',
        'revoked_by_name',
        'revoked_reason',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'set_at' => 'datetime',
            'locked_until' => 'datetime',
            'failures_window_started_at' => 'datetime',
            'last_locked_at' => 'datetime',
            'revoked_at' => 'datetime',
            'failed_attempts' => 'integer',
            'lock_level' => 'integer',
        ];
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }
        if ($this->pin_hash === null) {
            return self::STATUS_NOT_SET;
        }
        if ($this->locked_until !== null && $this->locked_until->isFuture()) {
            return self::STATUS_LOCKED;
        }

        return self::STATUS_ACTIVE;
    }
}
