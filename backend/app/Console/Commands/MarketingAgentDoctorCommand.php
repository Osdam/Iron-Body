<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingAgentDoctorService;
use Illuminate\Console\Command;

/**
 * Diagnóstico INTEGRAL del agente comercial de WhatsApp. Valida cerebro OpenAI,
 * Meta/WhatsApp, base de conocimiento, plan mensual, preparación de Wompi
 * (producción o pendiente) y el estado de auto-ejecución. NO imprime secretos.
 */
class MarketingAgentDoctorCommand extends Command
{
    protected $signature = 'marketing:agent-doctor';
    protected $description = 'Diagnostica el agente comercial de WhatsApp (OpenAI, Meta, conocimiento, plan, Wompi, auto-execute).';

    public function handle(MarketingAgentDoctorService $doctor): int
    {
        $r = $doctor->report();

        $this->info('── IRON BODY · Agente Comercial WhatsApp · Doctor ──');
        $this->newLine();

        $labels = [
            'openai'        => 'Cerebro OpenAI',
            'meta'          => 'Meta / WhatsApp',
            'knowledge'     => 'Base de conocimiento',
            'monthly_plan'  => 'Plan mensual',
            'wompi_payment' => 'Wompi (pago)',
            'auto_execute'  => 'Auto-ejecución',
        ];

        $rows = [];
        foreach ($r['checks'] as $key => $check) {
            $rows[] = [
                $labels[$key] ?? $key,
                $check['ok'] ? '✓' : '✗',
                $check['status'],
                $check['detail'],
            ];
        }
        $this->table(['Chequeo', 'OK', 'Estado', 'Detalle'], $rows);

        $this->newLine();
        $this->info('Sugerencias:');
        foreach ($r['checks'] as $check) {
            $this->line('  → '.$check['hint']);
        }

        $this->newLine();
        $this->info('Garantías de seguridad:');
        foreach ($r['safety'] as $line) {
            $this->line('  ✓ '.$line);
        }

        $this->newLine();
        $this->line($r['ready']
            ? '<info>'.$r['summary'].'</info>'
            : '<comment>'.$r['summary'].'</comment>');

        return self::SUCCESS;
    }
}
