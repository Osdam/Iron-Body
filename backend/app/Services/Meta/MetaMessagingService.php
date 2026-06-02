<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío de mensajes salientes (Instagram / Facebook / WhatsApp) vía Graph API.
 *
 * GATED por META_ENABLED: si la integración no está habilitada/configurada, NO
 * contacta a Meta y devuelve false (scaffolding seguro, sin mocks). La lógica
 * real de envío queda lista para activarse cuando existan tokens + dominio.
 */
class MetaMessagingService
{
    public function __construct(private readonly MetaAuthService $auth)
    {
    }

    /**
     * Envía texto a un usuario de WhatsApp por su phone number id.
     * Respeta la ventana de 24h / plantillas: aquí NO se fuerza nada; el caller
     * decide. Devuelve el message id de Meta o null.
     */
    public function sendWhatsappText(string $toWaId, string $body): ?string
    {
        if (! $this->auth->isConfigured()) {
            Log::info('meta.messaging.skipped', ['reason' => 'disabled_or_unconfigured', 'channel' => 'whatsapp']);
            return null;
        }

        $phoneNumberId = (string) config('meta.whatsapp_phone_number_id');
        if ($phoneNumberId === '') {
            return null;
        }

        try {
            $resp = Http::withToken((string) $this->auth->accessToken())
                ->timeout($this->auth->timeout())
                ->post($this->auth->graphUrl("{$phoneNumberId}/messages"), [
                    'messaging_product' => 'whatsapp',
                    'to'                => $toWaId,
                    'type'              => 'text',
                    'text'              => ['body' => $body],
                ]);

            if ($resp->failed()) {
                Log::warning('meta.messaging.failed', ['status' => $resp->status(), 'channel' => 'whatsapp']);
                return null;
            }

            return $resp->json('messages.0.id');
        } catch (Throwable $e) {
            Log::warning('meta.messaging.exception', ['error' => class_basename($e), 'channel' => 'whatsapp']);
            return null;
        }
    }
}
