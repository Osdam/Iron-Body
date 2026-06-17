<?php

namespace App\Http\Resources;

use App\Models\MyClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MyClass */
class ClassResource extends JsonResource
{
    /**
     * @param  array<string,mixed>  $memberContext  estado de la sesión de HOY para
     *   el miembro (session_status, my_attendance, can_check_in, can_cancel). Vacío
     *   en contexto CRM.
     */
    public function __construct($resource, private bool $isReserved = false, private array $memberContext = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $bookedSpots = $this->reservations_count ?? $this->reservations()->count();
        $totalSpots  = $this->max_capacity;
        $available   = max(0, $totalSpots - $bookedSpots);

        $status = match (true) {
            $this->isReserved          => 'reserved',
            $bookedSpots >= $totalSpots => 'full',
            $available <= 3            => 'few_spots',
            default                    => 'available',
        };

        $dt = $this->date_time ?? $this->resource->nextOccurrence();

        return array_merge($this->memberContext, [
            // ── App fields ───────────────────────────────────────────────
            'id'               => (string) $this->id,
            'name'             => $this->name,
            'type'             => $this->type,
            'instructor'       => $this->instructor ?? $this->trainer?->full_name ?? '',
            'date_time'        => $dt?->toIso8601String() ?? '',
            'duration_minutes' => $this->duration_minutes,
            'total_spots'      => $totalSpots,
            'booked_spots'     => $bookedSpots,
            'available_spots'  => $available,
            'is_reserved'      => $this->isReserved,
            'status'           => $status,
            'description'      => $this->description ?? '',
            // ── CRM fields (keeps Angular working) ───────────────────────
            'max_capacity'         => $totalSpots,
            'enrolled_count'       => $bookedSpots,
            'day_of_week'          => $this->day_of_week,
            'start_time'           => $this->start_time,
            'end_time'             => $this->end_time,
            'location'             => $this->location,
            'is_recurring'         => (bool) $this->is_recurring,
            'renewal_hours'        => $this->renewal_hours,
            'allow_online_booking' => (bool) $this->allow_online_booking,
            'requires_active_plan' => (bool) $this->requires_active_plan,
            'notes'                => $this->notes,
            'trainer_id'           => $this->trainer_id,
            'trainer'              => $this->whenLoaded('trainer', fn () => [
                'id'        => $this->trainer->id,
                'full_name' => $this->trainer->full_name,
            ]),
            'created_at' => $this->created_at,
        ]);
    }
}
