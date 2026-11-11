<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:whatsapp-outcomes` — los normalizadores de WhatsApp (contrato H9,
 * D9-8 etapa 8, D9-10; paquete W9): lee lo que el webhook de Meta dejó como
 * siempre (estados de entrega y entrantes) para los trabajos de WhatsApp que
 * salieron y lo registra como resultados (`sent`, `delivered`, `read`,
 * `failed`, `replied`, `opted_out`). Solo lee Marketing; solo escribe
 * `outreach_*`. Con la maestra apagada o el modo `off` no hace nada y lo dice.
 * La programa OPS9 (propuesta: cada minuto, antes de `outreach:dispatch`).
 */
class OutreachWhatsappOutcomesCommand extends Command
{
    protected $signature = 'outreach:whatsapp-outcomes {--json : el resumen en JSON}';

    protected $description = 'Normalizadores de WhatsApp de la automatización comercial (W9): estados de Meta, respuestas y palabra de baja → resultados. Nunca envía.';

    public function handle(WhatsappTemplateOutcomes $ingest): int
    {
        $r = $ingest->run(now());
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($r['skipped'] ?? false) {
            $this->line('Automatización comercial apagada ('.$r['reason'].'): no se normalizó nada.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · normalizadores de WhatsApp ──');
        $this->line('Trabajos     : '.$r['jobs'].' de WhatsApp en vivo de los últimos '.WhatsappTemplateOutcomes::LOOKBACK_DAYS.' días');
        $registrados = $r['recorded'] === [] ? 'ninguno' : implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys($r['recorded']), $r['recorded']));
        $this->line('Resultados   : '.$registrados);
        $this->line('Repetidos    : '.$r['duplicates'].' (ya estaban: no avanzan nada)');
        $this->line('Conciliados  : '.$r['linked'].' trabajo(s) ambiguo(s) unidos a su mensaje por la correlación');
        $this->line('Sin mensaje  : '.$r['unmatched'].' (nada que leer; un ambiguo así lo resuelve una persona)');

        return self::SUCCESS;
    }
}
