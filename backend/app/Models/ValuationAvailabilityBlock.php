<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo puntual de la agenda de un entrenador (descanso, ausencia, día libre
 * o manual). Instantes UTC. Crear un bloqueo NO cancela las citas que ya haya
 * debajo: con `force` solo se avisa.
 */
class ValuationAvailabilityBlock extends Model
{
    public const KINDS = ['break', 'absence', 'day_off', 'manual'];

    public const KIND_LABELS = [
        'break' => 'Descanso',
        'absence' => 'Ausencia',
        'day_off' => 'Día libre',
        'manual' => 'Bloqueo manual',
    ];

    protected $fillable = ['trainer_id', 'starts_at', 'ends_at', 'kind', 'reason', 'created_by_admin_id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trainer_id' => 'integer',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
