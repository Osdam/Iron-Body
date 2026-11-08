<?php

namespace App\Console\Commands\Voice;

use App\Services\Telephony\VoiceRateLimits;
use App\Support\Cache\ExpiredCachePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `voice:prune-rate-limits` — la PURGA de los límites de las cuatro rutas
 * públicas de la voz (PERF8, hallazgo P8-3; B8-c).
 *
 * Cada llamada, cada evento post-llamada y cada aviso estrena su cubo
 * (`voice-rl:<ruta>|<huella>` y su `:timer`, Enmienda 6, E6-4), y la caché
 * `database` solo borra una fila caducada cuando alguien vuelve a leer su
 * clave: esas no las vuelve a leer nadie. Esto borra SOLO las claves
 * `voice-rl:*` CADUCADAS (para la caché ya no existen): ninguna viva, nada más
 * de la caché. Local: sin red, sin proveedores, sin tocar `voice_*`.
 *
 * Con otro almacén del limitador (redis, array…) no hay nada que purgar: lo
 * dice y sale con 0. `--dry-run` solo cuenta. No lo programa este comando:
 * OPS8 lo agenda en `routes/console.php`.
 */
class VoicePruneRateLimitsCommand extends Command
{
    protected $signature = 'voice:prune-rate-limits
        {--dry-run : solo cuenta las claves caducadas, sin borrar}
        {--json : el resumen en JSON}';

    protected $description = 'Purga de la caché database las claves voice-rl:* CADUCADAS de los límites de las rutas públicas de la voz (P8-3). Ninguna viva, nada más.';

    public function handle(): int
    {
        $resumen = ExpiredCachePruner::prune(VoiceRateLimits::PREFIX, (bool) $this->option('dry-run'));
        Log::channel('voice')->info('voice.rate_limits.pruned', [
            'store' => $resumen['store'], 'supported' => $resumen['supported'], 'dry_run' => $resumen['dryRun'],
            'expired' => $resumen['expired'], 'deleted' => $resumen['deleted'],
        ]);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->info('── Voz · purga de los límites (voice-rl:*) ──');
        if (! $resumen['supported']) {
            $this->line("Almacén     : {$resumen['store']} ({$resumen['driver']}): no es la caché database; caducan solas, nada que purgar.");

            return self::SUCCESS;
        }
        $this->line("Almacén     : {$resumen['store']} (database)");
        $this->line('Caducadas   : '.$resumen['expired'].($resumen['dryRun'] ? ' · en seco: no se borra nada' : ' · borradas: '.$resumen['deleted'].' en '.$resumen['batches'].' tanda(s)'));

        return self::SUCCESS;
    }
}
