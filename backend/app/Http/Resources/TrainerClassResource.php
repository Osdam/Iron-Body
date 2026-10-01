<?php

namespace App\Http\Resources;

use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Clase en la agenda del entrenador. Expone aforo real (capacidad vs inscritos)
 * y la próxima ocurrencia. `reservations_count` lo fija el controlador con el
 * cupo de la OCURRENCIA operativa (por session_date), no el histórico; si no
 * está disponible cae a `enrolled_count`. `session_date` dice de qué sesión es
 * ese cupo: el controlador la fija en `booked_session_date`.
 *
 * @mixin MyClass
 */
class TrainerClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enrolled = $this->reservations_count ?? $this->enrolled_count ?? 0;
        $booking = app(ClassBookingService::class);
        $operativa = $booking->operationalDate($this->resource);

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
            'session_date' => $this->resource->getAttributes()['booked_session_date'] ?? $operativa,
            // Misma ocurrencia operativa (Bogotá, por día) que usa el resto del
            // sistema para reservar/contar, como instante con su zona. Antes, en
            // una clase de fecha única, la hora de pared salía marcada como UTC.
            'next_occurrence' => $booking->occurrenceStart($this->resource, $operativa)?->toIso8601String(),
        ];
    }
}
