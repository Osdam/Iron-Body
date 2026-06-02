<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Detecta evaluaciones físicas desactualizadas → emite evaluation.outdated. */
class DetectEvaluationOutdated extends Command
{
    protected $signature = 'ironbody:detect-evaluation-outdated';
    protected $description = 'Detecta miembros con evaluación física desactualizada y emite evaluation.outdated.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', ['--only' => 'evaluation.outdated']);
    }
}
