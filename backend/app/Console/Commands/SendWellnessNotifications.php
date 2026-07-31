<?php

namespace App\Console\Commands;

use App\Services\Notifications\WellnessPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tanda de bienestar de la franja en curso.
 *
 * El día se divide en cinco franjas (07:00, 11:00, 15:00, 19:00 y 21:45 en hora
 * de Bogotá) y cada ejecución atiende la que corresponda a la hora actual. La
 * llave de idempotencia (`wellness:socio:fecha:franja`) garantiza que el socio
 * reciba UNA como mucho en cada una, aunque la tanda se dispare dos veces.
 *
 * En producción quien manda la hora es n8n; este comando es el camino de
 * respaldo y el que se usa para probar a mano.
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
            'Franja: %s · considerados: %d · enviados ahora: %d · no enviados: %d · ya resueltos: %d',
            $stats['slot'] ?? 'fuera de horario',
            $stats['considered'],
            $stats['sent'],
            $stats['suppressed'],
            $stats['already_handled'],
        ));

        foreach ($stats as $clave => $valor) {
            if (str_starts_with($clave, 'skipped_') && $valor > 0) {
                $this->line("  · {$clave}: {$valor}");
            }
        }

        Log::info('notifications.wellness.run', $stats);

        return self::SUCCESS;
    }
}
