<?php

namespace App\Console\Commands\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Copia `LEGACY_BASELINE_V1` a la tabla de reglas del Studio: las doce reglas
 * comerciales que hoy APLICA EL CÓDIGO (R-01…R-12), como reglas de solo
 * lectura (`legacy_baseline`, `enforcement = code`).
 *
 * IDEMPOTENTE. La primera vez crea las doce; las siguientes no cambian nada,
 * salvo que alguien haya alterado una fila a mano: entonces la SINCRONIZA con
 * el código y lo deja en la auditoría con su antes y su después. Nunca toca una
 * regla del Studio (S-NNN). Todo va en una transacción: o se aplica entero o no
 * se aplica.
 *
 * El actor de la auditoría es `system` (este comando), no una persona.
 * `--dry-run` enseña qué haría sin escribir nada.
 *
 * No es una versión ni la publica: el motor no lee nada del Studio.
 */
class ImportLegacyBaselineCommand extends Command
{
    protected $signature = 'ultron-studio:import-baseline {--dry-run : Enseña qué haría, sin escribir nada}';

    protected $description = 'Importa (o sincroniza) las reglas del código de LEGACY_BASELINE_V1 en ULTRON Studio';

    public function handle(StudioRules $rules): int
    {
        $simular = (bool) $this->option('dry-run');

        try {
            $plan = $rules->importLegacyBaseline($simular);
        } catch (UniqueConstraintViolationException) {
            // Otra importación a la vez creó las mismas reglas: esta se deshizo
            // entera. Volver a ejecutarla no cambia nada si la otra terminó.
            $this->error('Otra importación de '.StudioCatalog::BASELINE_LABEL.' se adelantó y esta no escribió nada. Vuelve a ejecutarla.');

            return self::FAILURE;
        } catch (UltronStudioException $e) {
            // Una regla del Studio ocupa un código del código: no se toca nada.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $filas = [];
        foreach (['created' => 'nueva', 'updated' => 'sincronizada', 'unchanged' => 'sin cambios'] as $clave => $accion) {
            foreach ($plan[$clave] as $codigo) {
                $filas[] = [$codigo, $accion];
            }
        }
        usort($filas, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $this->table(['Regla', $simular ? 'Haría' : 'Resultado'], $filas);

        foreach ($plan['orphans'] as $codigo) {
            $this->warn("{$codigo} es una regla del código que ya no está en el catálogo: no se toca.");
        }

        $resumen = count($plan['created']).' nuevas, '.count($plan['updated']).' sincronizadas, '.count($plan['unchanged']).' sin cambios';
        if ($simular) {
            $this->info(StudioCatalog::BASELINE_LABEL." (simulación, no se escribió nada): {$resumen}.");
        } elseif ($plan['created'] === [] && $plan['updated'] === []) {
            $this->info(StudioCatalog::BASELINE_LABEL.": nada que importar ({$resumen}).");
        } else {
            $this->info(StudioCatalog::BASELINE_LABEL.": {$resumen}. Queda en la auditoría del Studio.");
        }

        return self::SUCCESS;
    }
}
