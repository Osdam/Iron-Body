<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Detecta progreso estancado (peso sin cambio) → emite progress.stalled. */
class DetectProgressStalled extends Command
{
    protected $signature = 'ironbody:detect-progress-stalled';
    protected $description = 'Detecta miembros con progreso estancado y emite progress.stalled.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', ['--only' => 'progress.stalled']);
    }
}
