<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Run\VoiceCallOutcomes;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:voice-outcomes` — el normalizador de la voz por estrategia
 * (contrato H9, D9-10; paquete V9): lo que la telefonía del H8 ya registró de
 * cada llamada que pidió una estrategia (estado, guarda posterior,
 * `no_volver_a_llamar`, `solicitar_humano`) → resultados del conjunto cerrado,
 * una vez por llamada. Nunca llama, nunca reintenta, nunca interpreta lo que
 * se dijo. Apagada la maestra, no hace nada y lo dice. No está programada:
 * el bloque del programador es de OPS (ver el informe de V9).
 */
class OutreachVoiceOutcomesCommand extends Command
{
    protected $signature = 'outreach:voice-outcomes {--json : el resumen en JSON}';

    protected $description = 'Resultados de la voz por estrategia (D9-10): lo que registró la telefonía, normalizado. Nunca llama ni reintenta.';

    public function handle(VoiceCallOutcomes $normalizer): int
    {
        $r = $normalizer->run(now());
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if ($r['skipped'] ?? false) {
            $this->line('Automatización comercial apagada ('.$r['reason'].'): no se normalizó nada.');

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · resultados de la voz ──');
        $this->line('Trabajos     : '.$r['jobs'].' de voz que salieron o quedaron ambiguos (últimos '.VoiceCallOutcomes::LOOKBACK_DAYS.' días)');
        $this->line('Enlazados    : '.$r['linked'].' ambiguo(s) con su llamada');
        $this->line('Registrados  : '.$r['recorded'].' resultado(s) ('.$r['duplicates'].' ya estaban, '.$r['ignored'].' ignorados)');
        $this->line('Pendientes   : '.$r['pending'].' llamada(s) todavía vivas');
        $this->line('Coste real   : '.$r['costMicros'].' micros de dólar (llamadas cerradas de la pasada)');

        return self::SUCCESS;
    }
}
