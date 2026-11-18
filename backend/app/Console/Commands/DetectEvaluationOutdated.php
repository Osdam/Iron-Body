<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Detecta a los socios a los que les toca valoración según su seguimiento
 * (CONTRATO_CICLO_VALORACIONES.md §7) → emite `evaluation.outdated`. Delega en
 * `ironbody:emit-automation-events --only=evaluation.outdated`, donde vive la
 * regla.
 */
class DetectEvaluationOutdated extends Command
{
    protected $signature = 'ironbody:detect-evaluation-outdated';

    protected $description = 'Detecta socios con la próxima valoración vencida según su seguimiento y emite evaluation.outdated.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', ['--only' => 'evaluation.outdated']);
    }
}
