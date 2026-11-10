<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:sweep` — la PASADA DE PLANIFICACIÓN (contrato H9, D9-8 etapas
 * 1–5): la ingesta de `commercial_events` por cursor, el barrido de los
 * disparadores periódicos (cada uno con su cadencia del catálogo) y la
 * planificación de las inscripciones que ya tocan (prioridad, escalera,
 * reserva y trabajo). OPS9 la programa cada 5 min bajo
 * `outreach.scheduler.enabled` (una de las dos tareas del bloque del H9).
 *
 * Con la maestra apagada o el modo `off` no hace nada y lo dice (sale con 0);
 * con la PARADA GLOBAL echada deja su pasada `aborted` y no inscribe ni
 * planifica. `--only=ingest|sweep|plan` corre una sola etapa; `--force`
 * ignora la cadencia (pruebas y diagnóstico).
 */
class OutreachSweepCommand extends Command
{
    protected $signature = 'outreach:sweep
        {--only= : una sola etapa (ingest | sweep | plan)}
        {--force : barre aunque la cadencia del disparador no haya vencido}
        {--json : el resumen en JSON}';

    protected $description = 'Pasada de planificación de la automatización comercial (D9-8): ingesta por cursor, barrido periódico y planificación de lo que toca.';

    public function handle(OutreachPlanner $planner): int
    {
        $solo = $this->option('only');
        if ($solo !== null && ! in_array($solo, ['ingest', 'sweep', 'plan'], true)) {
            $this->error('--only admite ingest, sweep o plan.');

            return 2;
        }
        $ahora = now();
        $r = [];
        if ($solo === null || $solo === 'ingest') {
            $r['ingest'] = $planner->ingest($ahora);
        }
        if ($solo === null || $solo === 'sweep') {
            $r['sweep'] = $planner->sweep($ahora, (bool) $this->option('force'));
        }
        if ($solo === null || $solo === 'plan') {
            $r['plan'] = $planner->planDue($ahora);
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $primero = $r[array_key_first($r)];
        if (($primero['skipped'] ?? false) && in_array($primero['reason'] ?? '', ['master_flag_off', 'mode_off'], true)) {
            $this->line('Automatización comercial apagada ('.$primero['reason'].'): no se hizo trabajo.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · pasada ──');
        if (isset($r['ingest'])) {
            $i = $r['ingest'];
            $this->line('Ingesta     : '.(($i['skipped'] ?? false) ? 'omitida ('.$i['reason'].')' : $i['events'].' evento(s) · cursor → '.$i['cursor_to'].' · resultados: '.$i['outcomes']));
        }
        if (isset($r['sweep'])) {
            $s = $r['sweep'];
            $this->line('Barrido     : '.(($s['skipped'] ?? false) ? 'omitido ('.$s['reason'].')' : count($s['strategies']).' estrategia(s)'));
            foreach ($s['strategies'] ?? [] as $uuid => $c) {
                $this->line('  '.substr($uuid, 0, 8).' · '.(isset($c['skipped']) ? 'omitida: '.$c['skipped'] : 'candidatos '.$c['candidates'].' · evaluados '.$c['evaluated'].' · inscritos '.$c['enrolled'].' · fuera '.($c['not_in_audience'] + $c['insufficient_facts'] + $c['excluded'] + $c['success_reached'])));
            }
        }
        if (isset($r['plan'])) {
            $p = $r['plan'];
            $this->line('Planificado : '.(($p['skipped'] ?? false) ? 'omitido ('.$p['reason'].')' : 'tocaban '.$p['due'].' · trabajos '.$p['planned'].' · aplazadas '.$p['deferred'].' · esperan '.$p['waiting'].' · negadas '.$p['denied'].' · convertidas '.$p['converted'].' · derivadas '.$p['handed_off'].' · salidas '.$p['exited'].' · canceladas '.$p['canceled']));
        }

        return self::SUCCESS;
    }
}
