<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\OutreachReconciler;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:reconcile` — la conciliación sola (contrato H9, D9-8
 * «Durabilidad»; §13.1 #24): los trabajos reclamados o en despacho con más
 * de 10 min pasan a `ambiguous` (con incidente), los planificados vencidos a
 * `expired` (reserva liberada) y las inscripciones cuya ventana acabó a
 * `expired` con `no_response`. Nunca reenvía nada. `outreach:dispatch` la
 * corre antes de reclamar; esta orden sirve para el diagnóstico y el runbook.
 */
class OutreachReconcileCommand extends Command
{
    protected $signature = 'outreach:reconcile {--json : el resumen en JSON}';

    protected $description = 'Conciliación de la automatización comercial (D9-8): ambiguos, trabajos vencidos y ventanas acabadas. Nunca reenvía.';

    public function handle(OutreachReconciler $reconciler): int
    {
        $r = $reconciler->run(now());
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($r['skipped'] ?? false) {
            $this->line('Automatización comercial apagada ('.$r['reason'].'): no se concilió nada.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · conciliación (pasada '.$r['run'].') ──');
        $this->line('Ambiguos    : '.$r['ambiguous'].' (reclamados sin ejecutar; con incidente; nadie los reenvía)');
        $this->line('Vencidos    : '.$r['expiredJobs'].' trabajo(s) planificado(s) fuera de plazo, reserva liberada');
        $this->line('Ventanas    : '.$r['expiredEnrollments'].' inscripción(es) acabada(s) con no_response');

        return self::SUCCESS;
    }
}
