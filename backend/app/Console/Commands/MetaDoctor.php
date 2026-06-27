<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaDoctorService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de la integración Meta / WhatsApp Cloud API. Muestra SOLO
 * presencia (SET/MISSING) y decisiones derivadas — NUNCA valores de tokens o
 * secretos. Pensado para verificar readiness antes/después de completar .env.
 */
class MetaDoctor extends Command
{
    protected $signature = 'meta:doctor';
    protected $description = 'Diagnostica la configuración Meta/WhatsApp Cloud API (sin imprimir secretos).';

    public function handle(MetaDoctorService $doctor): int
    {
        $r = $doctor->report();

        $this->info('── IRON BODY · Meta / WhatsApp Cloud API · Doctor ──');
        $this->line('META_ENABLED        : '.($r['enabled'] ? 'true' : 'false'));
        $this->line('graph_version       : '.$r['graph_version']);
        $this->newLine();

        $this->table(['Variable', 'Estado'], collect($r['present'])->map(
            fn (bool $set, string $key) => [$key, $set ? 'SET' : 'MISSING'],
        )->values()->all());

        $this->newLine();
        $this->line('auth_configured     : '.($r['auth_configured'] ? 'yes' : 'no'));
        $this->line('envío real permitido: '.($r['live_send_allowed'] ? 'yes (real)' : 'no (queda dry_run)'));
        $this->line('send_mode           : '.$r['send_mode']);
        $this->line('webhook a registrar : '.$r['webhook_url']);

        $w = $r['webhook'];
        $this->newLine();
        $this->line('── Webhook entrante (Fase 4-A) ──');
        $this->line('GET route            : '.($w['get_route_exists'] ? 'yes' : 'no'));
        $this->line('POST route           : '.($w['post_route_exists'] ? 'yes' : 'no'));
        $this->line('verify_token         : '.($w['verify_token'] ? 'SET' : 'MISSING'));
        $this->line('webhook_secret       : '.($w['webhook_secret'] ? 'SET' : 'MISSING'));
        $this->line('inbound_auto_analyze : '.($w['inbound_auto_analyze'] ? 'true' : 'false'));
        $this->line('inbound_auto_execute : '.($w['inbound_auto_execute'] ? 'true' : 'false'));
        $this->line('effective_mode       : '.$w['effective_mode']);

        if (! empty($r['missing'])) {
            $this->newLine();
            $this->warn('Faltantes para envío real:');
            foreach ($r['missing'] as $m) {
                $this->line('  • '.$m);
            }
        }

        if (! empty($r['suggestions'])) {
            $this->newLine();
            $this->info('Sugerencias:');
            foreach ($r['suggestions'] as $s) {
                $this->line('  → '.$s);
            }
        }

        $this->newLine();
        $this->line($r['live_send_allowed']
            ? '<info>Listo para envío real controlado.</info>'
            : '<comment>Modo seguro: los envíos quedan en dry_run (no se entrega nada a Meta).</comment>');

        return self::SUCCESS;
    }
}
