<?php

namespace App\Console\Commands;

use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Console\Command;

/**
 * Reconcilia pagos Wompi en vuelo (pending/requires_action) consultando el
 * estado real en Wompi. Respaldo del webhook: nunca aprueba localmente, activa
 * membresía de forma idempotente solo si Wompi responde APPROVED.
 */
class WompiReconcile extends Command
{
    protected $signature = 'payments:wompi-reconcile {--limit=100 : Máximo de transacciones a revisar}';
    protected $description = 'Reconcilia pagos Wompi pendientes contra la API de Wompi (respaldo del webhook).';

    public function handle(): int
    {
        // El servicio se CONSTRUYE con su factory: no se inyecta por el contenedor
        // (WompiClient recibe `array $cfg`, que el contenedor no sabe resolver →
        // "Unresolvable dependency resolving array $cfg").
        $service = WompiReconciliationService::make();

        $stats = $service->reconcilePending((int) $this->option('limit'));

        $this->info(sprintf(
            'Wompi reconcile → revisados: %d · actualizados: %d · expirados: %d · sin cambio: %d',
            $stats['checked'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['expired'] ?? 0,
            $stats['skipped'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
