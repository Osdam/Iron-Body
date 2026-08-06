<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhookEvent;
use App\Services\Meta\MetaWebhookIngestService;
use App\Services\Meta\MetaWebhookService;
use App\Services\Observability\ChannelLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Webhook público de Meta (Instagram / Facebook / WhatsApp).
 *
 *  GET  /api/webhooks/meta  → verificación (hub.challenge + verify_token).
 *  POST /api/webhooks/meta  → eventos. Valida firma X-Hub-Signature-256,
 *                             PERSISTE el evento crudo, responde 200 de
 *                             inmediato y delega el trabajo pesado a la cola.
 *
 * Rutas SIN auth de sesión (Meta las llama): la seguridad es el verify_token
 * (GET) y la firma HMAC (POST). NO se guardan tokens; ninguna lógica de IA corre
 * dentro de este request.
 *
 * El orden importa: primero se guarda lo que Meta dijo, después se encola. Si el
 * worker no está vivo, el mensaje del prospecto sigue existiendo y se puede
 * reprocesar; antes se perdía.
 */
class WebhookMetaController extends Controller
{
    /** Techo del cuerpo aceptado. Meta no envía payloads grandes; uno enorme es un abuso. */
    private const MAX_BODY_BYTES = 512 * 1024;

    public function __construct(
        private readonly MetaWebhookService $webhook,
        private readonly MetaWebhookIngestService $ingest,
    ) {}

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

    /** Recepción de eventos (POST). Persistir → 200 rápido → cola. */
    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        // El correlation_id nace aquí y acompaña al evento por todo el sistema:
        // job, mensaje, decisión del agente, envío a Meta y status callback.
        $correlationId = $this->ingest->newCorrelationId();
        Context::add('correlation_id', $correlationId);

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            ChannelLog::warning('meta.webhook.rejected', [
                'reason' => 'body_too_large',
                'body_bytes' => strlen($raw),
            ]);

            return response()->json(['ok' => false], 413);
        }

        if (! $this->webhook->validateSignature($raw, $signature)) {
            // Instrumentación: firma inválida (NUNCA logueamos la firma ni el secret).
            ChannelLog::warning('meta.webhook.rejected', [
                'reason' => 'invalid_signature',
                'has_signature' => $signature !== null && $signature !== '',
                'body_bytes' => strlen($raw),
            ]);

            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();

        if (empty($payload)) {
            ChannelLog::info('meta.webhook.skipped', ['reason' => 'empty_payload']);

            return response()->json(['ok' => true]);
        }

        // Persistencia SÍNCRONA del hecho original. Si esto falla no podemos
        // garantizar el mensaje, así que se devuelve 500 y Meta reintenta: es
        // preferible una reentrega (que el hash deduplica) a perder al prospecto.
        try {
            ['event' => $event, 'duplicate' => $duplicate] = $this->ingest->record($raw, $payload, $correlationId);
        } catch (Throwable $e) {
            ChannelLog::error('meta.webhook.persist_failed', [
                'error_class' => class_basename($e),
                'body_bytes' => strlen($raw),
            ]);

            return response()->json(['ok' => false], 500);
        }

        ChannelLog::info('meta.webhook.received', [
            'event_id' => $event->id,
            'object' => $event->object,
            'phone_number_id' => $event->phone_number_id,
            'expected_phone_number_id' => (string) config('meta.whatsapp_phone_number_id'),
            'messages_count' => $event->messages_count,
            'statuses_count' => $event->statuses_count,
            'body_bytes' => $event->payload_bytes,
            'duplicate' => $duplicate,
        ]);

        if ($duplicate) {
            // Reentrega de Meta o replay del mismo cuerpo firmado: ya lo tenemos.
            ChannelLog::info('meta.webhook.skipped', [
                'reason' => 'duplicate_delivery',
                'event_id' => $event->id,
                'original_correlation_id' => $event->correlation_id,
            ]);

            return response()->json(['ok' => true]);
        }

        ProcessMetaWebhookEvent::dispatch($event->id);
        ChannelLog::info('meta.webhook.queued', [
            'event_id' => $event->id,
            'queue_connection' => (string) config('queue.default'),
            'inbound_enabled' => (bool) config('marketing.inbound.meta_enabled', true),
        ]);

        // Meta solo necesita un 200 rápido para no reintentar.
        return response()->json(['ok' => true]);
    }
}
