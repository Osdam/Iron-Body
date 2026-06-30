<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhookEvent;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook público de Meta (Instagram / Facebook / WhatsApp).
 *
 *  GET  /api/webhooks/meta  → verificación (hub.challenge + verify_token).
 *  POST /api/webhooks/meta  → eventos. Valida firma X-Hub-Signature-256,
 *                             responde 200 de inmediato y delega a la cola.
 *
 * Rutas SIN auth de sesión (Meta las llama): la seguridad es el verify_token
 * (GET) y la firma HMAC (POST). NO se guardan tokens; el procesamiento pesado
 * va a cola para no bloquear ni exceder el timeout de Meta.
 */
class WebhookMetaController extends Controller
{
    public function __construct(private readonly MetaWebhookService $webhook)
    {
    }

    /** Verificación del webhook (GET). Devuelve el challenge en texto plano. */
    public function verify(Request $request): Response
    {
        $challenge = $this->webhook->verifyChallenge(
            $request->query('hub_mode') ?? $request->query('hub.mode'),
            $request->query('hub_verify_token') ?? $request->query('hub.verify_token'),
            $request->query('hub_challenge') ?? $request->query('hub.challenge'),
        );

        if ($challenge === null) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** Recepción de eventos (POST). 200 rápido + procesamiento en cola. */
    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        if (! $this->webhook->validateSignature($raw, $signature)) {
            // Instrumentación: firma inválida (NUNCA logueamos la firma ni el secret).
            Log::warning('meta.webhook.skipped', [
                'reason'        => 'invalid_signature',
                'has_signature' => $signature !== null && $signature !== '',
                'body_bytes'    => strlen($raw),
            ]);
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();

        // Instrumentación SÍNCRONA y SEGURA (sin tokens/secret/headers): deja en
        // laravel.log qué llegó de Meta, aunque el worker de cola no esté corriendo.
        $this->logIncoming($payload);

        if (! empty($payload)) {
            ProcessMetaWebhookEvent::dispatch($payload);
            Log::info('meta.webhook.queued', [
                'queue_connection' => (string) config('queue.default'),
                'inbound_enabled'  => (bool) config('marketing.inbound.meta_enabled', true),
            ]);
        } else {
            Log::info('meta.webhook.skipped', ['reason' => 'empty_payload']);
        }

        // Meta solo necesita un 200 rápido para no reintentar.
        return response()->json(['ok' => true]);
    }

    /**
     * Logging diagnóstico de un payload de Meta SIN datos sensibles (no tokens,
     * no app_secret, no headers de auth). Resume estructura entry/changes/value,
     * detecta messages vs statuses, el phone_number_id recibido vs el esperado, y
     * los campos clave del primer mensaje/estado. Nunca lanza.
     */
    private function logIncoming(array $payload): void
    {
        try {
            $change   = data_get($payload, 'entry.0.changes.0', []);
            $value    = (array) data_get($change, 'value', []);
            $messages = (array) data_get($value, 'messages', []);
            $statuses = (array) data_get($value, 'statuses', []);

            Log::info('meta.webhook.received', [
                'object'                   => $payload['object'] ?? null,
                'entries'                  => is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0,
                'field'                    => $change['field'] ?? null,
                'phone_number_id'          => data_get($value, 'metadata.phone_number_id'),
                'expected_phone_number_id' => (string) config('meta.whatsapp_phone_number_id'),
                'messages_count'           => count($messages),
                'statuses_count'           => count($statuses),
            ]);

            if ($messages !== []) {
                $m = (array) $messages[0];
                Log::info('meta.webhook.message_detected', [
                    'message_id' => $m['id'] ?? null,
                    'type'       => $m['type'] ?? null,
                    'from'       => $m['from'] ?? null,
                    'wa_id'      => data_get($value, 'contacts.0.wa_id'),
                    'has_text'   => isset($m['text']['body']),
                    // Cuerpo del texto ACOTADO, solo para diagnóstico temporal.
                    'text'       => isset($m['text']['body']) ? mb_substr((string) $m['text']['body'], 0, 120) : null,
                ]);
            }

            if ($statuses !== []) {
                $s = (array) $statuses[0];
                Log::info('meta.webhook.status_detected', [
                    'message_id'   => $s['id'] ?? null,
                    'status'       => $s['status'] ?? null,
                    'recipient_id' => $s['recipient_id'] ?? null,
                ]);
            }

            if ($messages === [] && $statuses === []) {
                Log::info('meta.webhook.skipped', [
                    'reason' => 'no_messages_no_statuses',
                    'field'  => $change['field'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('meta.webhook.log_error', ['error' => class_basename($e)]);
        }
    }
}
