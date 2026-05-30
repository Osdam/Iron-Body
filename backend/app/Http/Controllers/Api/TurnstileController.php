<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TurnstileSetting;
use App\Services\SerialTurnstileService;
use App\Services\TurnstileService;
use App\Services\ZktecoTurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class TurnstileController extends Controller
{
    public function __construct(
        private readonly TurnstileService $turnstile,
        private readonly ZktecoTurnstileService $zkteco,
        private readonly SerialTurnstileService $serial,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $this->serialize(TurnstileSetting::current()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'in:webhook,zkteco,serial'],
            'device_host' => ['nullable', 'string', 'max:120'],
            'device_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'device_comm_key' => ['nullable', 'string', 'max:60'],
            'serial_port' => ['nullable', 'string', 'max:20', 'regex:/^COM\d{1,3}$/i'],
            'serial_baud' => ['nullable', 'integer', 'min:300', 'max:921600'],
            'serial_command' => ['nullable', 'string', 'max:120'],
            'webhook_url' => ['nullable', 'string', 'max:500', 'url'],
            'http_method' => ['nullable', 'in:GET,POST,PUT,PATCH'],
            'auth_header' => ['nullable', 'string', 'max:500'],
            'request_payload' => ['nullable', 'string', 'max:4000'],
            'open_duration_ms' => ['nullable', 'integer', 'min:200', 'max:30000'],
            'fire_on_entry' => ['nullable', 'boolean'],
            'fire_on_exit' => ['nullable', 'boolean'],
            'sound_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = TurnstileSetting::current();
        $settings->fill($data)->save();

        return response()->json([
            'ok' => true,
            'data' => $this->serialize($settings->refresh()),
        ]);
    }

    /** Disparo manual desde el CRM. */
    public function trigger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'in:entry,exit'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $settings = TurnstileSetting::current();
        $result = $this->turnstile->trigger($settings, [
            'member_name' => $data['reason'] ?? 'Disparo manual',
            'action' => $data['action'] ?? 'entry',
        ]);

        return response()->json([
            'ok' => $result['ok'] ?? false,
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * Disparo ad-hoc de un webhook arbitrario (Sonoff/ESP32/Shelly). Útil para
     * probar el relé HTTP sin tener que guardar settings primero.
     */
    public function fireWebhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'method' => ['nullable', 'in:GET,POST,PUT,PATCH'],
            'payload' => ['nullable', 'string', 'max:4000'],
        ]);

        $method = strtoupper($data['method'] ?? 'POST');
        $payload = $data['payload'] ?? null;
        $settings = TurnstileSetting::current();

        try {
            $request = Http::timeout(3)->connectTimeout(3)->acceptJson();
            $response = match ($method) {
                'GET'   => $request->get($data['url']),
                'PUT'   => $request->withBody($payload ?? '', 'application/json')->put($data['url']),
                'PATCH' => $request->withBody($payload ?? '', 'application/json')->patch($data['url']),
                default => $payload
                    ? $request->withBody($payload, 'application/json')->post($data['url'])
                    : $request->post($data['url']),
            };

            $ok = $response->successful();
            $settings->forceFill([
                'last_triggered_at' => now(),
                'last_status'       => $ok ? 'success' : 'error',
                'last_http_code'    => $response->status(),
                'last_error'        => $ok ? null : substr((string) $response->body(), 0, 480),
            ])->save();

            return response()->json([
                'ok' => $ok,
                'result' => [
                    'ok' => $ok,
                    'status' => $response->status(),
                    'body' => substr((string) $response->body(), 0, 480),
                ],
                'data' => $this->serialize($settings->refresh()),
            ], $ok ? 200 : 502);
        } catch (Throwable $e) {
            $settings->forceFill([
                'last_triggered_at' => now(),
                'last_status'       => 'error',
                'last_http_code'    => null,
                'last_error'        => substr($e->getMessage(), 0, 480),
            ])->save();

            return response()->json([
                'ok' => false,
                'result' => ['ok' => false, 'error' => $e->getMessage()],
                'data' => $this->serialize($settings->refresh()),
            ], 502);
        }
    }

    /**
     * Apertura por puerto COM (replica NetGymValidator).
     * Acepta override de port/baud/command desde el body, o usa el singleton.
     */
    public function openSerial(Request $request): JsonResponse
    {
        $data = $request->validate([
            'port' => ['nullable', 'string', 'max:20', 'regex:/^COM\d{1,3}$/i'],
            'baud' => ['nullable', 'integer', 'min:300', 'max:921600'],
            'command' => ['nullable', 'string', 'max:120'],
        ]);

        $settings = TurnstileSetting::current();
        $port = $data['port'] ?? $settings->serial_port;
        $baud = $data['baud'] ?? ($settings->serial_baud ?: 9600);
        $command = $data['command'] ?? ($settings->serial_command ?: 'PULSE 3000');

        if (! $port) {
            return response()->json([
                'ok' => false,
                'message' => 'Configura el puerto COM (ej. COM4) en ajustes o envíalo en el body.',
            ], 422);
        }

        $result = $this->serial->open($port, (int) $baud, $command);

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status'       => $result['ok'] ? 'success' : 'error',
            'last_http_code'    => null,
            'last_error'        => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        return response()->json([
            'ok' => (bool) $result['ok'],
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * Apertura directa de un torniquete ZKTeco Eco (SDK standalone, TCP 4370).
     * Si no se envía host/port/comm_key en el body, se toman del singleton.
     * Útil para pruebas desde el CRM sin tener que activar el torniquete global.
     */
    public function openZkteco(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:120'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'comm_key' => ['nullable', 'string', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:254'],
        ]);

        $settings = TurnstileSetting::current();
        $host = $data['host'] ?? $settings->device_host;
        $port = $data['port'] ?? ($settings->device_port ?: 4370);
        $commKey = array_key_exists('comm_key', $data) ? $data['comm_key'] : $settings->device_comm_key;
        $duration = $data['duration_seconds']
            ?? max(1, (int) round(($settings->open_duration_ms ?: 3000) / 1000));

        if (! $host) {
            return response()->json([
                'ok' => false,
                'message' => 'Falta el host del dispositivo ZKTeco (configurar en ajustes o enviar en body).',
            ], 422);
        }

        $result = $this->zkteco->openDoor(
            host: $host,
            port: (int) $port,
            durationSeconds: (int) $duration,
            commKey: $commKey,
        );

        $settings->forceFill([
            'last_triggered_at' => now(),
            'last_status'       => $result['ok'] ? 'success' : 'error',
            'last_http_code'    => null,
            'last_error'        => $result['ok'] ? null : substr((string) ($result['error'] ?? 'unknown'), 0, 480),
        ])->save();

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'result' => $result,
            'data' => $this->serialize($settings->refresh()),
        ], ($result['ok'] ?? false) ? 200 : 502);
    }

    private function serialize(TurnstileSetting $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'enabled' => $s->enabled,
            'mode' => $s->mode,
            'device_host' => $s->device_host,
            'device_port' => $s->device_port,
            'device_comm_key' => $s->device_comm_key,
            'serial_port' => $s->serial_port,
            'serial_baud' => $s->serial_baud,
            'serial_command' => $s->serial_command,
            'webhook_url' => $s->webhook_url,
            'http_method' => $s->http_method,
            'auth_header' => $s->auth_header,
            'request_payload' => $s->request_payload,
            'open_duration_ms' => $s->open_duration_ms,
            'fire_on_entry' => $s->fire_on_entry,
            'fire_on_exit' => $s->fire_on_exit,
            'sound_enabled' => $s->sound_enabled,
            'last_triggered_at' => optional($s->last_triggered_at)->toIso8601String(),
            'last_status' => $s->last_status,
            'last_error' => $s->last_error,
            'last_http_code' => $s->last_http_code,
        ];
    }
}
