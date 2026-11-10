<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\OutreachMonitor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:monitor` — la vigilancia de la automatización comercial
 * (contrato H9, D9-14): SOLO APAGA. Verifica la cadena de estrategias, la
 * deriva (trabajos vivos de una versión que ya no está activa), las tasas de
 * opt-out y de fallos de las últimas 24 h y el techo de gasto del día, y
 * abre el freno que toque con su incidente de IRON GUARD (fuente `outreach`).
 * Nunca suelta nada. OPS9 la programa cada 10 min bajo
 * `outreach.monitor.enabled`; apagada (o con la maestra apagada o el modo
 * `off`) no escribe nada y sale con 0 diciéndolo.
 */
class OutreachMonitorCommand extends Command
{
    protected $signature = 'outreach:monitor {--json : el resumen en JSON}';

    protected $description = 'Vigilancia de la automatización comercial (D9-14): cadena, deriva, opt-out, fallos y gasto. Solo abre frenos; nunca los suelta.';

    public function handle(OutreachMonitor $monitor): int
    {
        $r = $monitor->run();
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($r['skipped']) {
            $this->line('Monitor de la automatización comercial: '.($r['enabled'] ? 'la ejecución está apagada (maestra o modo off)' : 'apagado (COMMERCIAL_AUTOMATION_MONITOR_ENABLED=false)').'. No se escribió nada.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · monitor (pasada '.$r['runId'].') ──');
        $this->line('Cadena      : '.($r['checks']['chain']['head'] ?? '—').' · entradas ilegibles: '.($r['checks']['chain']['flagged'] ?? 0));
        $this->line('Deriva      : '.($r['checks']['drift']['flagged'] ?? 0).' · opt-out: '.($r['checks']['optOut']['flagged'] ?? 0).' · fallos: '.($r['checks']['failures']['flagged'] ?? 0).' · gasto: '.($r['checks']['budget']['flagged'] ?? 0));
        $this->line('Frenos      : '.($r['opened'] === [] ? 'ninguno nuevo' : implode(', ', array_map(static fn (array $f): string => $f['scope'].($f['scopeRef'] !== null ? '('.$f['scopeRef'].')' : '').' por '.$f['reason'], $r['opened']))));

        return $r['opened'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
