<?php

namespace App\Support;

use App\Models\ClassAttendance;

/**
 * FUENTE ÚNICA del estado resuelto de una clase/fecha para el miembro. La usan
 * por igual la pantalla normal de "Clases" (ClassResource) y "Organizar mi
 * semana" (AppClassController@weeklyPlan), para que NUNCA muestren estados
 * distintos de la misma clase/fecha/usuario y Flutter no invente estados.
 *
 * Separa tres conceptos:
 *   - session_status: scheduled | live | finished  (lo controla el entrenador)
 *   - reservation_status: none | reserved | attended | late | absent
 *   - display_state: estado RESUELTO para pintar, con prioridad fija.
 *
 * Prioridad (regla central, NO se puede invertir):
 *   finalizada/cerrada > en curso > asistencia > reservada > llena > vencida >
 *   pocos cupos > disponible.
 * Así "Reservada" jamás tapa "En curso" ni "Finalizada".
 */
final class ClassStateResolver
{
    /**
     * Estado de la RESERVA del miembro, independiente de la disponibilidad. La
     * asistencia (present/late/absent) es más específica y tiene prioridad sobre
     * "reserved". "cancelled" no se persiste: cancelar borra la fila → 'none'.
     */
    public static function reservationStatus(bool $reserved, ?string $attendance): string
    {
        return match ($attendance) {
            ClassAttendance::STATUS_PRESENT => 'attended',
            ClassAttendance::STATUS_LATE    => 'late',
            ClassAttendance::STATUS_ABSENT  => 'absent',
            default                         => $reserved ? 'reserved' : 'none',
        };
    }

    /**
     * Estado RESUELTO para pintar. [$available] son cupos libres de la ocurrencia;
     * [$isPast] = día operativo ya vencido sin sesión real (solo aplica al plan
     * semanal). Nunca se calcula "finalizada/en curso" por reloj: viene de la
     * sesión del entrenador ([$sessionStatus]).
     */
    public static function displayState(
        string $sessionStatus,
        bool $reserved,
        ?string $attendance,
        int $available,
        bool $isPast = false,
    ): string {
        if ($sessionStatus === 'finished') {
            return match ($attendance) {
                ClassAttendance::STATUS_PRESENT => 'attended',
                ClassAttendance::STATUS_LATE    => 'late',
                ClassAttendance::STATUS_ABSENT  => 'absent',
                default                         => 'finished',
            };
        }
        if ($sessionStatus === 'live') {
            return 'live';
        }
        if ($reserved) {
            return 'reserved';
        }
        if ($available <= 0) {
            return 'full';
        }
        if ($isPast) {
            return 'unavailable';
        }
        if ($available <= 3) {
            return 'few_spots';
        }

        return 'available';
    }

    /** Solo se reserva una sesión programada, con cupo, no reservada y no vencida. */
    public static function canReserve(string $sessionStatus, bool $reserved, int $available, bool $isPast = false): bool
    {
        return $sessionStatus === 'scheduled' && ! $reserved && $available > 0 && ! $isPast;
    }

    /** Solo se cancela una reserva de una sesión aún programada (no iniciada/cerrada/vencida). */
    public static function canCancel(string $sessionStatus, bool $reserved, bool $isPast = false): bool
    {
        return $reserved && $sessionStatus === 'scheduled' && ! $isPast;
    }
}
