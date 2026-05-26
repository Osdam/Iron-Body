<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Notifica a los miembros cuya membresía ya venció (ventana reciente).
 *
 *   php artisan notifications:membership-expired              (últimos 7 días)
 *   php artisan notifications:membership-expired --days=3
 *
 * Solo considera vencimientos recientes (no spamea cuentas vencidas hace meses).
 * Idempotente: NotificationService deduplica por
 * membership_expired_MEMBERID_DATE, así que correrlo a diario no duplica.
 */
class NotifyExpiredMemberships extends Command
{
    protected $signature = 'notifications:membership-expired {--days=7}';

    protected $description = 'Genera notificaciones para membresías recién vencidas';

    public function handle(NotificationService $notifications): int
    {
        $days  = max(1, (int) $this->option('days'));
        $today = Carbon::today();
        $since = $today->copy()->subDays($days);

        $users = User::query()
            ->whereNotNull('membership_end_date')
            ->whereDate('membership_end_date', '<', $today)
            ->whereDate('membership_end_date', '>=', $since)
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $member = Member::where('user_id', $user->id)->first();
            if (! $member) {
                continue;
            }
            $notifications->notifyMembershipExpired($member, [
                'name'                => $user->plan ?: 'tu plan',
                'membership_end_date' => $user->membership_end_date,
            ]);
            $count++;
        }

        $this->info("Membresías vencidas notificadas: {$count} (ventana {$days} días).");

        return self::SUCCESS;
    }
}
