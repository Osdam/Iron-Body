<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Detecta miembros sin registrar nutrición → emite nutrition.missing. */
class DetectNutritionMissing extends Command
{
    protected $signature = 'ironbody:detect-nutrition-missing {--nutrition-days=2}';
    protected $description = 'Detecta miembros sin registrar nutrición y emite nutrition.missing.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', [
            '--only' => 'nutrition.missing',
            '--nutrition-days' => $this->option('nutrition-days'),
        ]);
    }
}
