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
 * los agentes vigentes (abre el freno) y el techo duro de gasto. Solo GET a
 * los proveedores; sin credenciales, sin red. OPS8 la programa bajo
 * `voice.monitor.enabled`.
 */
class VoiceReconcileCommand extends Command
{
    protected $signature = 'voice:reconcile
        {--hours=48 : horas hacia atrás de la lista de Twilio (1–168)}
        {--json : el resumen en JSON}';

    protected $description = 'Conciliación de la voz (D8-21): Twilio frente a voice_calls, ambiguas, deriva de los agentes y techo de gasto. Solo GET; abre el freno si hace falta.';

    public function handle(VoiceReconciler $conciliacion): int
    {
        $horas = (int) $this->option('hours');
        $horas = $horas >= 1 && $horas <= 168 ? $horas : 48;
        $resumen = $conciliacion->run(VoiceAdapters::carrier(), VoiceAdapters::deployer(), now()->subHours($horas));

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        } else {
            $this->info('── Voz · conciliación ──');
            $this->line('Twilio      : '.($resumen['carrier'] ?? '—').' · '.$resumen['pages'].' página(s)'.($resumen['truncated'] ? ' · TRUNCADA' : ''));
            $this->line('Conocidas   : '.$resumen['known'].' · tramos: '.$resumen['legs'].' · DESCONOCIDAS: '.$resumen['unknown']);
            $this->line('Sin job     : '.$resumen['staleRequested'].' pasadas a ambiguous · atascadas en dialing: '.$resumen['stuckDialing']);
            $this->line('Deriva      : '.($resumen['drift'] === [] ? '—' : implode(', ', array_map(static fn ($p, $r) => "{$p} {$r}", array_keys($resumen['drift']), $resumen['drift']))));
            $this->line('Techo gasto : '.($resumen['costCap'] ? 'SUPERADO (freno)' : 'dentro'));
        }

        return $resumen['unknown'] === 0 && ! $resumen['costCap'] && ! in_array('drift', $resumen['drift'], true) ? self::SUCCESS : self::FAILURE;
    }
}
