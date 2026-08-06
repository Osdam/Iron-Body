<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * En qué situación comercial está una persona, calculado y con fecha.
 *
 * La diferencia con una etiqueta manual: esto caduca. Un «alta intención» de
 * hace tres semanas no es alta intención, y tratarlo como tal es la forma más
 * rápida de que el agente diga algo que no encaja con la realidad del cliente.
 */
class CommercialSegment extends Model
{
    protected $fillable = [
        'marketing_lead_id', 'member_id', 'segment',
        'confidence', 'evidence', 'computed_at', 'expires_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'confidence' => 'float',
        'computed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** ¿Sigue siendo válido lo que dice esta fila? */
    public function isFresh(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
