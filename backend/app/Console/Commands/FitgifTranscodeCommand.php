<?php

namespace App\Console\Commands;

use App\Services\ExerciseProviderService;
use Illuminate\Console\Command;

/**
 * Genera el MP4 H.264 optimizado de cada GIF FitGif ya cacheado.
 *
 * 100% local (ffmpeg) → NO llama a FitGif ni consume su límite 3/min.
 * El GIF original se conserva como fallback. Idempotente.
 */
class FitgifTranscodeCommand extends Command
{
    protected $signature = 'fitgif:transcode {--force : Re-generar aunque ya exista}';
    protected $description = 'Transcodea a MP4 (1.3x, 24fps, H.264) los GIFs FitGif cacheados';

    public function handle(ExerciseProviderService $exercises): int
    {
        $this->info('Transcodificando GIFs FitGif → MP4 (ffmpeg local)…');
        $start = microtime(true);

        $res = $exercises->fitgifTranscodeAll(
            fn ($line) => $this->line('  ' . $line),
            (bool) $this->option('force'),
        );

        $secs = round(microtime(true) - $start);
        $this->newLine();
        $this->info("Listo en {$secs}s — MP4 OK: {$res['ok']} · fallidos: {$res['fail']} · total: {$res['total']}");

        return self::SUCCESS;
    }
}
