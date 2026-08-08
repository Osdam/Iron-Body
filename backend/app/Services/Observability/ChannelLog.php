<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * El único punto por el que el canal de WhatsApp escribe en el log.
 *
 * Dos razones para no usar `Log::info` directo:
 *
 *  1. Nada sale sin pasar por LogRedactor (teléfonos enmascarados, secretos
 *     fuera), y eso no puede depender de la memoria de quien escribe la línea.
 *  2. IRON GUARD necesita leer estas líneas como datos, no como prosa. Cada
 *     evento lleva siempre el mismo esqueleto —event, correlation_id, service,
 *     release, host— para poder agrupar incidentes sin adivinar.
 *
 * El nombre del evento es un identificador estable con puntos
 * (`meta.webhook.received`), no una frase: se usa para agrupar.
 */
class ChannelLog
{
    /** Canal dedicado; si no existe, cae al canal por defecto sin romper nada. */
    private const CHANNEL = 'channel';

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /**
     * Registra la duración de una operación. Devuelve lo que devuelva el
     * callback; si lanza, registra el fallo con su duración y re-lanza (medir
     * no debe cambiar el comportamiento).
     */
    public static function timed(string $event, array $context, callable $work): mixed
    {
        $startedAt = microtime(true);

        try {
            $result = $work();
            self::info($event, $context + [
                'status' => 'ok',
                'duration_ms' => self::elapsed($startedAt),
            ]);

            return $result;
        } catch (\Throwable $e) {
            self::error($event, $context + [
                'status' => 'error',
                'duration_ms' => self::elapsed($startedAt),
                'error_class' => class_basename($e),
                'error_message' => LogRedactor::preview($e->getMessage(), 300),
            ]);

            throw $e;
        }
    }

    private static function write(string $level, string $event, array $context): void
    {
        $payload = array_merge(self::envelope($event), LogRedactor::scrub($context));

        // El canal dedicado escribe JSON por línea. Si no está configurado —o
        // si el facade está sustituido por un doble de test, que devuelve null
        // en channel()— se usa la raíz del facade: instrumentar nunca debe
        // poder tumbar el webhook.
        $logger = self::hasChannel() ? Log::channel(self::CHANNEL) : null;
        $logger ??= Log::getFacadeRoot();

        try {
            $logger->{$level}($event, $payload);
        } catch (\Throwable $e) {
            // Escribir el log puede fallar por causas que no tienen nada que ver
            // con la petición: en producción el cron del scheduler corría como
            // root y dejaba `channel-<fecha>.log` en propiedad de root, con lo
            // que php-fpm (www-data) no podía abrirlo en modo append. Monolog
            // lanzaba, la excepción subía por el controlador y el webhook de
            // Meta contestaba 500 —incluido el camino que RECHAZA una firma
            // inválida, que ya había hecho su trabajo correctamente—.
            //
            // Con Meta enviando de verdad, un 500 sostenido no es un log feo:
            // Meta reintenta y acaba dando de baja la suscripción del webhook.
            // Perder la línea de log es un daño menor que perder el canal.
            self::fallback($level, $event, $payload, $e);
        }
    }

    /**
     * Último intento por el canal por defecto, y silencio si tampoco puede.
     *
     * No se re-lanza nunca: el contrato de esta clase es que observar no cambia
     * el comportamiento de lo observado.
     */
    private static function fallback(string $level, string $event, array $payload, \Throwable $original): void
    {
        try {
            Log::getFacadeRoot()->{$level}($event, $payload + [
                'channel_log_degraded' => class_basename($original),
            ]);
        } catch (\Throwable) {
            // Sin sitio donde escribir. Se pierde la línea, no la petición.
        }
    }

    /** Esqueleto común a toda línea del canal. */
    private static function envelope(string $event): array
    {
        return array_filter([
            'event' => $event,
            'service' => 'iron-channel',
            'environment' => (string) config('app.env'),
            'release' => self::release(),
            'host' => gethostname() ?: null,
            // Context se propaga solo del request al job encolado (Laravel 11+),
            // así que el correlation_id sobrevive al salto a la cola.
            'correlation_id' => Context::get('correlation_id'),
            'conversation_id' => Context::get('conversation_id'),
            'agent_run_id' => Context::get('agent_run_id'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Commit desplegado. Se cachea en memoria del proceso: leer el HEAD en cada
     * línea de log sería un syscall por mensaje.
     */
    private static function release(): ?string
    {
        static $release = false;

        if ($release !== false) {
            return $release;
        }

        $configured = config('observability.release');
        if (! empty($configured)) {
            return $release = (string) $configured;
        }

        $head = base_path('.git/HEAD');
        if (! is_readable($head)) {
            return $release = null;
        }

        $contents = trim((string) @file_get_contents($head));
        if (str_starts_with($contents, 'ref: ')) {
            $refPath = base_path('.git/'.trim(substr($contents, 5)));
            $contents = is_readable($refPath) ? trim((string) @file_get_contents($refPath)) : '';
        }

        return $release = $contents !== '' ? substr($contents, 0, 12) : null;
    }

    private static function hasChannel(): bool
    {
        return is_array(config('logging.channels.'.self::CHANNEL));
    }

    private static function elapsed(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
