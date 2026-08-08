<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salud de los carriles de cola, medida sobre la propia tabla `jobs`.
 *
 * Después de separar las colas hay una pregunta nueva que antes no existía:
 * ¿está atendido cada carril? Con un solo worker la respuesta era trivial —o
 * estaba vivo o no había cola—. Con cinco carriles, el fallo interesante es más
 * silencioso: los cuatro que no importan funcionan, el de los mensajes de
 * clientes se queda sin proceso, y por fuera todo parece normal porque los jobs
 * se siguen encolando sin error. Nadie se enteraría hasta que un cliente
 * llamara preguntando por qué no le contestan.
 *
 * No se inspeccionan procesos del sistema a propósito: preguntarle a Supervisor
 * cuántos workers cree tener responde a la pregunta equivocada. Un worker puede
 * estar corriendo y bloqueado, o vivo pero apuntando a otra cola por un error de
 * configuración. Lo que importa es si el trabajo AVANZA, y eso se ve en dos
 * señales que no se pueden falsear: el latido que deja cada job al terminar, y
 * la edad del trabajo más viejo que sigue esperando.
 */
class QueueHealthService
{
    /** Prefijo del latido que deja cada worker al terminar un job. */
    private const HEARTBEAT_KEY = 'queue:heartbeat:';

    /** Contador de jobs terminados por minuto y carril. */
    private const RATE_KEY = 'queue:rate:';

    /**
     * Registra que un carril acaba de terminar un trabajo.
     *
     * Se llama desde el listener de `JobProcessed`. Es la única prueba directa
     * de que hay alguien atendiendo ese carril: si el latido envejece mientras
     * hay cola, no hay worker o está atascado.
     */
    public function heartbeat(string $queue): void
    {
        Cache::put(self::HEARTBEAT_KEY.$queue, now()->timestamp, now()->addHours(6));

        $minuteKey = self::RATE_KEY.$queue.':'.now()->format('YmdHi');
        Cache::add($minuteKey, 0, now()->addMinutes(10));
        Cache::increment($minuteKey);
    }

    /**
     * Foto de todos los carriles declarados.
     *
     * @return array<string, array<string,mixed>>
     */
    public function snapshot(): array
    {
        $lanes = (array) config('queue.lanes', []);
        $out = [];

        foreach ($lanes as $name => $lane) {
            $out[$name] = $this->laneSnapshot($name, (array) $lane);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function laneSnapshot(string $name, array $lane): array
    {
        $queue = (string) ($lane['queue'] ?? $name);

        $backlog = 0;
        $reserved = 0;
        $oldestSeconds = 0;

        if (Schema::hasTable('jobs')) {
            $rows = DB::table('jobs')->where('queue', $queue);

            $backlog = (clone $rows)->count();
            $reserved = (clone $rows)->whereNotNull('reserved_at')->count();

            // `available_at` es cuándo el job PODÍA empezar a atenderse. La
            // diferencia con ahora es la espera real acumulada, no una
            // estimación: es exactamente lo que lleva esperando una persona.
            $oldest = (clone $rows)->whereNull('reserved_at')->min('available_at');
            $oldestSeconds = $oldest !== null ? max(0, now()->timestamp - (int) $oldest) : 0;
        }

        $heartbeat = Cache::get(self::HEARTBEAT_KEY.$queue);
        $lastSeenSeconds = $heartbeat !== null ? max(0, now()->timestamp - (int) $heartbeat) : null;

        $failedLastHour = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedLastHour = DB::table('failed_jobs')
                ->where('queue', $queue)
                ->where('failed_at', '>=', now()->subHour())
                ->count();
        }

        return [
            'queue' => $queue,
            'priority' => (int) ($lane['priority'] ?? 9),
            'slo_wait_ms' => (int) ($lane['slo_wait_ms'] ?? 0),
            'backlog' => $backlog,
            'reserved' => $reserved,
            'oldest_pending_seconds' => $oldestSeconds,
            'last_processed_seconds_ago' => $lastSeenSeconds,
            'jobs_last_minute' => (int) (Cache::get(self::RATE_KEY.$queue.':'.now()->subMinute()->format('YmdHi')) ?? 0),
            'failed_last_hour' => $failedLastHour,
            'looks_unattended' => $this->looksUnattended($backlog, $oldestSeconds, $lastSeenSeconds),
            'breaching_slo' => $this->breachingSlo($oldestSeconds, (int) ($lane['slo_wait_ms'] ?? 0)),
        ];
    }

    /**
     * ¿Este carril parece no tener a nadie atendiéndolo?
     *
     * Hacen falta las dos condiciones. Solo «no hay latido» daría falsos
     * positivos constantes en los carriles tranquilos: el de facturación puede
     * pasarse días sin un solo trabajo, y eso es salud, no avería. Solo «hay
     * cola» tampoco basta: una ráfaga legítima acumula cola durante unos
     * segundos con los workers trabajando a tope.
     *
     * Lo que no tiene explicación inocente es la conjunción: hay trabajo
     * esperando, lleva esperando más de lo tolerable, y nadie ha terminado nada
     * en ese carril desde antes de que empezara a acumularse.
     */
    private function looksUnattended(int $backlog, int $oldestSeconds, ?int $lastSeenSeconds): bool
    {
        $graceSeconds = (int) config('observability.queues.unattended_after_seconds', 120);

        if ($backlog === 0 || $oldestSeconds < $graceSeconds) {
            return false;
        }

        return $lastSeenSeconds === null || $lastSeenSeconds >= $graceSeconds;
    }

    /** ¿La espera del más viejo se sale del compromiso del carril? */
    private function breachingSlo(int $oldestSeconds, int $sloWaitMs): bool
    {
        if ($sloWaitMs <= 0) {
            return false;
        }

        // Margen de x4 sobre el SLO antes de considerarlo incumplimiento: el
        // SLO es un p95 sobre una ráfaga, no un techo instantáneo, y alertar al
        // primer pico convertiría la alarma en ruido.
        return $oldestSeconds * 1000 > $sloWaitMs * 4;
    }
}
