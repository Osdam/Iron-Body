<?php

namespace App\Services\Meta;

use App\Services\Observability\ChannelLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envío de mensajes salientes (WhatsApp Cloud API) vía Graph API.
 *
 * GATED por META_ENABLED: si la integración no está habilitada/configurada, NO
 * contacta a Meta y devuelve un resultado no-enviado (scaffolding seguro).
 *
 * Devuelve un RESULTADO, no solo el id. Antes, un envío fallido y uno
 * rechazado para siempre eran indistinguibles —ambos `null`—, así que el
 * sistema no podía decidir si reintentar o rendirse, y quien atendía veía
 * "failed" sin saber por qué. Ahora cada respuesta trae el código de Meta y si
 * el fallo es transitorio.
 */
class MetaMessagingService
{
    /**
     * Códigos de Meta que SÍ merecen otro intento: límites de tasa y averías
     * pasajeras suyas. Todo lo demás (número inválido, plantilla mal, ventana
     * de 24 h cerrada) no mejora por insistir y reintentarlo solo gasta cuota
     * y arriesga un envío duplicado.
     */
    private const RETRYABLE_ERROR_CODES = [
        1,        // API desconocido / transitorio
        2,        // servicio temporalmente no disponible
        4,        // límite de peticiones de la aplicación
        80007,    // límite de tasa del negocio
        130429,   // límite de mensajes (throughput)
        131000,   // error interno de Meta
        131016,   // servicio no disponible
        131048,   // límite de calidad/spam alcanzado
        133016,   // recurso temporalmente bloqueado
    ];

    public function __construct(private readonly MetaAuthService $auth) {}

    /**
     * Texto plano a un número de WhatsApp.
     *
     * @param  string|null  $replyToMetaMessageId  Cita al mensaje del cliente.
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    public function sendWhatsappText(string $toWaId, string $body, ?string $replyToMetaMessageId = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => false],
        ];

        if ($replyToMetaMessageId !== null) {
            $payload['context'] = ['message_id' => $replyToMetaMessageId];
        }

        return $this->send($payload, 'text');
    }

    /**
     * Plantilla aprobada. Es la ÚNICA forma de escribir primero o de retomar
     * una conversación cuando ya pasaron 24 horas desde el último mensaje del
     * cliente; fuera de eso Meta rechaza el envío con 131047.
     *
     * @param  array<int,array<string,mixed>>  $components
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    public function sendWhatsappTemplate(string $toWaId, string $templateName, string $language, array $components = []): array
    {
        $template = ['name' => $templateName, 'language' => ['code' => $language]];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => 'template',
            'template' => $template,
        ], 'template');
    }

    /**
     * Adjunto saliente. `$source` es un media_id ya subido a Meta o una URL
     * pública; se distinguen porque una URL trae esquema.
     *
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    public function sendWhatsappMedia(
        string $toWaId,
        string $kind,
        string $source,
        ?string $caption = null,
        ?string $filename = null,
    ): array {
        $node = str_starts_with($source, 'http://') || str_starts_with($source, 'https://')
            ? ['link' => $source]
            : ['id' => $source];

        // Sticker y audio no admiten pie de foto en Cloud API; mandarlo es un 400.
        if ($caption !== null && in_array($kind, ['image', 'video', 'document'], true)) {
            $node['caption'] = $caption;
        }
        if ($filename !== null && $kind === 'document') {
            $node['filename'] = $filename;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toWaId,
            'type' => $kind,
            $kind => $node,
        ], 'media:'.$kind);
    }

    /**
     * Marca como leído el mensaje del cliente (el doble check azul en su
     * teléfono). Cortesía, no crítico: un fallo aquí no rompe nada.
     */
    public function markAsRead(string $metaMessageId): bool
    {
        $result = $this->send([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $metaMessageId,
        ], 'read_receipt');

        return $result['ok'];
    }

    /**
     * Llamada real a Graph. Nunca lanza y nunca loguea el token ni el cuerpo
     * del mensaje.
     *
     * @param  array<string,mixed>  $payload
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    private function send(array $payload, string $kind): array
    {
        $base = [
            'ok' => false, 'message_id' => null, 'http_status' => null,
            'error_code' => null, 'error_title' => null, 'error_message' => null,
            'retryable' => false, 'reason' => null,
        ];

        if (! $this->auth->isConfigured()) {
            ChannelLog::info('meta.send.skipped', ['reason' => 'disabled_or_unconfigured', 'kind' => $kind]);

            return array_merge($base, ['reason' => 'meta_disabled_or_unconfigured']);
        }

        $phoneNumberId = (string) $this->auth->phoneNumberId();
        if ($phoneNumberId === '') {
            return array_merge($base, ['reason' => 'missing_phone_number_id']);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) $this->auth->accessToken())
                ->timeout($this->auth->timeout())
                ->asJson()
                ->post($this->auth->graphUrl("{$phoneNumberId}/messages"), $payload);
        } catch (Throwable $e) {
            // Timeout o red caída: no sabemos si Meta lo recibió. Se marca
            // reintentable, pero quien reintente debe comprobar antes que no
            // haya un id de proveedor ya guardado.
            ChannelLog::warning('meta.send.exception', [
                'kind' => $kind,
                'error_class' => class_basename($e),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return array_merge($base, [
                'reason' => 'transport_error',
                'error_title' => class_basename($e),
                'retryable' => true,
            ]);
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

        if ($response->successful()) {
            $messageId = $response->json('messages.0.id');

            ChannelLog::info('meta.send.ok', [
                'kind' => $kind,
                'http_status' => $response->status(),
                'duration_ms' => $durationMs,
                'has_message_id' => $messageId !== null,
            ]);

            return array_merge($base, [
                'ok' => true,
                'message_id' => is_string($messageId) ? $messageId : null,
                'http_status' => $response->status(),
            ]);
        }

        return $this->failure($response, $kind, $durationMs, $base);
    }

    /**
     * Traduce el fallo de Graph a algo accionable, decidiendo si insistir tiene
     * sentido.
     *
     * @param  array<string,mixed>  $base
     * @return array{ok:bool,message_id:?string,http_status:?int,error_code:?int,error_title:?string,error_message:?string,retryable:bool,reason:?string}
     */
    private function failure(Response $response, string $kind, float $durationMs, array $base): array
    {
        $error = (array) ($response->json('error') ?? []);
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $status = $response->status();

        // Un 5xx sin código propio también es cosa de Meta, no nuestra.
        $retryable = ($code !== null && in_array($code, self::RETRYABLE_ERROR_CODES, true))
            || $status === 429
            || $status >= 500;

        ChannelLog::warning('meta.send.failed', [
            'kind' => $kind,
            'http_status' => $status,
            'error_code' => $code,
            'error_title' => $error['error_subcode'] ?? ($error['type'] ?? null),
            'retryable' => $retryable,
            'duration_ms' => $durationMs,
        ]);

        return array_merge($base, [
            'http_status' => $status,
            'error_code' => $code,
            'error_title' => isset($error['type']) ? (string) $error['type'] : null,
            'error_message' => isset($error['message']) ? (string) $error['message'] : null,
            'retryable' => $retryable,
            'reason' => 'provider_send_failed',
        ]);
    }
}
