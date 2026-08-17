<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Récord personal vigente de un miembro para un ejercicio y una métrica.
 *
 * @property string $metric
 * @property float $value
 */
class PersonalRecord extends Model
{
    /** Carga máxima levantada en una serie completada. */
    public const METRIC_MAX_WEIGHT = 'max_weight';

    /** 1RM estimada (Epley) a partir de carga y repeticiones de una serie. */
    public const METRIC_ESTIMATED_1RM = 'estimated_1rm';

    public const METRICS = [self::METRIC_MAX_WEIGHT, self::METRIC_ESTIMATED_1RM];

    /** Origen del récord: derivado de un entrenamiento o puesto por el entrenador. */
    public const SOURCE_WORKOUT = 'workout';

    public const SOURCE_TRAINER = 'trainer';

    protected $fillable = [
        'member_id', 'exercise_id', 'exercise_name', 'exercise_key',
        'metric', 'value', 'unit', 'reps', 'weight_kg', 'previous_value',
        'achieved_at', 'source', 'workout_session_id', 'workout_session_set_id',
        'trainer_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'previous_value' => 'decimal:2',
            'reps' => 'integer',
            'achieved_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    /** Etiqueta legible de la métrica para la app. */
    public function metricLabel(): string
    {
        return match ($this->metric) {
            self::METRIC_MAX_WEIGHT => 'Carga máxima',
            self::METRIC_ESTIMATED_1RM => '1RM estimada',
            default => $this->metric,
        };
    }

    public function toPublicArray(): array
    {
        return [
            'name' => $this->exercise_name,
            'metric' => $this->metric,
            'metric_label' => $this->metricLabel(),
            'value' => (float) $this->value,
            'unit' => $this->unit,
            'reps' => $this->reps,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'previous_value' => $this->previous_value !== null ? (float) $this->previous_value : null,
            'achieved_at' => $this->achieved_at?->toIso8601String(),
            'source' => $this->source,
        ];
    }
}
