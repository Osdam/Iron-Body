<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhookEvent;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();
        if (! empty($payload)) {
            ProcessMetaWebhookEvent::dispatch($payload);
        }

        // Meta solo necesita un 200 rápido para no reintentar.
        return response()->json(['ok' => true]);
    }
}
