<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ClassAttendance;
use App\Models\ClassSession;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Contexto de la clase para el MIEMBRO según la sesión de HOY: si está en curso,
 * finalizada, su asistencia y qué acciones puede hacer (check-in / cancelar).
 * Compartido por los controladores que sirven clases a la app.
 */
trait MemberClassContext
{
    /** Zona horaria operativa del gimnasio (el backend corre en UTC). */
    public const OPERATIONAL_TZ = 'America/Bogota';

    /** Respuesta única cuando el plan no incluye clases. */
    protected function classesNotInPlanResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Tu plan no incluye reserva de clases.',
            'code' => 'classes_not_in_plan',
        ], 403);
    }

    /**
     * "Hoy" del gimnasio. `Carbon::today()` devuelve el día en UTC, que en
     * Colombia (UTC-5) ya avanzó al día siguiente desde las 19:00: usarlo para
     * filtrar reservas descartaba las de la clase en curso y el cupo se veía
     * libre cuando ya estaba lleno.
     */
    protected function operationalToday(): Carbon
    {
        return Carbon::today(self::OPERATIONAL_TZ);
    }

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
     * @param  Collection<int, int>  $classIds
     * @return Collection<int, ClassSession>
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
                $q->whereDate('session_date', $this->operationalToday())
                    ->orWhere(fn ($q2) => $q2->whereNotNull('started_at')->whereNull('ended_at'))
                    ->orWhere(fn ($q2) => $q2->whereNotNull('ended_at')
                        ->where('session_date', '>=', $this->operationalToday()->subDays(8)));
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
            'my_attendance' => $myAttendance,
            'can_check_in' => $reserved && $status === 'live' && $myAttendance !== ClassAttendance::STATUS_PRESENT,
            'can_cancel' => $reserved && $status === 'scheduled',
        ];
    }

    /**
     * Estado OFICIAL de una sesión de clase ('finished' | 'live' | 'scheduled'),
     * controlado por el entrenador y NUNCA por la hora local.
     *
     * Delegado: la regla vive en {@see ClassBookingService::sessionStatus()},
     * que es también la que decide si se puede reservar o cancelar.
     */
    protected function sessionStatusLabel(?ClassSession $session): string
    {
        return app(ClassBookingService::class)->sessionStatus($session);
    }

    /** Cupo ocupado de una ocurrencia (reservas de esa fecha + legacy sin fecha). */
    protected function occurrenceBookedCount(MyClass $class, string $date): int
    {
        return app(ClassBookingService::class)->bookedCount($class, $date);
    }
}
