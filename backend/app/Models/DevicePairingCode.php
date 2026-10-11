<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Código de un solo uso para vincular un dispositivo. Se guarda su HMAC. */
class DevicePairingCode extends Model
{
    protected $fillable = [
        'code_hash', 'name', 'location', 'created_by_admin_id', 'created_by_name',
        'expires_at', 'consumed_at', 'consumed_device_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
