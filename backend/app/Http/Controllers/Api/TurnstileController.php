<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TurnstileSetting;
use App\Services\TurnstileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnstileController extends Controller
{
    public function __construct(private readonly TurnstileService $turnstile)
    {
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

    private function serialize(TurnstileSetting $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'enabled' => $s->enabled,
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
