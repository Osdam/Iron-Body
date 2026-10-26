<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Diagnóstico de un rechazo por «rostro de otra persona»: la plantilla con la
 * que coincidió y la distancia. Una fila por plantilla dentro del umbral.
 *
 * Solo para quien administra la biometría desde el servidor: ningún endpoint
 * la lee, caduca a los `workforce.face.duplicate_match_retention_days` y se
 * borra al revocar la plantilla con la que coincidió. Append-only.
 */
class FaceDuplicateMatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'audit_log_id',
        'face_reference_id',
        'distance',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'distance' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
