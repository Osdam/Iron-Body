<?php

namespace App\Services;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassEventBus;
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

        // Por clase, la fecha de la última sesión vencida que cumplió su ventana.
        // Solo se limpian las reservas de ese ciclo (<= esa fecha) y las legacy
        // sin fecha; las reservas FUTURAS ("Organizar mi semana") se CONSERVAN.
        $dueUpTo = [];
        foreach ($sessions as $session) {
            $hours = (int) ($session->gymClass?->renewal_hours ?? 0);
            if ($hours <= 0) {
                continue;
            }
            // Aún dentro de la ventana → todavía se muestra como "finalizada".
            if ($session->ended_at->copy()->addHours($hours)->isFuture()) {
                continue;
            }
            $classId = $session->class_id;
            $date = optional($session->session_date)->toDateString() ?? $session->ended_at->toDateString();
            if (! isset($dueUpTo[$classId]) || $date > $dueUpTo[$classId]) {
                $dueUpTo[$classId] = $date;
            }
        }

        if ($dueUpTo === []) {
            return 0;
        }

        $ids = array_keys($dueUpTo);
        $bus = app(ClassEventBus::class);

        // El hecho de cada clase se escribe DENTRO de la transacción, como pide
        // el bus (o quedan el cambio y su hecho, o ninguno), y el lote envuelve
        // la transacción: los avisos salen tras el commit, uno por clase para el
        // CRM y su entrenador y uno solo a los socios, para que la clase vuelva a
        // aparecer reservable para todos.
        $bus->batch(function () use ($dueUpTo, $bus): void {
            DB::transaction(function () use ($dueUpTo, $bus): void {
                // Limpia SOLO el ciclo vencido por clase: reservas con fecha <= la
                // sesión renovada, más las legacy sin fecha. Nunca borra futuras.
                foreach ($dueUpTo as $classId => $upTo) {
                    ClassReservation::where('class_id', $classId)
                        ->where(function ($q) use ($upTo): void {
                            $q->whereNull('session_date')
                                ->orWhereDate('session_date', '<=', $upTo);
                        })
                        ->delete();

                    // Columna heredada: el cupo de la ocurrencia operativa, con la
                    // misma regla que el resto (antes, «todas las que sobreviven»).
                    $clase = MyClass::find($classId);
                    if ($clase !== null) {
                        $booking = app(ClassBookingService::class);
                        MyClass::whereKey($classId)->update([
                            'enrolled_count' => $booking->bookedCount($clase, $booking->operationalDate($clase)),
                        ]);
                    }

                    // Archiva SOLO las sesiones del ciclo vencido (conserva el
                    // historial y la asistencia). Antes archivaba todas las
                    // finalizadas de la clase, también una recién cerrada que aún
                    // no había cumplido su ventana: esa ya no se renovaba nunca y
                    // sus reservas se quedaban.
                    ClassSession::where('class_id', $classId)
                        ->whereNotNull('ended_at')
                        ->whereNull('renewed_at')
                        ->whereDate('session_date', '<=', $upTo)
                        ->update(['renewed_at' => now()]);

                    if ($clase !== null) {
                        $bus->classChanged($clase, 'renewal', ClassEventBus::SOURCE_SYSTEM);
                    }
                }
            });
        });

        return count($ids);
    }
}
