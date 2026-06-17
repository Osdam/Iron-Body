<?php

namespace App\Http\Resources;

use App\Models\MyClass;
use App\Support\ClassStateResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MyClass */
class ClassResource extends JsonResource
{
    /**
     * @param  array<string,mixed>  $memberContext  estado de la sesión de HOY para
     *   el miembro (session_status, my_attendance, can_check_in, can_cancel). Vacío
     *   en contexto CRM.
     * @param  int|null  $reservationId  id de la reserva del miembro (si la tiene).
     */
    public function __construct(
        $resource,
        private bool $isReserved = false,
        private array $memberContext = [],
        private ?int $reservationId = null,
    ) {
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

        // Estado RESUELTO (misma fuente que "Organizar mi semana"): la sesión del
        // entrenador y la asistencia mandan sobre "reservada", para que "Clases"
        // jamás muestre "Reservar" ni "Reservada" cuando ya está en curso/finalizada.
        $sessionStatus = $this->memberContext['session_status'] ?? 'scheduled';
        $attendance = $this->memberContext['my_attendance'] ?? null;
        $displayState = ClassStateResolver::displayState($sessionStatus, (bool) $this->isReserved, $attendance, $available);
        $reservationStatus = ClassStateResolver::reservationStatus((bool) $this->isReserved, $attendance);
        $canReserve = ClassStateResolver::canReserve($sessionStatus, (bool) $this->isReserved, $available);
        $canCancel = ClassStateResolver::canCancel($sessionStatus, (bool) $this->isReserved);

        $dt = $this->date_time ?? $this->resource->operationalOccurrence();

        return array_merge($this->memberContext, [
            // ── Estado resuelto del ciclo de vida (fuente única) ─────────────
            'display_state'      => $displayState,
            'reservation_status' => $reservationStatus,
            'attendance_status'  => $attendance,
            'reservation_id'     => $this->reservationId,
            'can_reserve'        => $canReserve,
            'can_cancel'         => $canCancel,
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
