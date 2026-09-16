<?php

namespace App\Jobs;

use App\Models\MarketingAutomationEvent;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Entrega un evento comercial al webhook de ULTRON en n8n.
 *
 * Firma igual que el puente de bienestar que ya funciona —bearer + HMAC del
 * cuerpo + clave de idempotencia— porque n8n verifica lo mismo en los dos
 * sitios y tener dos esquemas de firma sería una forma elegante de equivocarse.
 *
 * NUNCA registra el secreto, la firma completa ni el cuerpo del mensaje.
 */
class SendUltronEventToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Espera creciente: si n8n está caído, no se insiste cada veinte segundos. */
    public array $backoff = [20, 60, 180];

    public function __construct(public int $eventId)
    {
        // Carril comercial: nadie está esperando esto con el teléfono en la mano,
        // pero tampoco puede competir con la ingesta de WhatsApp.
        $lane = (array) config('queue.lanes.commercial');
        $this->onQueue($lane['queue'] ?? 'commercial');
    }

    /** Un evento se entrega una sola vez a la vez. */
    public function uniqueId(): string
    {
        return 'ultron-event:'.$this->eventId;
    }

    public function handle(): void
    {
        $event = MarketingAutomationEvent::find($this->eventId);

        if ($event === null || $event->status === MarketingAutomationEvent::STATUS_SENT) {
            return;
        }

        if ($event->correlation_id !== null) {
            Context::add('correlation_id', $event->correlation_id);
        }

        $url = trim((string) config('marketing.ultron.webhook_url'));
        $secret = (string) config('automation.webhook_secret');

        if (! (bool) config('marketing.ultron.enabled', false) || $url === '' || $secret === '') {
            // Apagado o sin configurar no es un fallo: es que no toca.
            $event->update([
                'status' => MarketingAutomationEvent::STATUS_SKIPPED,
                'last_error' => 'ultron deshabilitado o sin configuración',
            ]);

            return;
        }

        $body = json_encode($event->payload_json ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
        $event->increment('attempts');

        try {
            $response = Http::timeout((int) config('marketing.ultron.timeout', 10))
                ->withHeaders([
                    'Authorization' => 'Bearer '.$secret,
                    'X-IronBody-Event' => $event->event_type,
                    'X-Idempotency-Key' => $event->idempotency_key,
                    'X-IronBody-Signature' => hash_hmac('sha256', $body, $secret),
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                $event->update([
                    'status' => MarketingAutomationEvent::STATUS_SENT,
                    'processed_at' => now(),
                    'last_error' => null,
                ]);

                ChannelLog::info('ultron.event.sent', [
                    'event_id' => $event->id,
                    'status_code' => $response->status(),
                    'attempts' => $event->attempts,
                ]);

                return;
            }

            // Sin el cuerpo de la respuesta: puede traer de vuelta el mensaje.
            $this->markFailed($event, 'http_'.$response->status());
        } catch (Throwable $e) {
            $this->markFailed($event, class_basename($e));
            throw $e; // deja que el job reintente con su backoff
        }
    }

    public function failed(Throwable $e): void
    {
        $event = MarketingAutomationEvent::find($this->eventId);

        if ($event !== null && $event->status !== MarketingAutomationEvent::STATUS_SENT) {
            $this->markFailed($event, 'job_failed:'.class_basename($e));
        }
    }

    private function markFailed(MarketingAutomationEvent $event, string $reason): void
    {
        $event->update([
            'status' => MarketingAutomationEvent::STATUS_FAILED,
            'last_error' => mb_substr($reason, 0, 250),
        ]);

        ChannelLog::warning('ultron.event.failed', [
            'event_id' => $event->id,
            'attempts' => $event->attempts,
            'reason' => $reason,
        ]);
    }
}
