<?php

namespace App\Console\Commands\Voice;

use App\Services\Telephony\VoiceWebhookHealth;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `voice:webhook-health` — la SALUD DEL WEBHOOK (auditoría B-T5; contrato H8,
 * D8-21, decisión (f)). Solo con `VOICE_MONITOR_ENABLED`: dos GET de solo
 * lectura a ElevenLabs (por la interfaz de D8-3; sin las piezas, sin red)
 * comparan el webhook post-llamada y los dos secretos registrados con las
 * referencias de Laravel (`VOICE_WEBHOOK_BASE_URL`, `ELEVENLABS_*_REF`); si no
 * coinciden, abre o suma al incidente de IRON GUARD `voice_webhook_unhealthy` y,
 * si el problema es DEFINITIVO (SC-3 del cierre, R8-12), echa el freno de voz
 * (`webhook_unhealthy`; lo suelta una persona, con su nota). Apagada, no sale a
 * la red y dice `disabled`. El bloque de voz del programador la lanza cada hora
 * (minuto 5).
 *
 * Sale 0 si coincide (o con la bandera apagada) y 1 si no. `--dry-run` lee y
 * compara, pero no guarda el veredicto, no abre incidentes y no frena (dice si
 * lo haría). Nunca imprime una URL, un id del proveedor ni un secreto:
 * estados, códigos y fechas.
 */
class VoiceWebhookHealthCommand extends Command
{
    protected $signature = 'voice:webhook-health
        {--dry-run : lee y compara, sin guardar el veredicto, abrir incidentes ni echar el freno}
        {--json : el veredicto en JSON}';

    protected $description = 'Salud del webhook post-llamada (D8-21 f): con VOICE_MONITOR_ENABLED, dos GET de solo lectura comparan el webhook y los dos secretos de ElevenLabs con las referencias de Laravel; si no coinciden, incidente de IRON GUARD y, si es definitivo, el freno de voz (SC-3). Apagada, sin red.';

    public function handle(VoiceWebhookHealth $salud): int
    {
        $r = $salud->run(VoiceAdapters::webhookObserver(), (bool) $this->option('dry-run'));
        $codigo = $r['state'] === VoiceWebhookHealth::STATE_UNHEALTHY ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $codigo;
        }

        $this->info('── Voz · salud del webhook'.($r['dryRun'] ? ' · EN SECO (no guarda ni abre nada)' : '').' ──');
        if ($r['state'] === VoiceWebhookHealth::STATE_DISABLED) {
            $this->line('Apagada (VOICE_MONITOR_ENABLED): no se ha hecho ninguna petición.');

            return $codigo;
        }
        $w = $r['webhook'];
        $s = $r['secrets'];
        $si = static fn (mixed $v): string => $v === true ? 'sí' : ($v === false ? 'no' : '—');
        $this->line('Webhook    : lectura '.$w['outcome'].' · existe '.$si($w['found']).' · URL de Laravel '.$si($w['urlMatches'])
            .' · desactivado '.$si($w['disabled']).' · desactivado solo '.$si($w['autoDisabled']).' · autenticación '.($w['authType'] ?? '—'));
        $this->line('Secretos   : lectura '.$s['outcome'].' · herramientas '.$si($s['toolFound']).' · iniciación '.$si($s['initiationFound'])
            .($s['truncated'] ? ' · lista con más páginas' : ''));
        $this->line('Última entrega buena: '.($r['lastGoodDeliveryAt'] ?? 'ninguna registrada'));
        if ($r['state'] === VoiceWebhookHealth::STATE_OK) {
            $this->info('Coincide: el webhook y los dos secretos son los de Laravel.');
        } else {
            $this->error('No coincide: '.implode(', ', $r['problems']).($r['incident'] === null ? '' : ' (incidente '.$r['incident'].')'));
            $this->brakeLine($r['brake']);
        }

        return $codigo;
    }

    /**
     * SC-3: qué pasó con el freno. Sin un problema DEFINITIVO no se frena (lo
     * incierto y lo que falta en Laravel solo avisan).
     *
     * @param  array<string, mixed>|null  $freno
     */
    private function brakeLine(?array $freno): void
    {
        if ($freno === null) {
            $this->line('Freno de voz: no se echa (solo frena un problema definitivo: '.implode(', ', VoiceWebhookHealth::BRAKING).').');

            return;
        }
        $por = $freno['reason'].' · '.implode(', ', $freno['problems']);
        $ahora = $freno['engaged'] ? 'echado' : 'suelto';
        $this->error('Freno de voz: '.match (true) {
            $freno['opened'] === null => 'EN SECO, '.($freno['wouldOpen'] ? 'lo echaría' : 'no abriría otra apertura').' ('.$por.'); ahora está '.$ahora,
            $freno['opened'] === true => 'ECHADO ('.$por.'; apertura '.$freno['uuid'].')',
            default => 'sin apertura nueva ('.$por.': la de esta avería sigue echada o se soltó durante la comprobación); ahora está '.$ahora,
        }.'. Lo suelta una persona con «Publicar la voz» y una nota, después de revisar las llamadas hechas sin la guarda.');
    }
}
