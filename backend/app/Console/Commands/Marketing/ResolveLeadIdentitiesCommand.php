<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingLeadIdentity;
use App\Services\Marketing\Attribution\LeadIdentityResolver;
use Illuminate\Console\Command;

/**
 * Recalcula quién es, en el CRM, la persona de cada lead real (regla D7).
 *
 * Escribe SOLO la tabla derivada `marketing_lead_identities`: nunca
 * `marketing_leads.member_id`, que es un enlace confirmado por una persona.
 * Repetirlo no duplica nada; una pasada sin cambios no escribe ninguna fila, y
 * las filas se escriben en orden de lead.
 *
 * Es el ÚNICO que escribe esa tabla. El panel resuelve en memoria los leads del
 * periodo que mira y no guarda nada: una lectura no escribe en la base. Esto
 * sirve para tener la tabla completa, por ejemplo antes de una auditoría. Lee
 * socios y usuarios de la base, no del índice en caché del panel, y de paso lo
 * renueva.
 */
class ResolveLeadIdentitiesCommand extends Command
{
    protected $signature = 'marketing:resolve-lead-identities
        {--dry-run : Calcula y cuenta sin escribir nada}';

    protected $description = 'Enlaza cada lead real con su persona del CRM (ficha explícita o teléfono único) en marketing_lead_identities.';

    public function handle(LeadIdentityResolver $resolver): int
    {
        $counts = $resolver->summarize();

        $this->table(
            ['método', 'leads'],
            array_map(fn (string $method) => [$method, $counts[$method] ?? 0], MarketingLeadIdentity::METHODS),
        );

        if ($this->option('dry-run')) {
            $pending = $resolver->syncAll(null, dryRun: true);
            $this->comment("Simulación: se escribirían {$pending} filas (nuevas o cambiadas). No se escribió nada.");

            return self::SUCCESS;
        }

        $written = $resolver->syncAll();
        $this->info("Listo: {$written} filas nuevas o actualizadas en marketing_lead_identities.");

        return self::SUCCESS;
    }
}
