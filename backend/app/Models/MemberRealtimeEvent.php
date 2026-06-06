<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Señal efímera de cambio para el stream real-time del miembro. No guarda datos
 * sensibles (ni tokens ni OTP ni montos): solo el tipo de cambio y qué módulos
 * tocó, para que el cliente refresque el módulo correspondiente.
 */
class MemberRealtimeEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_id',
        'type',
        'changed',
        'version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changed' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
