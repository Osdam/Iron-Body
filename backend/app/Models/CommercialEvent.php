<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo que ha ido pasando con una persona, en orden.
 *
 * Cada evento puede provocar que el motor recalcule la siguiente mejor acción.
 * `evaluated_at` en null significa «esto todavía no lo ha mirado nadie», que es
 * exactamente la cola de trabajo del motor.
 */
class CommercialEvent extends Model
{
    protected $fillable = [
        'marketing_lead_id', 'member_id', 'commercial_opportunity_id',
        'event', 'dedupe_key', 'payload', 'occurred_at', 'evaluated_at', 'correlation_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'evaluated_at' => 'datetime',
    ];
}
