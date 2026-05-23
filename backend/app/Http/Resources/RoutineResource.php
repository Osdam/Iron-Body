<?php

namespace App\Http\Resources;

use App\Models\Exercise;
use App\Models\Routine;
use App\Models\RoutineExercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Routine $this */
        $exercises = $this->routineExercises
            ->sortBy('sort_order')
            ->values()
            ->map(fn (RoutineExercise $re): array => $this->serializeRoutineExercise($re))
            ->all();

        // Fallback: si la tabla normalizada está vacía pero la rutina trae
        // ejercicios en la columna JSON `exercises` (caso Api\RoutineController
        // store/update desde el CRM Angular), serializamos desde ahí para no
        // devolver un array vacío a la app.
        if (empty($exercises) && is_array($this->exercises) && ! empty($this->exercises)) {
            $exercises = collect($this->exercises)
                ->map(fn (array $ex, int $i): array => [
                    'id'           => $ex['id'] ?? ($i + 1),
                    'exercise_id'  => null,
                    'name'         => $ex['name'] ?? '',
                    'muscle_group' => $ex['muscleGroup'] ?? $ex['muscle_group'] ?? '',
                    'sets'         => (int) ($ex['sets'] ?? 3),
                    'reps'         => (int) ($ex['reps'] ?? 10),
                    'weight'       => (string) ($ex['suggestedWeight'] ?? $ex['weight'] ?? ''),
                    'notes'        => (string) ($ex['notes'] ?? ''),
                    'sort_order'   => (int) ($ex['order'] ?? ($i + 1)),
                    'gif_url'      => null,
                    'thumbnail_url'=> null,
                ])
                ->values()
                ->all();
        }

        // Build muscle_group from exercises if not explicitly set.
        // El fallback del JSON expone la clave `muscle_group` plana; el formato
        // normal de serializeRoutineExercise() trae el nombre dentro de `exercise.muscle_group`.
        $muscleGroup = trim((string) ($this->muscle_group ?? ''));
        if ($muscleGroup === '' && ! empty($exercises)) {
            $muscleGroup = collect($exercises)
                ->map(fn (array $e) => $e['muscle_group'] ?? $e['exercise']['muscle_group'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->implode(' · ');
        }

        return [
            'id'                => (string) $this->id,
            'name'              => $this->name,
            'objective'         => $this->objective ?? '',
            'level'             => $this->level ?? 'Principiante',
            'muscle_group'      => $muscleGroup,
            'estimated_minutes' => (int) ($this->estimated_minutes ?? $this->duration_minutes ?? 0),
            'days_per_week'     => (int) ($this->days_per_week ?? 0),
            'description'       => $this->description ?? '',
            'notes'             => $this->notes ?? '',
            'is_assigned'       => (bool) $this->is_assigned,
            'created_by_admin'  => (bool) $this->created_by_admin,
            'exercises'         => $exercises,
            'exercise_count'    => count($exercises),
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function serializeRoutineExercise(RoutineExercise $re): array
    {
        /** @var Exercise|null $ex */
        $ex = $re->exercise;

        return [
            'id'         => (string) $re->id,
            'sets'       => (int) $re->sets,
            'reps'       => (string) ($re->reps ?: '10'),
            'weight'     => $re->weight !== null ? (float) $re->weight : null,
            'notes'      => $re->notes ?? '',
            'sort_order' => (int) $re->sort_order,
            'exercise'   => $ex ? [
                'id'                => (string) $ex->id,
                'name'              => $ex->name,
                'muscle_group'      => $ex->muscle_group ?? $ex->body_part ?? '',
                'equipment'         => $ex->equipment ?? '',
                'difficulty'        => $ex->difficulty ?? 'Principiante',
                'description'       => $ex->description ?? '',
                'steps'             => $ex->steps ?? [],
                'tips'              => $ex->tips ?? [],
                'common_mistakes'   => $ex->common_mistakes ?? [],
                'secondary_muscles' => $ex->secondary_muscles ?? [],
                'muscles_worked'    => $ex->muscles_worked ?? [],
                'suggested_sets'    => (int) ($ex->suggested_sets ?? 3),
                'suggested_reps'    => $ex->suggested_reps ?? '8-12',
                'gif_url'           => $ex->gif_url,
                'thumbnail_url'     => $ex->thumbnail_url,
                'video_url'         => $ex->video_path,
            ] : null,
        ];
    }
}
