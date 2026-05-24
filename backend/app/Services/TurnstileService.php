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

    public function trigger(TurnstileSetting $settings, array $context = []): array
    {
        if (! $settings->enabled) {
            return ['ok' => false, 'reason' => 'disabled'];
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
