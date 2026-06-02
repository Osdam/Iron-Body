<?php

namespace App\Services;

use App\Jobs\SendAutomationEventToN8n;
use App\Models\AutomationEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Capa segura de eventos Laravel → n8n (outbox pattern).
 *
 * Persiste cada evento en automation_events (fuente de verdad), genera la
 * idempotency_key, sanea el payload (nunca datos sensibles) y, solo si n8n
 * está habilitado, despacha el job de envío. Si está deshabilitado, el evento
 * queda 'skipped' sin romper nada.
 */
class AutomationEventService
{
    /**
     * Emite un evento de automatización. Idempotente por idempotency_key.
     */
    public function emit(
        string $eventType,
        ?int $memberId = null,
        array $payload = [],
        ?string $idempotencyKey = null,
    ): AutomationEvent {
        $key = $idempotencyKey ?? $this->generateKey($eventType, $memberId);

        // Idempotencia: si ya existe, no duplicamos.
        $existing = AutomationEvent::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $enabled = (bool) config('automation.enabled');

        $event = AutomationEvent::create([
            'event_type' => $eventType,
            'member_id' => $memberId,
            'payload_json' => $this->sanitizePayload($payload),
            'status' => $enabled ? AutomationEvent::STATUS_PENDING : AutomationEvent::STATUS_SKIPPED,
            'idempotency_key' => $key,
            'attempts' => 0,
        ]);

        Log::info('automation.event.emitted', [
            'event_type' => $eventType,
            'event_id' => $event->id,
            'member_id' => $memberId,
            'status' => $event->status,
        ]);

        if ($enabled) {
            $this->dispatch($event);
        }

        return $event;
    }

    /**
     * Elimina recursivamente cualquier clave sensible del payload. Defensa en
     * profundidad: aunque un caller pase datos prohibidos, nunca salen a n8n.
     */
    public function sanitizePayload(array $payload): array
    {
        $forbidden = array_map('strtolower', (array) config('automation.forbidden_keys', []));

        $clean = function (array $data) use (&$clean, $forbidden): array {
            $out = [];
            foreach ($data as $key => $value) {
                $lower = is_string($key) ? strtolower($key) : $key;
                // Coincidencia por substring: 'card_number', 'document_image', etc.
                $isForbidden = false;
                if (is_string($lower)) {
                    foreach ($forbidden as $bad) {
                        if (str_contains($lower, $bad)) {
                            $isForbidden = true;
                            break;
                        }
                    }
                }
                if ($isForbidden) {
                    continue;
                }
                $out[$key] = is_array($value) ? $clean($value) : $value;
            }
            return $out;
        };

        return $clean($payload);
    }

    /** Despacha el job de envío a n8n (cola). */
    public function dispatch(AutomationEvent $event): void
    {
        SendAutomationEventToN8n::dispatch($event->id);
    }

    private function generateKey(string $eventType, ?int $memberId): string
    {
        return $eventType . ':' . ($memberId ?? 'global') . ':' . (string) Str::uuid();
    }
}
