<?php

namespace App\Console\Commands\Voice;

use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `voice:reconcile` — la CONCILIACIÓN y la vigilancia de la voz (contrato H8,
 * D8-21; E1-2, E2-3): las llamadas de la subcuenta en Twilio frente a
 * `voice_calls` (una sin su fila abre el freno; resuelve las ambiguas y
 * registra el precio real), las salientes que perdieron su job, la deriva de
 * los agentes vigentes (abre el freno) y el techo duro de gasto. Con
 * `--numbers` (Enmienda 4, E4-8), ADEMÁS la deriva del número importado (a qué
 * agente apunta en ElevenLabs frente al publicado; abre el freno). Solo GET a
 * los proveedores; sin credenciales, sin red. OPS8 la programa bajo
 * `voice.monitor.enabled`.
 *
 * Con `--dry-run` (E10-9; el diagnóstico del runbook ANTES de soltar el freno)
 * lee y decide lo mismo, pero no escribe nada (ni filas, ni eventos, ni freno,
 * ni incidentes, ni la caché del job): el resumen dice además lo que haría
 * (`dryRun`, `wouldOpenBrake` y `would`: aperturas, incidentes, eventos por
 * tipo, transiciones y precios). El código de salida, el de siempre.
 */
class VoiceReconcileCommand extends Command
{
    protected $signature = 'voice:reconcile
        {--hours=48 : horas hacia atrás de la lista de Twilio (1–168)}
        {--numbers : además, la deriva del número importado (E4-8)}
        {--dry-run : en seco: informa lo que haría sin escribir nada (E10-9)}
        {--json : el resumen en JSON}';

    protected $description = 'Conciliación de la voz (D8-21): Twilio frente a voice_calls, ambiguas, deriva de los agentes y techo de gasto. Solo GET; abre el freno si hace falta. Con --dry-run no escribe nada.';

    public function handle(VoiceReconciler $conciliacion): int
    {
        $horas = (int) $this->option('hours');
        $horas = $horas >= 1 && $horas <= 168 ? $horas : 48;
        $seco = (bool) $this->option('dry-run');
        $resumen = $conciliacion->run(VoiceAdapters::carrier(), VoiceAdapters::deployer(), now()->subHours($horas), $seco);
        if ($this->option('numbers')) {
            $resumen['number'] = $conciliacion->numbers(VoiceAdapters::numberObserver(), $seco);
        }
        if ($seco) {
            $resumen += $conciliacion->dryRunReport();
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        } else {
            $this->info('── Voz · conciliación'.($seco ? ' · EN SECO (no escribe nada)' : '').' ──');
            $this->line('Twilio      : '.($resumen['carrier'] ?? '—').' · '.$resumen['pages'].' página(s)'.($resumen['truncated'] ? ' · TRUNCADA' : ''));
            $this->line('Conocidas   : '.$resumen['known'].' · tramos: '.$resumen['legs'].' · DESCONOCIDAS: '.$resumen['unknown']);
            $this->line('Sin job     : '.$resumen['staleRequested'].' pasadas a ambiguous · atascadas en dialing: '.$resumen['stuckDialing']);
            $this->line('Deriva      : '.($resumen['drift'] === [] ? '—' : implode(', ', array_map(static fn ($p, $r) => "{$p} {$r}", array_keys($resumen['drift']), $resumen['drift']))));
            $this->line('Techo gasto : '.($resumen['costCap'] ? 'SUPERADO (freno)' : 'dentro'));
            if (isset($resumen['number'])) {
                $n = $resumen['number'];
                $this->line('Número      : '.($n['result'] === VoiceReconciler::NUMBER_DRIFT ? 'DERIVA (freno)' : $n['result'])
                    .' · apunta: '.$n['observed'].' · debería: '.$n['expected']);
            }
            if ($seco) {
                $w = $resumen['would'];
                $this->line('Haría       : '.($resumen['wouldOpenBrake']
                    ? 'ECHAR EL FRENO '.$w['incidents'].' vez/veces ('.implode(', ', array_column($w['brakes'], 'cause')).'), con su incidente'
                    : 'no echar el freno'));
                $this->line('              eventos: '.($w['events'] === [] ? '—' : implode(', ', array_map(static fn ($k, $n) => "{$k} ×{$n}", array_keys($w['events']), $w['events'])))
                    .' · transiciones: '.$w['transitions'].' · precios: '.$w['costs']);
            }
        }
        $derivaDelNumero = ($resumen['number']['result'] ?? null) === VoiceReconciler::NUMBER_DRIFT;

        return $resumen['unknown'] === 0 && ! $resumen['costCap'] && ! in_array('drift', $resumen['drift'], true) && ! $derivaDelNumero ? self::SUCCESS : self::FAILURE;
    }
}
