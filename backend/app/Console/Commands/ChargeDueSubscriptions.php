<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\RecurringBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cobra las suscripciones de membresía vencidas (pago automático). Respeta el
 * feature flag: con WOMPI_RECURRING_ENABLED=false NO cobra ni toca Wompi. El
 * servicio es idempotente (lock por suscripción + billing_period único), así que
 * ejecutarlo varias veces no cobra dos veces. Resumen sin secretos en logs.
 */
class ChargeDueSubscriptions extends Command
{
    protected $signature = 'subscriptions:charge-due {--limit=100 : Máximo de suscripciones a procesar}';
    protected $description = 'Cobra las suscripciones de membresía vencidas (pago automático Wompi).';

    public function handle(): int
    {
        if (! (bool) config('wompi.recurring.enabled', false)) {
            $this->info('Pago automático deshabilitado (WOMPI_RECURRING_ENABLED=false); nada que cobrar.');
            return self::SUCCESS;
        }

        // Factory: WompiClient recibe `array $cfg` que el contenedor no resuelve.
        $stats = RecurringBillingService::make()->chargeDue((int) $this->option('limit'));

        $summary = sprintf(
            'Cobro recurrente → seleccionadas: %d · aprobadas: %d · rechazadas: %d · pendientes: %d · past_due: %d · omitidas: %d',
            $stats['selected'] ?? 0,
            $stats['approved'] ?? 0,
            $stats['declined'] ?? 0,
            $stats['pending'] ?? 0,
            $stats['past_due'] ?? 0,
            $stats['skipped'] ?? 0,
        );

        $this->info($summary);
        Log::info('subscriptions.charge_due.command', $stats);

        return self::SUCCESS;
    }
}
