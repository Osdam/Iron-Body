<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Detecta miembros sin entrenar → emite workout.missed. */
class DetectWorkoutMissed extends Command
{
    protected $signature = 'ironbody:detect-workout-missed {--workout-days=3}';
    protected $description = 'Detecta miembros sin entrenar y emite workout.missed.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', [
            '--only' => 'workout.missed',
            '--workout-days' => $this->option('workout-days'),
        ]);
    }
}
