<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Franja semanal de disponibilidad de un entrenador para valoraciones.
 *
 * `weekday` ISO (1 = lunes … 7 = domingo) y `start_time`/`end_time` en hora de
 * PARED de Bogotá, sin cruzar la medianoche. Varias por día; el descanso es el
 * hueco entre ellas. La agenda solo ofrece y acepta citas dentro de una franja
 * activa y vigente: sin franjas no hay huecos.
 */
class ValuationAvailabilityRule extends Model
{
    protected $fillable = [
        'trainer_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_until',
        'active', 'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'active' => 'boolean',
            'trainer_id' => 'integer',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    /** `HH:MM`, sea cual sea el formato en que el motor devuelva el TIME. */
    public function startHm(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endHm(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    /** `Y-m-d` o null (el motor puede devolver la fecha con hora). */
    public function validFromDate(): ?string
    {
        return $this->valid_from ? substr((string) $this->valid_from, 0, 10) : null;
    }

    public function validUntilDate(): ?string
    {
        return $this->valid_until ? substr((string) $this->valid_until, 0, 10) : null;
    }
}
