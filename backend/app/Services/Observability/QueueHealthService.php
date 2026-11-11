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
 *
 * Auditoría B-T16 (H-7 de OPS7): esas dos señales no ven a un worker que MUERE
 * con un trabajo reservado (la espera solo cuenta los trabajos sin reservar).
 * En los carriles con `watch_reserved` se mira además la tercera: un trabajo
 * reservado hace más que el `retry_after` de su conexión (y un margen) sin un
 * latido del carril desde que se reservó es un trabajo HUÉRFANO
 * (`looks_orphaned`).
 */
class QueueHealthService
{
    /** Prefijo del latido que deja cada worker al terminar un job. */
    private const HEARTBEAT_KEY = 'queue:heartbeat:';

    /** Contador de jobs terminados por minuto y carril. */
    private const RATE_KEY = 'queue:rate:';

    /**
     * B-T16: margen sobre el `retry_after` antes de llamar huérfano a un
     * trabajo reservado. Un worker VIVO recoge un trabajo con la reserva
     * vencida en su siguiente sondeo (segundos): el margen evita avisar en esa
     * ventana.
     */
    public const ORPHAN_MARGIN_SECONDS = 60;

    /** B-T16: cuántos trabajos huérfanos se miran por carril y pasada (cada worker muerto deja uno). */
    public const ORPHANS_MAX = 20;

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

        $watchReserved = ($lane['watch_reserved'] ?? false) === true;
        $retryAfter = $this->retryAfter($lane);
        $orphans = $watchReserved && $retryAfter !== null ? $this->orphanedReservations($queue, $retryAfter, $heartbeat) : [];

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
            'looks_unattended' => $this->looksUnattended($backlog, $oldestSeconds, $lastSeenSeconds, $this->unattendedAfter($lane)),
            'breaching_slo' => $this->breachingSlo($oldestSeconds, (int) ($lane['slo_wait_ms'] ?? 0)),
            // B-T16, aditivo: los trabajos reservados cuyo worker murió con ellos (solo en los carriles con `watch_reserved`).
            'watch_reserved' => $watchReserved,
            'retry_after_seconds' => $retryAfter,
            'orphaned_reserved' => count($orphans),
            'orphaned_jobs' => $orphans,
            'looks_orphaned' => $orphans !== [],
        ];
    }

    /**
     * B-T16: el `retry_after` de la conexión del carril (el tiempo tras el que
     * la cola da por muerto un trabajo reservado y deja que otro worker lo
     * tome), o null si no hay uno positivo.
     *
     * @param  array<string, mixed>  $lane
     */
    private function retryAfter(array $lane): ?int
    {
        $conexion = $lane['connection'] ?? null;
        $valor = is_string($conexion) ? config('queue.connections.'.$conexion.'.retry_after') : null;

        return is_int($valor) && $valor > 0 ? $valor : null;
    }

    /**
     * B-T16: los trabajos RESERVADOS de la cola hace más de `retry_after` +
     * {@see ORPHAN_MARGIN_SECONDS} sin un latido del carril DESDE QUE SE
     * RESERVARON. Hacen falta las dos condiciones: una reserva vieja con un
     * latido posterior dice que hay un worker vivo en el carril (la recogerá
     * él en su siguiente sondeo); sin latido posterior, el que la tenía murió y
     * nadie más atiende. Solo ids, segundos e intentos: ni la carga del
     * trabajo ni sus datos.
     *
     * @return list<array{id: int, reserved_seconds: int, attempts: int}>
     */
    private function orphanedReservations(string $queue, int $retryAfter, mixed $heartbeat): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $ahora = now()->timestamp;
        $filas = DB::table('jobs')
            ->where('queue', $queue)
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $ahora - $retryAfter - self::ORPHAN_MARGIN_SECONDS)
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->limit(self::ORPHANS_MAX)
            ->get(['id', 'reserved_at', 'attempts']);

        $huerfanos = [];
        foreach ($filas as $fila) {
            if ($heartbeat !== null && (int) $heartbeat > (int) $fila->reserved_at) {
                continue; // alguien terminó un trabajo de este carril después: hay un worker vivo
            }
            $huerfanos[] = [
                'id' => (int) $fila->id,
                'reserved_seconds' => max(0, $ahora - (int) $fila->reserved_at),
                'attempts' => (int) $fila->attempts,
            ];
        }

        return $huerfanos;
    }

    /**
     * Cuánto se tolera sin latido en ESTE carril: su `unattended_after_seconds`
     * si lo declara (un entero positivo; el del laboratorio, 300 s), y si no el
     * global de `observability.queues.unattended_after_seconds` (120 s).
     *
     * @param  array<string, mixed>  $lane
     */
    private function unattendedAfter(array $lane): int
    {
        $propio = $lane['unattended_after_seconds'] ?? null;

        return is_int($propio) && $propio > 0
            ? $propio
            : (int) config('observability.queues.unattended_after_seconds', 120);
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
    private function looksUnattended(int $backlog, int $oldestSeconds, ?int $lastSeenSeconds, int $graceSeconds): bool
    {
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
