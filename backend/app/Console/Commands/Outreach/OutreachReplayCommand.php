<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachReplay;
use App\Services\Outreach\Run\OutreachRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:replay <decision_uuid>` — la reproducibilidad (contrato H9,
 * D9-12; T12; §13.1 #24): vuelve a evaluar una decisión guardada con su foto
 * de hechos, su instante y las entradas de su traza, y compara la
 * `trace_hash`. Solo lectura (deja su pasada `replay` si la ejecución está
 * encendida). Sale con 0 si da lo mismo, 1 si difiere y 2 si no se puede
 * reproducir o no existe.
 */
class OutreachReplayCommand extends Command
{
    protected $signature = 'outreach:replay {decision : el uuid de la decisión} {--json : el resumen en JSON}';

    protected $description = 'Vuelve a evaluar una decisión guardada de la automatización comercial y compara su huella (D9-12).';

    public function handle(OutreachReplay $replay, OutreachRuns $runs): int
    {
        $uuid = strtolower(trim((string) $this->argument('decision')));
        if (! Str::isUuid($uuid)) {
            $this->error('La decisión se nombra por su uuid.');

            return 2;
        }
        $ahora = now();
        $run = OutreachConfig::active() ? $runs->open('replay', OutreachConfig::mode(), $ahora) : null;
        try {
            $r = $replay->replay($uuid);
        } catch (OutreachException $e) {
            if ($run !== null) {
                $runs->fail($run, $ahora, [], $e->errorCode);
            }
            $this->error($e->getMessage().' ('.$e->errorCode.')');

            return 2;
        }
        if ($run !== null) {
            $runs->complete($run, $ahora, ['result' => $r['result']]);
        }
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        } else {
            $this->info('── Automatización comercial · replay de '.$r['decision'].' ('.$r['kind'].') ──');
            $this->line('Guardada    : '.$r['storedHash']);
            $this->line('Reevaluada  : '.($r['replayedHash'] ?? '—'));
            $this->line('Resultado   : '.match ($r['result']) {
                OutreachReplay::SAME => 'LA MISMA huella: misma versión, misma foto, misma decisión.',
                OutreachReplay::DIFFERENT => 'DISTINTA: el evaluador cambió o la fila no es lo que dice ('.implode(', ', array_map(static fn ($c, $ok) => $c.($ok ? ' igual' : ' distinta'), array_keys($r['ladder']), $r['ladder'])).').',
                default => 'no reproducible: '.$r['reason'],
            });
        }

        return match ($r['result']) {
            OutreachReplay::SAME => self::SUCCESS,
            OutreachReplay::DIFFERENT => self::FAILURE,
            default => 2,
        };
    }
}
