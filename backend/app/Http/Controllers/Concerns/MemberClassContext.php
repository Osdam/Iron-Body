<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contexto de la clase para el MIEMBRO según la sesión de HOY: si está en curso,
 * finalizada, su asistencia y qué acciones puede hacer (check-in / cancelar).
 * Compartido por los controladores que sirven clases a la app.
 */
trait MemberClassContext
{
    /**
     * Sesión relevante de la clase para el miembro. Prioriza: (1) EN CURSO
     * (iniciada y sin finalizar), (2) recién FINALIZADA (en las últimas 8 h, para
     * mostrar "finalizada/espera" hasta la próxima clase), (3) la de HOY. No
     * depende de que la fecha calce exacto (evita desajustes de zona horaria).
     */
    protected function currentClassSession(MyClass $class): ?ClassSession
    {
        return $this->relevantSessionQuery()
            ->where('class_id', $class->getKey())
            ->first();
    }

    /**
     * Sesión relevante de varias clases en bloque (sin N+1), una por clase.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $classIds
     * @return \Illuminate\Support\Collection<int, ClassSession>
     */
    protected function relevantSessionsFor($classIds)
    {
        return $this->relevantSessionQuery()
            ->whereIn('class_id', $classIds)
            ->get()
            ->unique('class_id')
            ->keyBy('class_id');
    }

    /**
     * Query base: en curso primero, luego recién finalizada / hoy; más reciente.
     * Excluye sesiones ya RENOVADAS (archivadas tras cumplir su ciclo): esas
     * dejan de ser la sesión vigente, de modo que la clase vuelve a aparecer como
     * reservable para el miembro. El estado "finalizada" persiste hasta que la
     * clase renueva (ver ClassRenewalService); el tope de 8 días es solo una cota
     * de seguridad para clases configuradas como "no renovar".
     */
    private function relevantSessionQuery()
    {
        return ClassSession::query()
            ->whereNull('renewed_at')
            ->where(function ($q) {
                $q->whereDate('session_date', Carbon::today())
                    ->orWhere(fn ($q2) => $q2->whereNotNull('started_at')->whereNull('ended_at'))
                    ->orWhere(fn ($q2) => $q2->whereNotNull('ended_at')
                        ->where('session_date', '>=', Carbon::today()->subDays(8)));
            })
            ->orderByRaw('CASE WHEN started_at IS NOT NULL AND ended_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('session_date')
            ->orderByDesc('id');
    }

    /**
     * @return array{session_status:string, my_attendance:?string, can_check_in:bool, can_cancel:bool}
     */
    protected function memberClassContext(bool $reserved, ?ClassSession $session, ?ClassAttendance $attendance): array
    {
        $status = $this->sessionStatusLabel($session);

        $myAttendance = $attendance?->status;

        return [
            'session_status' => $status,
            'my_attendance'  => $myAttendance,
            'can_check_in'   => $reserved && $status === 'live' && $myAttendance !== ClassAttendance::STATUS_PRESENT,
            'can_cancel'     => $reserved && $status === 'scheduled',
        ];
    }

    /**
     * Estado OFICIAL de una sesión de clase, controlado por el entrenador/backend
     * (NUNCA por la hora local): 'finished' si el entrenador la cerró
     * (`ended_at`), 'live' si la inició y no la ha cerrado (`started_at`), o
     * 'scheduled' en cualquier otro caso. Fuente única de verdad para el estado,
     * compartida por Clases, el detalle y "Organizar mi semana".
     */
    protected function sessionStatusLabel(?ClassSession $session): string
    {
        if ($session && $session->ended_at) {
            return 'finished';
        }
        if ($session && $session->started_at) {
            return 'live';
        }

        return 'scheduled';
    }

    /**
     * Reserva una ocurrencia (clase + fecha de sesión) de forma transaccional y
     * segura ante concurrencia: bloquea la clase (Postgres; no-op en SQLite de
     * tests), valida cupo POR FECHA y anti-doble reserva (tolerante a la reserva
     * legacy sin fecha = ciclo vigente). Devuelve: reserved | already | full.
     * Compartido por la reserva individual y la reserva semanal en lote.
     */
    protected function reserveOccurrence(Member $member, MyClass $class, string $date): string
    {
        return DB::transaction(function () use ($member, $class, $date): string {
            $locked = MyClass::whereKey($class->getKey())->lockForUpdate()->first() ?? $class;

            $already = ClassReservation::where('class_id', $class->getKey())
                ->where('member_id', $member->getKey())
                ->where(function ($q) use ($date): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $date);
                })
                ->exists();
            if ($already) {
                return 'already';
            }

            $booked = ClassReservation::where('class_id', $class->getKey())
                ->where(function ($q) use ($date): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $date);
                })
                ->lockForUpdate()
                ->count();
            if ($booked >= (int) $locked->max_capacity) {
                return 'full';
            }

            ClassReservation::create([
                'class_id'     => $class->getKey(),
                'member_id'    => $member->getKey(),
                'session_date' => $date,
                'reserved_at'  => now(),
            ]);

            return 'reserved';
        });
    }

    /** Cupo ocupado de una ocurrencia (reservas de esa fecha + legacy sin fecha). */
    protected function occurrenceBookedCount(MyClass $class, string $date): int
    {
        return (int) ClassReservation::where('class_id', $class->getKey())
            ->where(function ($q) use ($date): void {
                $q->whereNull('session_date')->orWhereDate('session_date', $date);
            })
            ->count();
    }
}
