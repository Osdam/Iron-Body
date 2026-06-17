<?php

namespace App\Console\Commands;

use App\Services\ClassRenewalService;
use Illuminate\Console\Command;

/**
 * Renueva clases fijas/recurrentes: reabre el ciclo de reservas según la
 * frecuencia (`renewal_hours`) de cada clase. Agendado cada hora en
 * routes/console.php. Idempotente.
 *
 *   php artisan classes:renew
 */
class ClassesRenew extends Command
{
    protected $signature = 'classes:renew';

    protected $description = 'Renueva clases fijas: reabre reservas según su frecuencia de renovación.';

    public function handle(ClassRenewalService $service): int
    {
        $renewed = $service->renewDue();
        $this->info("Clases renovadas: {$renewed}");

        return self::SUCCESS;
    }
}
