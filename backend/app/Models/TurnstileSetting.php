<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TurnstileSetting extends Model
{
    protected $fillable = [
        'name',
        'enabled',
        'mode',
        'device_host',
        'device_port',
        'device_comm_key',
        'serial_port',
        'serial_baud',
        'serial_command',
        'webhook_url',
        'http_method',
        'auth_header',
        'request_payload',
        'open_duration_ms',
        'fire_on_entry',
        'fire_on_exit',
        'sound_enabled',
        'last_triggered_at',
        'last_status',
        'last_error',
        'last_http_code',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fire_on_entry' => 'boolean',
            'fire_on_exit' => 'boolean',
            'sound_enabled' => 'boolean',
            'open_duration_ms' => 'integer',
            'device_port' => 'integer',
            'serial_baud' => 'integer',
            'last_http_code' => 'integer',
            'last_triggered_at' => 'datetime',
        ];
    }

    /** Devuelve la fila singleton, creándola si no existe. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Torniquete principal',
                'enabled' => false,
                'mode' => 'webhook',
                'device_port' => 4370,
                'http_method' => 'POST',
                'open_duration_ms' => 3000,
                'fire_on_entry' => true,
                'fire_on_exit' => false,
                'sound_enabled' => true,
            ],
        );
    }
}
