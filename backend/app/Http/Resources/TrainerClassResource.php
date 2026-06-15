<?php

namespace App\Http\Resources;

use App\Models\MyClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Clase en la agenda del entrenador. Expone aforo real (capacidad vs inscritos)
 * y la próxima ocurrencia. `reservations_count` viene de withCount cuando está
 * disponible; si no, cae a `enrolled_count`.
 *
 * @mixin MyClass
 */
class TrainerClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrolled = $this->reservations_count ?? $this->enrolled_count ?? 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'day_of_week' => $this->day_of_week,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'date_time' => $this->date_time,
            'location' => $this->location,
            'status' => $this->status,
            'accepts_attendance' => $this->acceptsAttendance(),
            'max_capacity' => $this->max_capacity,
            'enrolled' => (int) $enrolled,
            'spots_left' => max(0, (int) $this->max_capacity - (int) $enrolled),
            'next_occurrence' => $this->nextOccurrence(),
        ];
    }
}
