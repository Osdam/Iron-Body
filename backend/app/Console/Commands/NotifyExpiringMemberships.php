<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Notifica a los miembros cuya membresía vence dentro de N días.
 *
 *   php artisan notifications:membership-expiring          (3 días por defecto)
 *   php artisan notifications:membership-expiring --days=5
 *
 * Idempotente: NotificationService deduplica por
 * membership_expiring_MEMBERID_DATE, así que correrlo a diario no spamea.
 * Programar en routes/console.php o el scheduler del sistema.
 */
class NotifyExpiringMemberships extends Command
{
    protected $signature = 'notifications:membership-expiring {--days=3}';

    protected $description = 'Genera notificaciones para membresías próximas a vencer';

    public function handle(NotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $today = Carbon::today();
        $limit = $today->copy()->addDays($days);

        $users = User::query()
            ->whereNotNull('membership_end_date')
            ->whereDate('membership_end_date', '>=', $today)
            ->whereDate('membership_end_date', '<=', $limit)
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $member = Member::where('user_id', $user->id)->first();
            if (! $member) {
                continue;
            }
            $notifications->notifyMembershipExpiring($member, [
                'name'                => $user->plan ?: 'tu plan',
                'membership_end_date' => $user->membership_end_date,
            ]);
            $count++;
        }

        $this->info("Membresías próximas a vencer notificadas: {$count} (ventana {$days} días).");

        return self::SUCCESS;
    }
}
