<?php

namespace App\Http\Resources;

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
            ->map(fn (RoutineExercise $re): array => [
                'id'           => $re->id,
                'exercise_id'  => $re->exercise_id,
                'name'         => $re->exercise?->name ?? '',
                'muscle_group' => $re->exercise?->muscle_group ?? $re->exercise?->body_part ?? '',
                'sets'         => (int) $re->sets,
                'reps'         => (int) $re->reps,
                'weight'       => $re->weight ?? '',
                'notes'        => $re->notes ?? '',
                'sort_order'   => (int) $re->sort_order,
                'gif_url'      => $re->exercise?->gif_url,
                'thumbnail_url'=> $re->exercise?->thumbnail_url,
            ])
            ->all();

        return [
            'id'                => (string) $this->id,
            'name'              => $this->name,
            'objective'         => $this->objective ?? '',
            'level'             => $this->level ?? '',
            'muscle_group'      => $this->muscle_group ?? '',
            'estimated_minutes' => (int) ($this->estimated_minutes ?? $this->duration_minutes ?? 0),
            'days_per_week'     => (int) $this->days_per_week,
            'description'       => $this->description ?? '',
            'notes'             => $this->notes ?? '',
            'is_assigned'       => (bool) $this->is_assigned,
            'created_by_admin'  => (bool) $this->created_by_admin,
            'exercises'         => $exercises,
            'exercise_count'    => count($exercises),
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }
}
