<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachReconciler;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:dispatch` — la PASADA DE DESPACHO (contrato H9, D9-8 etapas 6 y
 * 7 y «Durabilidad»): primero la conciliación (los trabajos que se quedaron
 * a medias, los vencidos y las inscripciones cuya ventana acabó) y después la
 * reclamación de los trabajos que ya tocan (≤ 50) con su despacho por la
 * cola. OPS9 la programa cada minuto bajo `outreach.scheduler.enabled` (la
 * segunda tarea del bloque del H9).
 *
 * Con la maestra apagada o el modo `off` no hace nada y lo dice; con la
 * PARADA GLOBAL echada no reclama (los planificados esperan) y deja su pasada
 * `aborted`. `--inline` ejecuta en el mismo proceso (la consola y las pruebas);
 * `--no-reconcile` salta la conciliación.
 */
class OutreachDispatchCommand extends Command
{
    protected $signature = 'outreach:dispatch
        {--limit= : cuántos trabajos reclamar como mucho (≤ 50)}
        {--inline : ejecutar en este proceso en vez de por la cola}
        {--no-reconcile : sin la conciliación previa}
        {--json : el resumen en JSON}';

    protected $description = 'Pasada de despacho de la automatización comercial (D9-8): conciliación, reclamación de los trabajos vencidos y su ejecución por la cola.';

    public function handle(OutreachDispatcher $dispatcher, OutreachReconciler $reconciler): int
    {
        $limite = is_numeric($this->option('limit')) ? max(1, min(50, (int) $this->option('limit'))) : null;
        $r = [];
        if (! $this->option('no-reconcile')) {
            $r['reconcile'] = $reconciler->run(now());
        }
        $r['dispatch'] = $dispatcher->dispatch(now(), $limite, (bool) $this->option('inline'));

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $d = $r['dispatch'];
        if (($d['skipped'] ?? false) && in_array($d['reason'] ?? '', ['master_flag_off', 'mode_off'], true)) {
            $this->line('Automatización comercial apagada ('.$d['reason'].'): no se despachó nada.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · despacho ──');
        if (isset($r['reconcile'])) {
            $c = $r['reconcile'];
            $this->line('Conciliación: '.(($c['skipped'] ?? false) ? 'omitida ('.$c['reason'].')' : 'ambiguos '.$c['ambiguous'].' · vencidos '.$c['expiredJobs'].' · ventanas '.$c['expiredEnrollments']));
        }
        $this->line('Reclamados  : '.(($d['skipped'] ?? false) ? 'ninguno ('.$d['reason'].')' : $d['claimed'].' · '.($d['results'] === [] ? '—' : implode(', ', array_map(static fn ($k, $n) => "{$k} {$n}", array_keys($d['results']), $d['results'])))));

        return self::SUCCESS;
    }
}
