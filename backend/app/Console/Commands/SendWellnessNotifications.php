<?php

namespace App\Console\Commands;

use App\Services\Notifications\WellnessPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tanda diaria de motivación, hábitos y suplementos.
 *
 * Corre varias veces al día a propósito: cada socio tiene sus propias horas de
 * silencio y su zona horaria, así que una sola pasada dejaría fuera a quien
 * duerme cuando corre el cron. La llave de idempotencia (`wellness:socio:fecha`)
 * garantiza que aun así reciba UNA como mucho.
 */
class SendWellnessNotifications extends Command
{
    protected $signature = 'notifications:wellness {--dry-run : Muestra el plan sin enviar nada}';

    protected $description = 'Envía la notificación diaria de motivación, hábitos o suplementos.';

    public function handle(WellnessPlanner $planner): int
    {
        $now = CarbonImmutable::now();

        if ($this->option('dry-run')) {
            $this->warn('Simulación: no se envía nada.');
            $this->line('Usa `notifications:wellness` sin --dry-run para enviar.');

            return self::SUCCESS;
        }

        $stats = $planner->planDaily($now);

        $this->info(sprintf(
            'Considerados: %d · enviados ahora: %d · no enviados: %d · ya resueltos hoy: %d',
            $stats['considered'],
            $stats['sent'],
            $stats['suppressed'],
            $stats['already_handled'],
        ));

        Log::info('notifications.wellness.run', $stats);

        return self::SUCCESS;
    }
}
