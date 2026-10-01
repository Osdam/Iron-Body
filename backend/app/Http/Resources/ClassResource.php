<?php

namespace App\Http\Resources;

use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use App\Support\ClassStateResolver;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MyClass */
class ClassResource extends JsonResource
{
    /**
     * @param  array<string,mixed>  $memberContext  estado de la sesión de HOY para
     *                                              el miembro (session_status, my_attendance, can_check_in, can_cancel). Vacío
     *                                              en contexto CRM.
     * @param  int|null  $reservationId  id de la reserva del miembro (si la tiene).
     * @param  bool  $internal  lo pide una sesión de administración: incluye las
     *                          notas internas de la clase.
     */
    public function __construct(
        $resource,
        private bool $isReserved = false,
        private array $memberContext = [],
        private ?int $reservationId = null,
        private bool $internal = false,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        // Cupo de la OCURRENCIA operativa, la misma que reservan la app y el CRM.
        // Sin `reservations_count` precalculado se contaban TODAS las reservas
        // de la clase, de cualquier semana: tras editar una clase el CRM
        // enseñaba 6/20 en una que tenía 0 en su próxima sesión.
        $bookedSpots = $this->reservations_count ?? $this->operationalBooked();
        $totalSpots = $this->max_capacity;
        $available = max(0, $totalSpots - $bookedSpots);

        // Una clase que el backend no aceptaría reservar (borrador o con la
        // reserva online apagada) nunca puede anunciarse como disponible: es la
        // misma regla que aplica `reserve()`, leída de la fuente única.
        $bookable = $this->resource->isBookableByMembers();

        $status = match (true) {
            $this->isReserved => 'reserved',
            ! $bookable => 'unavailable',
            $bookedSpots >= $totalSpots => 'full',
            $available <= 3 => 'few_spots',
            default => 'available',
        };

        // Estado RESUELTO (misma fuente que "Organizar mi semana"): la sesión del
        // entrenador y la asistencia mandan sobre "reservada", para que "Clases"
        // jamás muestre "Reservar" ni "Reservada" cuando ya está en curso/finalizada.
        $sessionStatus = $this->memberContext['session_status'] ?? 'scheduled';
        $attendance = $this->memberContext['my_attendance'] ?? null;
        $displayState = ClassStateResolver::displayState($sessionStatus, (bool) $this->isReserved, $attendance, $available);
        $reservationStatus = ClassStateResolver::reservationStatus((bool) $this->isReserved, $attendance);
        $canReserve = $bookable
            && ClassStateResolver::canReserve($sessionStatus, (bool) $this->isReserved, $available);
        $canCancel = ClassStateResolver::canCancel($sessionStatus, (bool) $this->isReserved);

        // La PRÓXIMA sesión. En una recurrente, `date_time` es la fecha desde la
        // que rige («vigente desde»): publicarla tal cual daba una fecha pasada
        // y la app no la contaba entre las próximas. `operationalOccurrence()`
        // ya devuelve `date_time` en una clase de fecha única.
        $dt = $this->resource->operationalOccurrence();

        return array_merge($this->memberContext, [
            // ── Estado resuelto del ciclo de vida (fuente única) ─────────────
            'display_state' => $displayState,
            'reservation_status' => $reservationStatus,
            'attendance_status' => $attendance,
            'reservation_id' => $this->reservationId,
            'can_reserve' => $canReserve,
            'can_cancel' => $canCancel,
            // ── App fields ───────────────────────────────────────────────
            'id' => (string) $this->id,
            'name' => $this->name,
            'type' => $this->type,
            // El entrenador ASIGNADO manda; el texto libre `instructor` (columna
            // heredada que el CRM ya no escribe) solo si no hay asignado. Al
            // revés, reasignar la clase no cambiaba el nombre que ve el socio.
            'instructor' => $this->trainer?->full_name ?? $this->instructor ?? '',
            'date_time' => $dt ? self::wallTime($dt) : '',
            'duration_minutes' => $this->duration_minutes,
            'total_spots' => $totalSpots,
            'booked_spots' => $bookedSpots,
            'available_spots' => $available,
            'is_reserved' => $this->isReserved,
            'status' => $status,
            'description' => $this->description ?? '',
            // ── CRM fields (keeps Angular working) ───────────────────────
            // `status` es la DISPONIBILIDAD para la app (available, full…); el
            // estado de la clase (active, inactive, finished) va aparte. El CRM
            // leía `status` como estado: sus KPIs daban 0, el filtro no
            // encontraba nada y el interruptor no podía pausar una clase.
            'class_status' => $this->resource->status,
            'max_capacity' => $totalSpots,
            'enrolled_count' => $bookedSpots,
            // La ocurrencia que cuentan `enrolled_count` y la app (día del
            // gimnasio). El CRM la calculaba en el navegador con `toISOString()`
            // y desde las 19:00 la pintaba un día después.
            'next_session_date' => app(ClassBookingService::class)->operationalDate($this->resource),
            // Próximas ocurrencias con su cupo: el calendario del CRM las pinta
            // todas, no solo la siguiente.
            'upcoming_sessions' => $this->upcomingSessions(),
            'day_of_week' => $this->day_of_week,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'location' => $this->location,
            'is_recurring' => (bool) $this->is_recurring,
            'renewal_hours' => $this->renewal_hours,
            'allow_online_booking' => (bool) $this->allow_online_booking,
            'requires_active_plan' => (bool) $this->requires_active_plan,
            // «Notas internas»: solo para el CRM. La lectura del catálogo es pública.
            'notes' => $this->when($this->internal, fn () => $this->notes),
            'trainer_id' => $this->trainer_id,
            'trainer' => $this->whenLoaded('trainer', fn () => [
                'id' => $this->trainer->id,
                'full_name' => $this->trainer->full_name,
            ]),
            'created_at' => $this->created_at,
        ]);
    }

    /**
     * Las próximas sesiones con su cupo. El listado las precalcula en bloque
     * (atributo transitorio del modelo); fuera de él se calculan aquí.
     *
     * @return list<array{session_date:string, booked:int}>
     */
    private function upcomingSessions(): array
    {
        $precalculadas = $this->resource->getAttributes()['upcoming_sessions'] ?? null;
        if (is_array($precalculadas)) {
            return $precalculadas;
        }

        return app(ClassBookingService::class)->upcomingSessionsFor([$this->resource])[$this->resource->getKey()] ?? [];
    }

    /** Reservas de la ocurrencia operativa, contadas por el dominio de reservas. */
    private function operationalBooked(): int
    {
        $booking = app(ClassBookingService::class);

        return $booking->bookedCount($this->resource, $booking->operationalDate($this->resource));
    }

    /**
     * Hora de PARED del gimnasio, sin zona: '2026-10-05T06:00:00'.
     *
     * La app publicada hace `DateTime.tryParse` y lee `.hour` sin pasar a hora
     * local. Con '-05:00' Dart la convierte a UTC y una clase de las 06:00 se
     * pintaba a las 11:00 AM. Sin zona, Dart la toma como hora local del
     * teléfono, que en Colombia es la del gimnasio.
     *
     * Vale para las dos fuentes: una `date_time` guardada ya es hora de pared
     * (el CRM la manda sin zona) y la ocurrencia calculada viene en Bogotá.
     */
    private static function wallTime(CarbonInterface $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s');
    }
}
