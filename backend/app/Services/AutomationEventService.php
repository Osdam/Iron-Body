<?php

namespace App\Services;

use App\Jobs\SendAutomationEventToN8n;
use App\Models\AutomationEvent;
use Illuminate\Support\Facades\Cache;
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
    /** Dónde se guarda el siguiente hueco libre de la cadencia. */
    private const CLAVE_CADENCIA = 'automation:dispatch:siguiente-hueco';

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

        // Coach humano: si el evento es relevante para seguimiento y el miembro
        // tiene entrenador activo, se crea una tarea accionable. Es INDEPENDIENTE
        // de n8n (el seguimiento del entrenador no debe depender del webhook) y
        // best-effort: nunca rompe la emisión del evento.
        try {
            app(TrainerTaskService::class)->createFromEvent($event);
        } catch (\Throwable $e) {
            Log::warning('automation.event.trainer_task_failed', [
                'event_id' => $event->id,
                'reason' => class_basename($e),
            ]);
        }

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

    /**
     * Despacha el job de envío a n8n, ESPACIADO.
     *
     * Iba sin freno, y un detector diario que recorre a todos los socios lo
     * demostró: 3.758 eventos en 28 segundos. n8n los relevó a ~20 por segundo
     * contra `notify-member` y el tope de la puerta rechazó 3.278 de 3.870.
     * O sea, la avalancha se ahogó a sí misma: el 85% de esas notificaciones
     * no llegó a nadie. De paso vació el cubo de rate limit que entonces
     * compartía con el asesor, y tres mensajes de WhatsApp se quedaron sin
     * respuesta.
     *
     * Aquí cada evento toma el SIGUIENTE HUECO LIBRE de una cadencia. Una
     * ráfaga se reparte sola en el tiempo y un evento suelto no espera nada,
     * porque su hueco es ahora. No hace falta que el detector sepa nada de
     * esto: pacer donde se despacha los cubre a todos.
     */
    public function dispatch(AutomationEvent $event): void
    {
        SendAutomationEventToN8n::dispatch($event->id)->delay($this->siguienteHueco());
    }

    /**
     * Cuánto esperar para no pasarse de la cadencia, en segundos.
     *
     * Un asignador de huecos, no una ventana: se guarda cuándo queda libre el
     * siguiente y cada llamada se lleva el suyo. Si hace rato que no se
     * despacha nada, el hueco es el presente y el retraso es cero.
     */
    private function siguienteHueco(): int
    {
        $porMinuto = max(1, (int) config('automation.dispatch_per_minute', 240));
        $intervaloMs = (int) max(1, round(60000 / $porMinuto));
        $ahoraMs = (int) round(microtime(true) * 1000);

        /*
         * SIN TECHO, Y ES DELIBERADO.
         *
         * Lo tuvo. La idea era que un evento suelto no pagara la avalancha de
         * otro, y el efecto medido fue peor: al recortar todas las esperas que
         * pasaban del techo, 1.361 eventos quedaban citados en el MISMO
         * instante. Una manada diferida es la avalancha otra vez, sólo que
         * diez minutos más tarde.
         *
         * La cola es primero en llegar, primero en salir, y la cola de una
         * ráfaga de miles tarda lo que tiene que tardar: es la consecuencia
         * honesta de emitirlos todos de golpe, y se arregla donde nacen, no
         * aquí. Nadie más lo paga, porque las dos puertas internas ya no
         * comparten cubo.
         */
        $libreMs = max((int) Cache::get(self::CLAVE_CADENCIA, 0), $ahoraMs);

        Cache::put(self::CLAVE_CADENCIA, $libreMs + $intervaloMs, now()->addHours(2));

        return (int) max(0, (int) ceil(($libreMs - $ahoraMs) / 1000));
    }

    private function generateKey(string $eventType, ?int $memberId): string
    {
        return $eventType.':'.($memberId ?? 'global').':'.(string) Str::uuid();
    }
}
