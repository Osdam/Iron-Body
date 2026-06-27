<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingAiDoctorService;
use Illuminate\Console\Command;

/**
 * Diagnóstico del cerebro comercial IA (driver, OpenAI readiness, responder
 * efectivo). Muestra SOLO presencia (SET/MISSING) — NUNCA la OPENAI_API_KEY ni
 * valores. Por defecto el responder efectivo es fake (no llama a OpenAI).
 */
class MarketingAiDoctor extends Command
{
    protected $signature = 'marketing:ai-doctor';
    protected $description = 'Diagnostica el cerebro comercial IA (sin imprimir secretos).';

    public function handle(MarketingAiDoctorService $doctor): int
    {
        $r = $doctor->report();

        $this->info('── IRON BODY · Cerebro Comercial IA · Doctor ──');
        $this->line('cerebro habilitado  : '.($r['brain_enabled'] ? 'true' : 'false'));
        $this->line('driver configurado  : '.$r['driver']);
        $this->line('openai_enabled      : '.($r['openai_enabled'] ? 'true' : 'false'));
        $this->newLine();

        $this->table(['Variable', 'Estado'], [
            ['OPENAI_API_KEY', $r['present']['openai_api_key'] ? 'SET' : 'MISSING'],
            ['model',          $r['present']['openai_model'] ? 'SET' : 'MISSING'],
        ]);

        $this->newLine();
        $this->line('openai_ready        : '.($r['openai_ready'] ? 'yes' : 'no'));
        $this->line('responder efectivo  : '.$r['effective_responder']);
        $this->line('fail_closed         : '.($r['fail_closed'] ? 'true' : 'false'));

        if (! empty($r['suggestions'])) {
            $this->newLine();
            $this->info('Sugerencias:');
            foreach ($r['suggestions'] as $s) {
                $this->line('  → '.$s);
            }
        }

        $this->newLine();
        $this->line($r['effective_responder'] === 'openai'
            ? '<info>Responder efectivo: OpenAI (Laravel valida y ejecuta).</info>'
            : '<comment>Responder efectivo: fake (determinista). No se llama a OpenAI.</comment>');

        return self::SUCCESS;
    }
}
