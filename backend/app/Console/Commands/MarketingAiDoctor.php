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

        return $this->revisarLosDosCanarios();
    }

    /**
     * Los dos canarios tienen que apuntar a la MISMA conversación.
     *
     * Son dos variables distintas —una decide a quién atiende ULTRON, la otra a
     * quién se le puede cobrar— y nada en el código las contrasta. Ya
     * divergieron una vez: el permiso de cobro se movió a una conversación y el
     * del cerebro se quedó en la anterior, de modo que la única que podía
     * cobrar era la única a la que ULTRON no contestaba. El canario quedó
     * inerte y no se supo hasta que alguien fue a mirar.
     *
     * Lo que lo hace peligroso es que la divergencia es SILENCIOSA por diseño:
     * el turno del pago muere con `automatic_links_disabled`, que es el motivo
     * legítimo que ve todo el tráfico fuera del canario. Ni el log ni el acta
     * lo distinguen de «aquí no toca cobrar». En una grabación se descubre
     * cuando ya no hay vuelta atrás en la toma.
     *
     * Por eso se comprueba aquí, que es lo que el smoke de producción ya
     * ejecuta en cada pasada, y por eso devuelve FAILURE: un canario inerte no
     * es un aviso, es una prueba que no va a funcionar.
     */
    private function revisarLosDosCanarios(): int
    {
        $cerebro = config('marketing.ultron.canary_conversation_id');
        $cobro = (int) config('marketing.ultron.payment_canary_conversation_id', 0);

        $this->newLine();
        $this->info('── Canarios ──');
        $this->line('ULTRON (atiende)    : '.($cerebro === null ? 'SIN ACOTAR (todo el tráfico)' : (int) $cerebro));
        $this->line('Cobro (puede acuñar): '.($cobro === 0 ? 'APAGADO' : $cobro));

        // Ninguno acotado, o sólo el del cobro con el cerebro abierto: son
        // estados válidos y no hay nada que contrastar.
        if ($cerebro === null || $cobro === 0) {
            return self::SUCCESS;
        }

        if ((int) $cerebro !== $cobro) {
            $this->newLine();
            $this->error(sprintf(
                'CANARIOS DIVERGENTES: ULTRON atiende la %d y el cobro está permitido en la %d. '
                .'La conversación que puede cobrar no recibe respuesta, y la que responde no puede cobrar.',
                (int) $cerebro,
                $cobro,
            ));

            return self::FAILURE;
        }

        $this->line('<info>Los dos apuntan a la misma conversación.</info>');

        return self::SUCCESS;
    }
}
