<?php

namespace App\Console\Commands;

use App\Services\Contracts\ContractTemplateService;
use Illuminate\Console\Command;

/**
 * Registra/actualiza las filas de `contract_templates` a partir de
 * config/contracts.php y de los PDFs oficiales en disco privado. NO copia ni
 * sobrescribe los archivos fuente (eso es parte del despliegue manual): solo
 * lee, calcula checksum y registra metadatos. Reporta plantillas faltantes.
 */
class InstallContractTemplates extends Command
{
    protected $signature = 'contracts:install-templates';

    protected $description = 'Registra/verifica las plantillas oficiales de contratos (checksums incluidos).';

    public function handle(ContractTemplateService $templates): int
    {
        $summary = $templates->syncFromConfig();
        $missing = [];

        $this->table(
            ['Plantilla', 'Existe', 'Checksum (sha256)', 'Ruta'],
            collect($summary)->map(function (array $row, string $key) use (&$missing) {
                if (! $row['exists']) {
                    $missing[] = $key;
                }

                return [
                    $key,
                    $row['exists'] ? 'sí' : 'NO',
                    $row['checksum'] ? substr($row['checksum'], 0, 16).'…' : '—',
                    $row['path'],
                ];
            })->values()->all()
        );

        if (! empty($missing)) {
            $this->error('Faltan plantillas oficiales: '.implode(', ', $missing));
            $this->line('Cópielas a la carpeta indicada (no se versionan en git). '.
                'Ver docs/contracts/LEGAL_REVIEW_REQUIRED.md.');

            return self::FAILURE;
        }

        $this->info('Todas las plantillas están instaladas y registradas.');

        return self::SUCCESS;
    }
}
