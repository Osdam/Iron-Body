<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ClassAttendance;
use App\Models\ClassSession;
use App\Models\MyClass;
use Illuminate\Support\Carbon;

/**
 * Contexto de la clase para el MIEMBRO según la sesión de HOY: si está en curso,
 * finalizada, su asistencia y qué acciones puede hacer (check-in / cancelar).
 * Compartido por los controladores que sirven clases a la app.
 */
trait MemberClassContext
{
    protected function todayClassSession(MyClass $class): ?ClassSession
    {
        return ClassSession::where('class_id', $class->getKey())
            ->whereDate('session_date', Carbon::today())
            ->first();
    }

    /**
     * @return array{session_status:string, my_attendance:?string, can_check_in:bool, can_cancel:bool}
     */
    protected function memberClassContext(bool $reserved, ?ClassSession $session, ?ClassAttendance $attendance): array
    {
        $status = 'scheduled';
        if ($session && $session->ended_at) {
            $status = 'finished';
        } elseif ($session && $session->started_at) {
            $status = 'live';
        }

        $myAttendance = $attendance?->status;

        return [
            'session_status' => $status,
            'my_attendance'  => $myAttendance,
            'can_check_in'   => $reserved && $status === 'live' && $myAttendance !== ClassAttendance::STATUS_PRESENT,
            'can_cancel'     => $reserved && $status === 'scheduled',
        ];
    }
}
