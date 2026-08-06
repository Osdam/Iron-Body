<?php

namespace App\Services\Marketing;

use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\Cache;

/**
 * Cortacircuitos de las llamadas a Hermes.
 *
 * Sin esto, si Hermes se cae cada prospecto que escribe paga el timeout íntegro
 * antes de degradar a OpenAI. Con quince personas escribiendo a la vez eso son
 * quince esperas inútiles y quince workers ocupados esperando a un servicio que
 * ya sabemos que no responde.
 *
 * El circuito tiene tres estados:
 *
 *   CERRADO   — todo normal, las llamadas pasan.
 *   ABIERTO   — hubo demasiados fallos seguidos; se degrada al instante a
 *               OpenAI sin tocar la red. Dura un tiempo de enfriamiento.
 *   MEDIO     — pasado el enfriamiento, se deja pasar UNA llamada de prueba. Si
 *               va bien, el circuito se cierra; si falla, se vuelve a abrir.
 *
 * El estado vive en caché compartida y no en memoria del proceso: cada request
 * de PHP es un proceso nuevo, así que una variable estática no serviría de nada.
 */
class HermesCircuitBreaker
{
    private const KEY_FAILURES = 'hermes:cb:failures';

    private const KEY_OPEN_UNTIL = 'hermes:cb:open_until';

    /** ¿Se puede intentar una llamada ahora mismo? */
    public function allows(): bool
    {
        $openUntil = Cache::get(self::KEY_OPEN_UNTIL);

        if ($openUntil === null) {
            return true; // circuito cerrado
        }

        if (now()->timestamp < (int) $openUntil) {
            return false; // abierto: degradar sin tocar la red
        }

        // Enfriamiento cumplido: se permite UNA llamada de prueba. Se borra la
        // marca ya, para que dos peticiones simultáneas no manden las dos.
        Cache::forget(self::KEY_OPEN_UNTIL);

        ChannelLog::info('hermes.circuit.half_open', []);

        return true;
    }

    /** Llamada correcta: se olvida el historial de fallos. */
    public function recordSuccess(): void
    {
        if (Cache::get(self::KEY_FAILURES) !== null) {
            ChannelLog::info('hermes.circuit.closed', []);
        }

        Cache::forget(self::KEY_FAILURES);
        Cache::forget(self::KEY_OPEN_UNTIL);
    }

    /** Llamada fallida: si se cruza el umbral, se abre el circuito. */
    public function recordFailure(string $reason = 'unknown'): void
    {
        $threshold = max(1, (int) config('marketing.ai.hermes.circuit_breaker.failure_threshold', 3));
        $window = max(10, (int) config('marketing.ai.hermes.circuit_breaker.window_seconds', 120));
        $cooldown = max(5, (int) config('marketing.ai.hermes.circuit_breaker.cooldown_seconds', 60));

        $failures = (int) Cache::get(self::KEY_FAILURES, 0) + 1;
        Cache::put(self::KEY_FAILURES, $failures, $window);

        if ($failures < $threshold) {
            return;
        }

        Cache::put(self::KEY_OPEN_UNTIL, now()->timestamp + $cooldown, $cooldown + 60);
        Cache::forget(self::KEY_FAILURES);

        ChannelLog::warning('hermes.circuit.opened', [
            'failures' => $failures,
            'cooldown_seconds' => $cooldown,
            'reason' => $reason,
        ]);
    }

    /** Estado legible para el doctor y el panel de IRON GUARD. */
    public function state(): array
    {
        $openUntil = Cache::get(self::KEY_OPEN_UNTIL);
        $isOpen = $openUntil !== null && now()->timestamp < (int) $openUntil;

        return [
            'state' => $isOpen ? 'open' : 'closed',
            'consecutive_failures' => (int) Cache::get(self::KEY_FAILURES, 0),
            'reopens_in_seconds' => $isOpen ? (int) $openUntil - now()->timestamp : null,
        ];
    }

    /** Reinicio manual (runbook de IRON GUARD, reversible e idempotente). */
    public function reset(): void
    {
        Cache::forget(self::KEY_FAILURES);
        Cache::forget(self::KEY_OPEN_UNTIL);
    }
}
