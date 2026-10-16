<?php

namespace App\Services;

use App\Models\TurnstileSetting;
use App\Support\Network\OutboundDestination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispara la apertura del torniquete vía webhook HTTP.
 *
 * Compatible con cualquier dispositivo que reciba GET o POST: ESP32 con
 * relé, Sonoff Mini, Shelly Plug, controladores ZKTeco / Hikvision con API
 * HTTP, o un Raspberry Pi corriendo Flask. La configuración (URL, método,
 * payload, header de auth) vive en `turnstile_settings`.
 *
 * Sustituye los placeholders {member_name}, {user_id}, {action},
 * {duration_ms} y {timestamp} en webhook_url, request_payload y auth_header.
 */
class TurnstileService
{
    private const TIMEOUT_SECONDS = 3;

    /** Lo único que sale hacia el CRM (y a `last_error`) cuando el destino se rechaza. */
    public const DESTINATION_NOT_ALLOWED = 'Ese destino no está permitido para el torniquete: usa la IP del dispositivo en la red de la sede.';

    /** Lo único que sale cuando no hubo respuesta; el detalle va al log del servidor. */
    private const UNREACHABLE = 'El dispositivo no respondió.';

    public function __construct(
        private readonly ZktecoTurnstileService $zkteco,
        private readonly SerialTurnstileService $serial,
    ) {}

    public function trigger(TurnstileSetting $settings, array $context = []): array
    {
        if (! $settings->enabled) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        if ($settings->mode === 'serial') {
            return $this->triggerSerial($settings);
        }

        if ($settings->mode === 'zkteco') {
            return $this->triggerZkteco($settings);
        }

        if (! $settings->webhook_url) {
            return ['ok' => false, 'reason' => 'no_webhook_url'];
        }

        $defaults = [
            'member_name' => '—',
            'user_id' => 0,
            'action' => 'entry',
            'duration_ms' => $settings->open_duration_ms,
            'timestamp' => now()->toIso8601String(),
        ];
        $vars = array_merge($defaults, $context);

        $url = $this->interpolate($settings->webhook_url, $vars);
        $method = strtoupper($settings->http_method ?: 'POST');

        $headers = [];
        if ($settings->auth_header) {
            [$headerName, $headerValue] = $this->splitAuthHeader(
                $this->interpolate($settings->auth_header, $vars),
            );
            if ($headerName) {
                $headers[$headerName] = $headerValue;
            }
        }

        $payloadString = $settings->request_payload
            ? $this->interpolate($settings->request_payload, $vars)
            : null;

        return $this->sendWebhook($settings, $url, $method, $payloadString, $headers, $vars);
    }

    /**
     * La petición HTTP al relé, para el disparo con la configuración guardada
     * y para la prueba ad-hoc del CRM (`turnstile/webhook/fire`).
     *
     * Es el SERVIDOR el que llama a una URL que escribe alguien en el CRM. Por
     * eso: solo a destinos permitidos (ver OutboundDestination: nunca el propio
     * servidor ni los metadatos de la nube), sin seguir redirecciones —un 302
     * hacia 169.254.169.254 se saltaba ese control—, y sin devolver ni guardar
     * lo que contesta el destino: solo su estado HTTP. Antes volvían 480
     * caracteres del cuerpo en la respuesta y en `last_error`.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $formData  cuerpo JSON de un POST sin payload
     * @return array{ok: bool, status?: int, reason?: string, error?: string}
     */
    public function sendWebhook(
        TurnstileSetting $settings,
        string $url,
        string $method,
        ?string $payload = null,
        array $headers = [],
        array $formData = [],
    ): array {
        $destination = OutboundDestination::forUrl($url);
        if ($destination === null) {
            $this->recordOutcome($settings, false, null, self::DESTINATION_NOT_ALLOWED);

            return ['ok' => false, 'reason' => 'destination_not_allowed', 'error' => self::DESTINATION_NOT_ALLOWED];
        }

        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withoutRedirecting()
            ->withHeaders($headers);

        if ($destination['pin'] !== null) {
            // La conexión va a la IP ya validada, no a lo que diga el DNS ahora.
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$destination['pin']]]]);
        }

        try {
            $response = match ($method) {
                'GET' => $request->get($url),
                'PUT' => $request->withBody($payload ?? '', 'application/json')->put($url),
                'PATCH' => $request->withBody($payload ?? '', 'application/json')->patch($url),
                default => filled($payload)
                    ? $request->withBody($payload, 'application/json')->post($url)
                    : $request->post($url, $formData),
            };
        } catch (Throwable $e) {
            // El detalle (host, puerto, «connection refused») se queda en el
            // log del servidor: hacia el CRM sería un escáner de puertos.
            Log::warning('Turnstile webhook failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            $this->recordOutcome($settings, false, null, self::UNREACHABLE);

            return ['ok' => false, 'reason' => 'exception', 'error' => self::UNREACHABLE];
        }

        $ok = $response->successful();
        $this->recordOutcome($settings, $ok, $response->status(), $ok ? null : 'HTTP '.$response->status());

        return ['ok' => $ok, 'status' => $response->status()];
    }

    private function recordOutcome(TurnstileSetting $settings, bool $ok, ?int $httpCode, ?string $error): void
    {
        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $ok ? 'success' : 'error',
            'last_http_code' => $httpCode,
            'last_error' => $error,
        ])->save();
    }

    /**
     * Dispara la apertura por puerto COM (replica NetGymValidator: ASCII
     * "PULSE 3000\r\n" → USB-CH340 → RS485 → placa SATT).
     */
    private function triggerSerial(TurnstileSetting $settings): array
    {
        if (! $settings->serial_port) {
            return ['ok' => false, 'reason' => 'no_serial_port'];
        }

        $result = $this->serial->open(
            port: $settings->serial_port,
            baud: $settings->serial_baud ?: 9600,
            command: $settings->serial_command ?: 'PULSE 3000',
        );

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $result['ok'] ? 'success' : 'error',
            'last_http_code' => null,
            'last_error' => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        return $result;
    }

    /**
     * Dispara la apertura vía SDK standalone ZKTeco (TCP puerto 4370).
     * Convierte open_duration_ms a segundos enteros (mínimo 1).
     */
    private function triggerZkteco(TurnstileSetting $settings): array
    {
        if (! $settings->device_host) {
            return ['ok' => false, 'reason' => 'no_device_host'];
        }

        // Socket TCP hacia un host que escribe alguien en el CRM: mismo control
        // que el relé HTTP, y a la IP resuelta, no al nombre.
        $host = OutboundDestination::resolve((string) $settings->device_host);
        if ($host === null) {
            $this->recordOutcome($settings, false, null, self::DESTINATION_NOT_ALLOWED);

            return ['ok' => false, 'reason' => 'destination_not_allowed', 'error' => self::DESTINATION_NOT_ALLOWED];
        }

        $durationSeconds = max(1, (int) round(($settings->open_duration_ms ?: 3000) / 1000));

        $result = $this->zkteco->openDoor(
            host: $host,
            port: $settings->device_port ?: 4370,
            durationSeconds: $durationSeconds,
            commKey: $settings->device_comm_key,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $result['ok'] ? 'success' : 'error',
            'last_http_code' => null,
            'last_error' => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        return $result;
    }

    private function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/i', function ($match) use ($vars) {
            $key = $match[1];

            return array_key_exists($key, $vars) ? (string) $vars[$key] : $match[0];
        }, $template) ?? $template;
    }

    /** Separa "Authorization: Bearer xxx" → ['Authorization', 'Bearer xxx']. */
    private function splitAuthHeader(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        if (! str_contains($raw, ':')) {
            return ['Authorization', $raw];
        }
        [$name, $value] = explode(':', $raw, 2);

        return [trim($name), trim($value)];
    }
}
