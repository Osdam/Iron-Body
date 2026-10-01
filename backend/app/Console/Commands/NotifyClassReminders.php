<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recordatorio de clases próximas a comenzar para los miembros inscritos.
 *
 *   php artisan notifications:class-reminders            (ventana 3 horas)
 *   php artisan notifications:class-reminders --hours=2
 *
 * La sesión es la MISMA que cuentan la app, el CRM y el entrenador
 * (`ClassBookingService::operationalDate`), su hora es la de pared del gimnasio
 * y el aviso va solo a quien ocupa ESA sesión. Antes se usaba
 * `MyClass::nextOccurrence()`, que leía la hora sobre el reloj UTC (el aviso de
 * una clase de las 18:00 salía a las 10:00) y avisaba a todo el que alguna vez
 * reservó la clase.
 *
 * Idempotente: NotificationService deduplica por
 * class_reminder_CLASSID_MEMBERID_FECHA_HORA (hora del gimnasio), así que
 * correrlo cada pocos minutos no genera duplicados para la misma franja.
 */
class NotifyClassReminders extends Command
{
    protected $signature = 'notifications:class-reminders {--hours=3}';

    protected $description = 'Genera recordatorios para clases próximas a comenzar';

    public function handle(NotificationService $notifications, ClassBookingService $booking): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $now = Carbon::now();
        $limit = $now->copy()->addHours($hours);

        $classes = MyClass::query()->where('status', 'active')->get();

        $count = 0;
        foreach ($classes as $class) {
            $date = $booking->operationalDate($class);
            $start = $booking->occurrenceStart($class, $date);
            if (! $start || $start->lt($now) || $start->gt($limit)) {
                continue;
            }

            // Una sesión que el entrenador ya inició o cerró no está «por comenzar».
            $session = ClassSession::where('class_id', $class->getKey())->whereDate('session_date', $date)->first();
            if ($booking->sessionStatus($session) !== 'scheduled') {
                continue;
            }

            $members = Member::query()->whereIn('id', $booking->occurrenceMemberIds($class, $date))->get();
            foreach ($members as $member) {
                $notifications->notifyClassReminder($member, $class, $start);
                $count++;
            }
        }

        $this->info("Recordatorios de clase generados: {$count} (ventana {$hours} h).");

        return self::SUCCESS;
    }
}
