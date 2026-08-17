<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una serie ejecutada dentro de una sesión de entrenamiento.
 *
 * @property int $set_number
 * @property int|null $reps
 * @property float|null $weight_kg
 * @property bool $completed
 */
class WorkoutSessionSet extends Model
{
    protected $fillable = [
        'workout_session_id', 'exercise_id', 'exercise_name', 'exercise_key',
        'exercise_order', 'set_number', 'reps', 'weight_kg', 'rpe',
        'completed', 'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'exercise_order' => 'integer',
            'set_number' => 'integer',
            'reps' => 'integer',
            'weight_kg' => 'decimal:2',
            'rpe' => 'integer',
            'completed' => 'boolean',
            'performed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Volumen de la serie: carga × repeticiones.
     *
     * Solo cuenta si la serie se marcó como completada y lleva carga y reps
     * reales. Un ejercicio sin carga (peso corporal, cardio) aporta 0: el
     * producto no tiene todavía una regla de volumen para esos casos y
     * inventarla falsearía el total.
     */
    public function volume(): float
    {
        if (! $this->completed) {
            return 0.0;
        }

        $weight = (float) ($this->weight_kg ?? 0);
        $reps = (int) ($this->reps ?? 0);

        if ($weight <= 0 || $reps <= 0) {
            return 0.0;
        }

        return round($weight * $reps, 2);
    }

    /** Normaliza el nombre del ejercicio para agrupar récords de forma estable. */
    public static function normalizeKey(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
