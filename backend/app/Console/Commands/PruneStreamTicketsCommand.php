<?php

namespace App\Console\Commands;

use App\Services\Admin\StreamTicketService;
use App\Support\Cache\ExpiredCachePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `admin:prune-stream-tickets` — la PURGA de lo que dejan los vales de los
 * canales SSE y de las fotos en la caché (SC-7 de la revisión de seguridad del
 * cierre).
 *
 * Cada vale anota su huella (`admin-stream-ticket:<sha256>`, 60 s) y canjearlo
 * la retira; el limitador de la emisión deja su cubo por persona
 * (`admin-stream-ticket:rate:*`) y el cupo de fotos su cuenta
 * (`admin-stream-ticket:faces`). La caché `database` solo borra una fila
 * caducada cuando alguien vuelve a leer su clave, y un vale que no se gastó
 * (un 429 del canal del Studio, un 5xx o un 499 de nginx, una pestaña cerrada
 * a tiempo) no lo vuelve a leer nadie: se quedaría en la tabla para siempre.
 * Esto borra SOLO las claves `admin-stream-ticket:*` CADUCADAS (para la caché
 * ya no existen): ninguna viva, nada más de la caché, en tandas cortas
 * ({@see ExpiredCachePruner}, la misma pieza que `voice:prune-rate-limits`).
 *
 * Los vales van al almacén POR DEFECTO y el purgador mira el del LIMITADOR: si
 * alguien los separa, la orden lo dice y falla (código 1) en vez de purgar el
 * almacén equivocado en silencio. Con otro controlador (redis, array…) no hay
 * nada que purgar: lo dice y sale con 0. `--dry-run` solo cuenta. No lo
 * programa esta orden: lo agenda el lead en `routes/console.php`.
 */
class PruneStreamTicketsCommand extends Command
{
    protected $signature = 'admin:prune-stream-tickets
        {--dry-run : solo cuenta las claves caducadas, sin borrar}
        {--json : el resumen en JSON}';

    protected $description = 'Purga de la caché database las claves admin-stream-ticket:* CADUCADAS (vales de streams y fotos y su limitador; SC-7). Ninguna viva, nada más.';

    public function handle(): int
    {
        $porDefecto = (string) config('cache.default');
        $limitador = (string) (config('cache.limiter') ?: $porDefecto);
        if ($limitador !== $porDefecto) {
            $this->error("Los vales van al almacén «{$porDefecto}» y el purgador mira el del limitador, «{$limitador}»: no se purga nada.");

            return self::FAILURE;
        }

        $resumen = ExpiredCachePruner::prune(StreamTicketService::CACHE_PREFIX, (bool) $this->option('dry-run'));
        Log::info('auth:admin:stream_tickets_pruned', [
            'store' => $resumen['store'], 'supported' => $resumen['supported'], 'dry_run' => $resumen['dryRun'],
            'expired' => $resumen['expired'], 'deleted' => $resumen['deleted'],
        ]);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->info('── Vales de streams y fotos · purga (admin-stream-ticket:*) ──');
        if (! $resumen['supported']) {
            $this->line("Almacén     : {$resumen['store']} ({$resumen['driver']}): no es la caché database; caducan solas, nada que purgar.");

            return self::SUCCESS;
        }
        $this->line("Almacén     : {$resumen['store']} (database)");
        $this->line('Caducadas   : '.$resumen['expired'].($resumen['dryRun'] ? ' · en seco: no se borra nada' : ' · borradas: '.$resumen['deleted'].' en '.$resumen['batches'].' tanda(s)'));

        return self::SUCCESS;
    }
}
