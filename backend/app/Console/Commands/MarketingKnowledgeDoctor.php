<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingKnowledgeBaseService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de la base de conocimiento comercial: cuántos ítems hay, activos,
 * por categoría, categorías recomendadas faltantes y planes activos. Indica si
 * el prompt builder está recibiendo conocimiento. No imprime secretos.
 */
class MarketingKnowledgeDoctor extends Command
{
    protected $signature = 'marketing:knowledge-doctor';
    protected $description = 'Diagnostica la base de conocimiento comercial (sin secretos).';

    public function handle(MarketingKnowledgeBaseService $kb): int
    {
        $r = $kb->summary();

        $this->info('── IRON BODY · Base de Conocimiento Comercial · Doctor ──');
        $this->line('total items         : '.$r['total_items']);
        $this->line('activos             : '.$r['active_items']);
        $this->line('active_plans_count  : '.$r['active_plans_count']);
        $this->line('prompt con knowledge: '.($r['prompt_receives_knowledge'] ? 'yes' : 'no'));
        $this->line('version             : '.$r['version']);
        $this->newLine();

        $rows = [];
        foreach ($r['by_category'] as $cat => $count) {
            $rows[] = [$cat, $count];
        }
        if ($rows !== []) {
            $this->table(['Categoría (activa)', 'Items'], $rows);
        }

        if (! empty($r['missing_recommended'])) {
            $this->newLine();
            $this->warn('Categorías recomendadas faltantes:');
            foreach ($r['missing_recommended'] as $cat) {
                $this->line('  • '.$cat);
            }
            $this->line('Sugerencia: php artisan marketing:knowledge-seed');
        }

        $this->newLine();
        $this->line($r['prompt_receives_knowledge']
            ? '<info>El prompt del cerebro está recibiendo conocimiento real.</info>'
            : '<comment>Sin conocimiento activo: el cerebro opera con restricciones conservadoras.</comment>');

        return self::SUCCESS;
    }
}
