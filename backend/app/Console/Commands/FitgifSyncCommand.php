<?php

namespace App\Console\Commands;

use App\Services\ExerciseProviderService;
use Illuminate\Console\Command;

/**
 * Pre-sincroniza los GIFs de FitGif del diccionario de rutinas.
 *
 * Respeta el límite de 3 req/min de FitGif (throttle interno ≥21 s/req),
 * por lo que puede tardar varios minutos. En runtime la app NO llama a
 * FitGif: sirve los GIFs ya descargados.
 */
class FitgifSyncCommand extends Command
{
    protected $signature = 'fitgif:sync';
    protected $description = 'Descarga y cachea los GIFs de FitGif de las rutinas (throttled 3/min)';

    public function handle(ExerciseProviderService $exercises): int
    {
        $this->info('Sincronizando FitGif (throttle 3 req/min, paciencia)…');
        $start = microtime(true);

        $res = $exercises->fitgifSync(fn ($line) => $this->line('  ' . $line));

        $secs = round(microtime(true) - $start);
        $this->newLine();
        $this->info("Listo en {$secs}s — OK: {$res['ok']} · sin GIF: {$res['fail']}");

        return self::SUCCESS;
    }
}
