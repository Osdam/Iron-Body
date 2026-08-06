<?php

namespace App\Console\Commands;

use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\WhatsappOutboxService;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;

/**
 * Reintenta los mensajes salientes cuyo fallo era pasajero.
 *
 * Sin esto, un 429 de Meta —que ocurre por diseño cuando se envía rápido—
 * dejaba al prospecto sin respuesta para siempre. Solo entran aquí los que
 * `WhatsappOutboxService` marcó como reintentables y cuya espera ya venció;
 * los definitivos (número sin WhatsApp, ventana de 24 h cerrada) quedan 'dead'
 * porque insistir no los arregla.
 *
 * Es seguro ejecutarlo de más: un mensaje con `meta_message_id` ya no se toca.
 */
class RetryWhatsappOutbox extends Command
{
    protected $signature = 'marketing:retry-outbox
        {--limit= : Máximo de mensajes por corrida}
        {--dry-run : Lista lo que se reintentaría sin enviar nada}';

    protected $description = 'Reintenta los mensajes de WhatsApp que fallaron por causas transitorias.';

    public function handle(WhatsappOutboxService $outbox, MarketingMessageDispatcher $dispatcher): int
    {
        $limit = (int) ($this->option('limit') ?? config('marketing.outbox.retry_batch', 50));
        $due = $outbox->due(max(1, $limit));

        if ($due->isEmpty()) {
            $this->info('No hay mensajes pendientes de reintento.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['id', 'intentos', 'código', 'vencía'],
                $due->map(fn (MarketingMessage $m) => [
                    $m->id, $m->send_attempts, $m->last_error_code ?: '—',
                    $m->next_attempt_at?->diffForHumans(),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $sent = 0;
        $stillFailing = 0;

        foreach ($due as $message) {
            // El hilo original: en el log, el reintento queda cosido al mensaje
            // entrante que provocó esta respuesta hace media hora.
            if (! empty($message->correlation_id)) {
                Context::add('correlation_id', (string) $message->correlation_id);
            }

            $to = $dispatcher->normalizePhone(data_get($message->metadata, 'recipient'));

            if ($to === null) {
                // Sin destinatario utilizable no hay reintento posible. Se cierra
                // en vez de dejarlo dando vueltas por la cola para siempre.
                $message->forceFill([
                    'status' => WhatsappOutboxService::STATUS_DEAD,
                    'last_error_message' => 'recipient_unavailable',
                    'next_attempt_at' => null,
                ])->save();
                $stillFailing++;

                continue;
            }

            $result = $outbox->deliver($message, $to);
            $result['sent'] ? $sent++ : $stillFailing++;
        }

        ChannelLog::info('outbox.retry_run', [
            'considered' => $due->count(),
            'sent' => $sent,
            'still_failing' => $stillFailing,
        ]);

        $this->info(sprintf(
            'Reintentados %d · entregados %d · siguen fallando %d',
            $due->count(), $sent, $stillFailing,
        ));

        return self::SUCCESS;
    }
}
