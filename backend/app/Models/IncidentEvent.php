<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada en la historia de un incidente: se repitió, alguien lo reconoció,
 * se ejecutó una remediación, se generó una hipótesis.
 *
 * Es también el registro de auditoría de IRON GUARD: toda acción automática deja
 * aquí su rastro con quién la hizo y con qué resultado.
 */
class IncidentEvent extends Model
{
    public const KIND_OCCURRENCE = 'occurrence';

    public const KIND_ANALYSIS = 'analysis';

    public const KIND_REMEDIATION = 'remediation';

    public const KIND_NOTE = 'note';

    public const KIND_STATUS = 'status';

    protected $fillable = ['incident_id', 'kind', 'actor', 'summary', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
