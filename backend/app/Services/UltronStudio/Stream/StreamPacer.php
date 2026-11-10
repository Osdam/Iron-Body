<?php

namespace App\Services\UltronStudio\Stream;

use App\Support\SseStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El COMPÁS de una conexión del canal: sondea cada `pollMs`, late (`: ping`)
 * cada `heartbeatSeconds` y cierra a los `maxSeconds` (contrato: 15 s y 55 s).
 *
 * Reutiliza el formato de {@see SseStream} (cabeceras, `emit`, `flush`); no
 * usa su bucle porque aquel acopla el latido al sondeo (un ping por tick), y
 * aquí el sondeo es de 1,5 s para que un evento llegue en menos de 2 s
 * mientras el latido se queda en 15 s. El reloj es inyectable
 * ({@see StreamClock}) para probar el bucle entero sin esperar.
 */
final class StreamPacer
{
    public function __construct(
        private readonly StreamClock $clock,
        private readonly int $maxSeconds,
        private readonly int $pollMs,
        private readonly int $heartbeatSeconds,
        private readonly int $retryMs,
    ) {}

    /** Con los valores de `config/ultron_studio_stream.php`. */
    public static function fromConfig(StreamClock $clock): self
    {
        return new self(
            $clock,
            max(1, (int) config('ultron_studio_stream.max_seconds', 55)),
            max(50, (int) config('ultron_studio_stream.poll_ms', 1500)),
            max(1, (int) config('ultron_studio_stream.heartbeat_seconds', 15)),
            max(500, (int) config('ultron_studio_stream.retry_ms', 3000)),
        );
    }

    /**
     * @param  callable(): void  $onOpen  Se llama una vez, tras el preludio: el evento `ready`.
     * @param  callable(): void  $tick  Se llama en cada sondeo; emite con {@see SseStream::emit()}.
     */
    public function response(callable $onOpen, callable $tick): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($onOpen, $tick): void {
            $this->clock->prepare($this->maxSeconds);

            $inicio = $this->clock->now();
            $cierre = $inicio + $this->maxSeconds;
            $latido = $inicio + $this->heartbeatSeconds;

            echo "retry: {$this->retryMs}\n";
            echo ": connected\n\n";
            $onOpen();
            SseStream::flush();

            while (true) {
                if ($this->clock->aborted()) {
                    break;
                }
                $tick();
                $ahora = $this->clock->now();
                if ($ahora >= $latido) {
                    echo ": ping\n\n";
                    while ($latido <= $ahora) {
                        $latido += $this->heartbeatSeconds;
                    }
                }
                SseStream::flush();
                if ($ahora >= $cierre || $this->clock->aborted()) {
                    break;
                }
                $this->clock->sleep((int) min($this->pollMs, ceil(($cierre - $ahora) * 1000)));
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
