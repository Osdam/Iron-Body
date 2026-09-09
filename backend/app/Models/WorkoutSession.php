<?php

namespace App\Models;

use App\Enums\WorkoutExerciseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $member_id
 * @property int|null $routine_id
 * @property string $client_session_id
 * @property int $duration_seconds
 * @property float $total_volume_kg
 */
class WorkoutSession extends Model
{
    protected $fillable = [
        'member_id', 'routine_id', 'routine_completion_id', 'client_session_id',
        'routine_name', 'started_at', 'completed_at', 'duration_seconds',
        'total_volume_kg', 'total_sets', 'total_exercises', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'total_volume_kg' => 'decimal:2',
            'total_sets' => 'integer',
            'total_exercises' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(RoutineCompletion::class, 'routine_completion_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSessionSet::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutSessionExercise::class);
    }

    /**
     * El estado de cada ejercicio de la sesión, exista o no la fila.
     *
     * Las sesiones anteriores a esta tabla no tienen filas de ejercicio y no se
     * les van a inventar: un backfill escribiría como dato lo que solo es una
     * suposición sobre entrenamientos que ya nadie puede confirmar. Para ellas
     * el estado se DERIVA en lectura desde sus series —completado si ejecutó
     * alguna, pendiente si no— que es exactamente lo que se sabe de ellas.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function exerciseBreakdown(): Collection
    {
        $rows = $this->exercises()->orderBy('exercise_order')->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn (WorkoutSessionExercise $e) => $e->toPublicArray())->values();
        }

        return $this->sets()->orderBy('exercise_order')->get()
            ->groupBy('exercise_order')
            ->map(function (Collection $sets, $order): array {
                /** @var WorkoutSessionSet $first */
                $first = $sets->first();

                return [
                    'exercise_id' => $first->exercise_id,
                    'name' => $first->exercise_name,
                    'exercise_key' => $first->exercise_key,
                    'order' => (int) $order,
                    'status' => $sets->contains('completed', true)
                        ? WorkoutExerciseStatus::COMPLETED->value
                        : WorkoutExerciseStatus::PENDING->value,
                    'skip_reason' => null,
                ];
            })
            ->values();
    }

    /**
     * Cuántos ejercicios se completaron DE VERDAD.
     *
     * Es lo que alimenta `total_exercises`. Antes se contaban las claves únicas
     * de las series, con lo que un ejercicio prescrito y nunca marcado sumaba
     * igual que uno entrenado.
     */
    public function completedExerciseCount(): int
    {
        return $this->exerciseBreakdown()
            ->where('status', WorkoutExerciseStatus::COMPLETED->value)
            ->count();
    }

    /**
     * Resumen que consume la pantalla "Entrenamiento completado". Es la
     * respuesta AUTORITATIVA: la app la pinta tal cual en vez de recalcular
     * nada en memoria, así que cerrar y reabrir no cambia lo que se ve.
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'client_session_id' => $this->client_session_id,
            'routine_id' => $this->routine_id ? (string) $this->routine_id : null,
            'routine_name' => $this->routine_name,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'total_volume_kg' => (float) $this->total_volume_kg,
            'total_sets' => $this->total_sets,
            'total_exercises' => $this->total_exercises,
            'muscle_groups' => $this->muscleGroups(),
        ];
    }

    /** Grupos musculares reales de los ejercicios entrenados en la sesión. */
    public function muscleGroups(): array
    {
        return $this->sets
            ->map(fn (WorkoutSessionSet $s) => $s->exercise?->body_part ?: $s->exercise?->target)
            ->filter(fn ($g) => filled($g))
            ->unique()
            ->values()
            ->all();
    }
}
