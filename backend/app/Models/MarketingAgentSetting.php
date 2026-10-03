<?php

namespace App\Models;

use App\Services\Marketing\MarketingAgentSwitch;
use Illuminate\Database\Eloquent\Model;

/**
 * La fila única del interruptor del agente IA. Se lee y se escribe solo a
 * través de {@see MarketingAgentSwitch}.
 */
class MarketingAgentSetting extends Model
{
    protected $fillable = [
        'id',
        'paused',
        'paused_at',
        'resumed_at',
        'changed_by_admin_id',
        'changed_by_name',
        'changed_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'changed_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
