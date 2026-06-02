<?php

namespace App\Jobs;

use App\Models\AutomationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un AutomationEvent al webhook de n8n.
 *
 * Firma el payload con HMAC-SHA256 (clave = N8N_WEBHOOK_SECRET) para que n8n
 * pueda verificar autenticidad. Reintentos controlados; estados sent/failed.
 * NUNCA loguea token, firma completa ni secretos.
 */
class SendAutomationEventToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos controlados (Laravel reintenta el job; cada intento suma attempts). */
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $eventId)
    {
    }

    public function handle(): void
    {
        $event = AutomationEvent::find($this->eventId);
        if ($event === null || $event->status === AutomationEvent::STATUS_SENT) {
            return;
        }

        $url = (string) config('automation.webhook_url');
        $secret = (string) config('automation.webhook_secret');

        if (!config('automation.enabled') || $url === '' || $secret === '') {
            $event->update([
                'status' => AutomationEvent::STATUS_SKIPPED,
                'last_error' => 'n8n deshabilitado o sin configuración',
            ]);
            return;
        }

        $payload = $event->toWebhookPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $body, $secret);

        $event->increment('attempts');

        try {
            $response = Http::timeout((int) config('automation.timeout', 10))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $secret,
                    'X-IronBody-Event' => $event->event_type,
                    'X-Idempotency-Key' => $event->idempotency_key,
                    'X-IronBody-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                $event->update([
                    'status' => AutomationEvent::STATUS_SENT,
                    'processed_at' => now(),
                    'last_error' => null,
                ]);
                Log::info('automation.event.sent', [
                    'event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'status_code' => $response->status(),
                ]);
                return;
            }

            // Respuesta no-2xx: marcar failed (sin loguear el body crudo).
            $this->markFailed($event, 'http_' . $response->status());
        } catch (Throwable $e) {
            $this->markFailed($event, class_basename($e));
            throw $e; // permite el retry del job
        }
    }

    public function failed(Throwable $e): void
    {
        $event = AutomationEvent::find($this->eventId);
        if ($event !== null && $event->status !== AutomationEvent::STATUS_SENT) {
            $this->markFailed($event, 'job_failed:' . class_basename($e));
        }
    }

    private function markFailed(AutomationEvent $event, string $reason): void
    {
        $event->update([
            'status' => AutomationEvent::STATUS_FAILED,
            'last_error' => substr($reason, 0, 250),
        ]);
        Log::warning('automation.event.failed', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'attempts' => $event->attempts,
            'reason' => $reason,
        ]);
    }
}
