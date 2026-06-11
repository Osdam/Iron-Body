<?php

namespace App\Console\Commands;

use App\Services\ExerciseProviderService;
use Illuminate\Console\Command;

/**
 * Sincroniza el catálogo de ExerciseDB (RapidAPI) a la tabla `exercises`.
 *
 * Descarga ~1.300 ejercicios con GIF animado (mini-video). La API key vive solo
 * en el backend (.env: EXERCISEDB_RAPIDAPI_KEY). En runtime la app lee la tabla
 * `exercises` (/api/app/exercises), nunca llama a RapidAPI.
 *
 * Requiere EXERCISE_PROVIDER=exercisedb en el .env.
 */
class ExercisedbSyncCommand extends Command
{
    protected $signature = 'exercisedb:sync';
    protected $description = 'Sincroniza el catálogo de ExerciseDB (RapidAPI) con GIFs animados a la tabla exercises';

    public function handle(ExerciseProviderService $exercises): int
    {
        if (config('services.exercises.provider') !== 'exercisedb') {
            $this->warn('EXERCISE_PROVIDER no es "exercisedb". Ajusta el .env y corre config:cache antes de sincronizar.');
            return self::FAILURE;
        }

        $this->info('Sincronizando ExerciseDB (' . config('services.exercisedb.base_url') . ')…');
        $start = microtime(true);

        $res = $exercises->sync();

        $secs = round(microtime(true) - $start);
        $this->newLine();
        $this->info("Listo en {$secs}s — OK: {$res['ok']} · fallos: {$res['fail']}");

        return $res['ok'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
