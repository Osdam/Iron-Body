<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Nonce de un solo uso que un dispositivo firma (anti-replay). */
class DeviceChallenge extends Model
{
    public $timestamps = false;

    protected $fillable = ['authorized_device_id', 'nonce', 'expires_at', 'consumed_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
