<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wompi\WompiWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook PÚBLICO de Wompi (POST /api/webhooks/wompi). Sin sesión de usuario.
 * La autenticidad se valida por el CHECKSUM oficial del evento (no por auth).
 * Responde 200 a eventos válidos o duplicados ya procesados; 401 si la firma es
 * inválida; 500 solo si queremos que Wompi reintente. Nunca revela secretos.
 */
class WompiWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['received' => false], 400);
        }

        // Cuerpo CRUDO para el hash de dedupe (idempotencia de reentregas).
        $rawBody = $request->getContent();

        $result = WompiWebhookService::make()->handle($payload, $rawBody);

        return response()->json([
            'received' => $result['http'] === 200,
            'status'   => $result['status'],
        ], $result['http']);
    }
}
