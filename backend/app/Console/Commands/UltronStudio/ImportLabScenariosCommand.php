<?php

namespace App\Console\Commands\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabScenarios;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Copia el catálogo de escenarios del laboratorio (C-01…C-12, contrato H4
 * §A2) a la tabla `ultron_studio_lab_scenarios`, como escenarios del catálogo
 * de solo lectura.
 *
 * IDEMPOTENTE. La primera vez crea los doce; las siguientes no cambian nada,
 * salvo que el código haya cambiado un escenario (o alguien lo alterase a
 * mano): entonces lo SINCRONIZA con la revisión +1, y ARCHIVA los que el código
 * retiró. Lo deja todo en el libro del laboratorio, con su antes y su después.
 * Nunca toca un escenario del personal (E-NNN). Todo va en una transacción.
 *
 * El actor es `system` (este comando). Funciona con la bandera del laboratorio
 * APAGADA: preparar los escenarios no gasta nada ni llama a nadie.
 * `--dry-run` enseña qué haría sin escribir nada.
 *
 * No ejecuta nada ni emite veredictos: solo copia escenarios.
 */
class ImportLabScenariosCommand extends Command
{
    protected $signature = 'ultron-studio:lab-import-scenarios {--dry-run : Enseña qué haría, sin escribir nada}';

    protected $description = 'Importa (o sincroniza) los escenarios del catálogo del laboratorio de ULTRON Studio';

    public function handle(StudioLabScenarios $scenarios): int
    {
        $simular = (bool) $this->option('dry-run');

        try {
            $plan = $scenarios->importCatalog($simular);
        } catch (UniqueConstraintViolationException) {
            // Otra importación a la vez creó los mismos escenarios: esta se
            // deshizo entera. Volver a ejecutarla no cambia nada si la otra terminó.
            $this->error('Otra importación del catálogo del laboratorio se adelantó y esta no escribió nada. Vuelve a ejecutarla.');

            return self::FAILURE;
        } catch (UltronStudioException $e) {
            // Un escenario del personal ocupa un código del catálogo, o una fila
            // cambió mientras se importaba: no se tocó nada.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $filas = [];
        foreach (['created' => 'nuevo', 'updated' => 'sincronizado', 'unchanged' => 'sin cambios', 'retired' => 'retirado (archivado)'] as $clave => $accion) {
            foreach ($plan[$clave] as $codigo) {
                $filas[] = [$codigo, $accion];
            }
        }
        usort($filas, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $this->table(['Escenario', $simular ? 'Haría' : 'Resultado'], $filas);

        $resumen = count($plan['created']).' nuevos, '.count($plan['updated']).' sincronizados, '
            .count($plan['unchanged']).' sin cambios, '.count($plan['retired']).' retirados';
        $etiqueta = 'Catálogo del laboratorio '.StudioLabCatalog::VERSION;
        if ($simular) {
            $this->info("{$etiqueta} (simulación, no se escribió nada): {$resumen}.");
        } elseif ($plan['created'] === [] && $plan['updated'] === [] && $plan['retired'] === []) {
            $this->info("{$etiqueta}: nada que importar ({$resumen}).");
        } else {
            $this->info("{$etiqueta}: {$resumen}. Queda en el libro del laboratorio.");
        }

        return self::SUCCESS;
    }
}
