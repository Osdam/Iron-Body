<?php

namespace App\Models;

use App\Enums\WorkoutExerciseStatus;
use App\Enums\WorkoutSkipReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ejercicio dentro de una sesión, con su estado.
 *
 * Su identidad dentro de la sesión es `exercise_order`. `exercise_id` puede ser
 * null (ejercicio fuera del catálogo) y puede repetirse (el mismo ejercicio dos
 * veces en el día), así que no sirve para identificar la posición.
 *
 * @property int $exercise_order
 * @property WorkoutExerciseStatus $status
 * @property WorkoutSkipReason|null $skip_reason
 */
class WorkoutSessionExercise extends Model
{
    protected $fillable = [
        'workout_session_id', 'exercise_id', 'exercise_name', 'exercise_key',
        'exercise_order', 'status', 'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'exercise_order' => 'integer',
            'status' => WorkoutExerciseStatus::class,
            'skip_reason' => WorkoutSkipReason::class,
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

    public function toPublicArray(): array
    {
        return [
            'exercise_id' => $this->exercise_id,
            'name' => $this->exercise_name,
            'exercise_key' => $this->exercise_key,
            'order' => $this->exercise_order,
            'status' => $this->status->value,
            'skip_reason' => $this->skip_reason?->value,
        ];
    }
}
