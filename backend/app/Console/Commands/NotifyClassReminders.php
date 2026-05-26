<?php

namespace App\Console\Commands;

use App\Models\MyClass;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recordatorio de clases próximas a comenzar para los miembros inscritos.
 *
 *   php artisan notifications:class-reminders            (ventana 3 horas)
 *   php artisan notifications:class-reminders --hours=2
 *
 * Usa MyClass::nextOccurrence() (datos reales: date_time o day_of_week+start_time)
 * y solo notifica a quienes tienen reserva activa. Idempotente: NotificationService
 * deduplica por class_reminder_CLASSID_MEMBERID_FECHA_HORA, así que correrlo cada
 * pocos minutos no genera duplicados para la misma franja.
 */
class NotifyClassReminders extends Command
{
    protected $signature = 'notifications:class-reminders {--hours=3}';

    protected $description = 'Genera recordatorios para clases próximas a comenzar';

    public function handle(NotificationService $notifications): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $now   = Carbon::now();
        $limit = $now->copy()->addHours($hours);

        $classes = MyClass::query()
            ->where('status', 'active')
            ->with(['reservations.member'])
            ->get();

        $count = 0;
        foreach ($classes as $class) {
            $next = $class->nextOccurrence();
            if (! $next || $next->lt($now) || $next->gt($limit)) {
                continue;
            }

            foreach ($class->reservations as $reservation) {
                $member = $reservation->member;
                if (! $member) {
                    continue;
                }
                $notifications->notifyClassReminder($member, $class, $next);
                $count++;
            }
        }

        $this->info("Recordatorios de clase generados: {$count} (ventana {$hours} h).");

        return self::SUCCESS;
    }
}
