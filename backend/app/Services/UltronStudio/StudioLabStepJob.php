<?php

namespace App\Services\UltronStudio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Un paso de una ejecución del laboratorio ({@see StudioLabRunner::step()}):
 * como mucho un caso, y el paso siguiente lo encola el propio runner.
 *
 * Vive aquí y no en `app/Jobs` (hallazgo 4c del plan del Hito 4): así el
 * laboratorio entero cabe en el directorio del Studio y la frontera con el
 * motor se vigila por directorios.
 *
 * Su CARRIL es `studio-lab` (`config('queue.lanes.lab')`), fijado en el
 * constructor como los demás jobs, con su worker propio `ironbody-lab`
 * (`docs/ops/supervisor/ironbody-lab.conf`): una llamada lenta al proveedor
 * del laboratorio no frena ningún carril del motor.
 *
 *  - `$tries = 1`: un reintento de la cola podría pagar dos veces la misma
 *    llamada. Un paso que falla lo liquida {@see failed()}.
 *  - `$timeout = 150` y `$failOnTimeout = true`: dos llamadas de 60 s caben.
 *    `retry_after` del carril (300) es mayor que el timeout: un paso lento no
 *    lo toma otro worker mientras sigue vivo.
 *
 * `failed()` (timeout del job o worker perdido): el caso en curso pasa a
 * `error` con coste = su reserva, porque no se sabe si se cobró, y se encola el
 * siguiente paso. NADA APRUEBA SOLO: este job no nombra el veredicto.
 */
final class StudioLabStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $lane = (array) config('queue.lanes.lab');
        $this->onQueue($lane['queue'] ?? 'studio-lab');
    }

    public function handle(StudioLabRunner $runner): void
    {
        $runner->step($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(StudioLabRunner::class)->failed($this->runId, $exception);
    }
}
