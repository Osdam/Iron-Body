<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Plan;
use Illuminate\Console\Command;
use Throwable;

/**
 * Marca un plan como facturable o no facturable, POR ID.
 *
 * `billing_enabled=false` separa "plan de acceso" de "venta con comprobante".
 * Es lo que permite que el plan Demo App Review (precio 0, sin tarifa) siga
 * funcionando —conserva acceso a módulos, membresía y features— sin recibir una
 * clasificación tributaria inventada y sin bloquear billing:factus-doctor.
 *
 * Deliberadamente NO localiza por nombre: los nombres cambian y un match difuso
 * sobre el catálogo de producción es justo el tipo de acción que no debe
 * automatizarse. Tampoco toca precio, tarifa, estado activo ni features.
 *
 * Uso:
 *   php artisan billing:set-plan-billing-status 12 false            # simulación
 *   php artisan billing:set-plan-billing-status 12 false --confirm
 */
class SetPlanBillingStatusCommand extends Command
{
    protected $signature = 'billing:set-plan-billing-status
        {plan_id : ID numérico del plan (nunca el nombre).}
        {enabled : true|false}
        {--confirm : Persiste el cambio. Sin este flag el comando es de solo lectura.}';

    protected $description = 'Activa o desactiva la facturación de un plan por ID, sin tocar precio, tarifa ni accesos.';

    public function handle(): int
    {
        $planId = (int) $this->argument('plan_id');
        $raw = strtolower(trim((string) $this->argument('enabled')));

        if (! in_array($raw, ['true', 'false', '1', '0'], true)) {
            $this->error('El argumento `enabled` debe ser true o false.');

            return self::FAILURE;
        }
        $enabled = in_array($raw, ['true', '1'], true);

        $plan = Plan::with('taxRate')->find($planId);
        if ($plan === null) {
            $this->error("No existe un plan con ID {$planId}.");

            return self::FAILURE;
        }

        $previous = (bool) $plan->billing_enabled;

        $this->table(['Campo', 'Valor'], [
            ['ID', $plan->id],
            ['Nombre', $plan->name],
            ['Precio', number_format((float) $plan->price, 2, ',', '.')],
            ['Activo', $plan->active ? 'sí' : 'no'],
            ['Tarifa', $plan->taxRate?->name ?? '— sin asignar —'],
            ['pricing_mode', $plan->pricing_mode ?? 'legacy_inclusive'],
            ['billing_enabled (antes)', $previous ? 'true' : 'false'],
            ['billing_enabled (después)', $enabled ? 'true' : 'false'],
        ]);

        if ($previous === $enabled) {
            $this->info('Sin cambios: el plan ya está en ese estado.');

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->warn('SIMULACIÓN: no se escribió nada. Añade --confirm para aplicar.');

            return self::SUCCESS;
        }

        // Solo se toca esta columna: ni precio, ni tarifa, ni active, ni features.
        $plan->forceFill(['billing_enabled' => $enabled])->save();

        $this->auditChange($plan, $previous, $enabled);

        $this->info("Plan #{$plan->id}: billing_enabled = ".($enabled ? 'true' : 'false').'.');
        if (! $enabled) {
            $this->line('El plan conserva su acceso y sus features; ya no exige tratamiento tributario.');
        }

        return self::SUCCESS;
    }

    private function auditChange(Plan $plan, bool $previous, bool $enabled): void
    {
        try {
            AuditLog::create([
                'action' => 'settings',
                'module' => 'billing',
                'entity' => 'plan',
                'entity_id' => (string) $plan->id,
                'target_name' => $plan->name,
                'actor_name' => 'console:billing:set-plan-billing-status',
                'summary' => 'Cambio de billing_enabled del plan',
                'changes' => [
                    'billing_enabled' => ['from' => $previous, 'to' => $enabled],
                ],
            ]);
        } catch (Throwable $e) {
            $this->warn('No se pudo registrar la auditoría: '.$e->getMessage());
        }
    }
}
