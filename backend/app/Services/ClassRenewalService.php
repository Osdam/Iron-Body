<?php

namespace App\Services;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\MyClass;
use Illuminate\Support\Facades\DB;

/**
 * Renovación de clases FIJAS/recurrentes.
 *
 * Cada clase define `renewal_hours` (cada cuánto se reabre el ciclo). Cuando una
 * sesión FINALIZÓ hace >= renewal_hours, la clase "renueva":
 *   1. Se limpian las reservas del ciclo anterior (los miembros reservan de nuevo).
 *   2. Se resetea enrolled_count.
 *   3. La sesión finalizada se marca `renewed_at` (archivada) → deja de ser la
 *      sesión vigente del miembro, pero se CONSERVA para la supervisión/historial
 *      (incluida la asistencia registrada). No se borra evidencia.
 *
 * Idempotente: una sesión ya renovada no se vuelve a tocar. Clases con
 * renewal_hours NULL o 0 NO renuevan automáticamente (gestión manual).
 */
class ClassRenewalService
{
    /**
     * Renueva todas las clases cuya última sesión finalizada cumplió su ventana.
     *
     * @return int número de clases renovadas
     */
    public function renewDue(): int
    {
        // Sesiones finalizadas, aún no archivadas, de clases con auto-renovación.
        $sessions = ClassSession::query()
            ->whereNotNull('ended_at')
            ->whereNull('renewed_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('classes')
                    ->whereColumn('classes.id', 'class_sessions.class_id')
                    ->whereNotNull('classes.renewal_hours')
                    ->where('classes.renewal_hours', '>', 0);
            })
            ->with('gymClass:id,renewal_hours')
            ->get();

        $dueClassIds = [];
        foreach ($sessions as $session) {
            $hours = (int) ($session->gymClass?->renewal_hours ?? 0);
            if ($hours <= 0) {
                continue;
            }
            // Aún dentro de la ventana → todavía se muestra como "finalizada".
            if ($session->ended_at->copy()->addHours($hours)->isFuture()) {
                continue;
            }
            $dueClassIds[$session->class_id] = true;
        }

        if ($dueClassIds === []) {
            return 0;
        }

        $ids = array_keys($dueClassIds);

        DB::transaction(function () use ($ids): void {
            ClassReservation::whereIn('class_id', $ids)->delete();
            MyClass::whereIn('id', $ids)->update(['enrolled_count' => 0]);
            // Archiva las sesiones finalizadas (conserva el historial/asistencia).
            ClassSession::whereIn('class_id', $ids)
                ->whereNotNull('ended_at')
                ->whereNull('renewed_at')
                ->update(['renewed_at' => now()]);
        });

        // Refresco en vivo: la clase vuelve a aparecer reservable para todos.
        RealtimeEvents::classesChanged();

        return count($ids);
    }
}
