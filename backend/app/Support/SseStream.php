<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events (SSE) sobre HTTP plano: tiempo real sin servidor
 * WebSocket aparte ni broker, compatible con el túnel ngrok.
 *
 * La conexión se mantiene acotada (`maxSeconds`) y el cliente reconecta solo:
 * así un worker no queda tomado indefinidamente. El polling de los clientes
 * sigue como fallback, de modo que esto es estrictamente aditivo.
 *
 * NOTA dev: `php artisan serve` usa 1 worker por defecto; para que el stream no
 * bloquee el resto de peticiones, arráncalo con varios workers:
 *   PHP_CLI_SERVER_WORKERS=8 php artisan serve
 * En producción (php-fpm/nginx) no aplica.
 */
class SseStream
{
    /**
     * @param  callable  $tick  Se invoca cada `intervalMs`; debe emitir eventos
     *                          con {@see SseStream::emit()} (hace echo).
     */
    public static function response(callable $tick, int $maxSeconds = 20, int $intervalMs = 2000): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($tick, $maxSeconds, $intervalMs): void {
            @set_time_limit($maxSeconds + 10);
            @ignore_user_abort(false);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            echo "retry: 3000\n";
            echo ": connected\n\n";
            self::flush();

            $deadline = microtime(true) + $maxSeconds;
            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    break;
                }
                $tick();
                echo ": ping\n\n"; // heartbeat (mantiene viva la conexión)
                self::flush();
                if (connection_aborted()) {
                    break;
                }
                usleep($intervalMs * 1000);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx: no bufferizar
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /** Emite un evento SSE (id opcional para que el cliente reanude). */
    public static function emit(string $event, array $data, int|string|null $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    public static function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
