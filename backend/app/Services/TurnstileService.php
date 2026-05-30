<?php

namespace App\Services;

use App\Models\TurnstileSetting;
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

    public function __construct(
        private readonly ZktecoTurnstileService $zkteco,
        private readonly SerialTurnstileService $serial,
    ) {
    }

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
            'user_id'     => 0,
            'action'      => 'entry',
            'duration_ms' => $settings->open_duration_ms,
            'timestamp'   => now()->toIso8601String(),
        ];
        $vars = array_merge($defaults, $context);

        $url = $this->interpolate($settings->webhook_url, $vars);
        $method = strtoupper($settings->http_method ?: 'POST');

        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->acceptJson();

        if ($settings->auth_header) {
            [$headerName, $headerValue] = $this->splitAuthHeader(
                $this->interpolate($settings->auth_header, $vars),
            );
            if ($headerName) {
                $request = $request->withHeaders([$headerName => $headerValue]);
            }
        }

        $payloadString = $settings->request_payload
            ? $this->interpolate($settings->request_payload, $vars)
            : null;

        try {
            $response = match ($method) {
                'GET'  => $request->get($url),
                'PUT'  => $request->withBody($payloadString ?? '', 'application/json')->put($url),
                'PATCH' => $request->withBody($payloadString ?? '', 'application/json')->patch($url),
                default => $payloadString
                    ? $request->withBody($payloadString, 'application/json')->post($url)
                    : $request->post($url, $vars),
            };

            $ok = $response->successful();
            $settings->forceFill([
                'last_triggered_at' => now(),
                'last_status'       => $ok ? 'success' : 'error',
                'last_http_code'    => $response->status(),
                'last_error'        => $ok ? null : substr((string) $response->body(), 0, 480),
            ])->save();

            return [
                'ok' => $ok,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 480),
            ];
        } catch (Throwable $e) {
            Log::warning('Turnstile webhook failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            $settings->forceFill([
                'last_triggered_at' => now(),
                'last_status'       => 'error',
                'last_http_code'    => null,
                'last_error'        => substr($e->getMessage(), 0, 480),
            ])->save();

            return ['ok' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
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
            'last_status'       => $result['ok'] ? 'success' : 'error',
            'last_http_code'    => null,
            'last_error'        => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
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

        $durationSeconds = max(1, (int) round(($settings->open_duration_ms ?: 3000) / 1000));

        $result = $this->zkteco->openDoor(
            host: $settings->device_host,
            port: $settings->device_port ?: 4370,
            durationSeconds: $durationSeconds,
            commKey: $settings->device_comm_key,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status'       => $result['ok'] ? 'success' : 'error',
            'last_http_code'    => null,
            'last_error'        => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
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
