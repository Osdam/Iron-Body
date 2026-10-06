<?php

namespace App\Jobs;

use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Una pasada de la sincronización de Meta Ads, fuera de la petición.
 *
 * La programa el scheduler cada hora (si META_ADS_SYNC_ENABLED) y la puede
 * despachar el botón «Actualizar» del panel. El trabajo de verdad, el cerrojo y
 * el registro del intento viven en {@see MetaAdsSync}; aquí solo se decide si
 * un fallo merece otro intento:
 *
 *  - rate_limit y transient → se relanza y la cola reintenta con espera
 *    creciente (1, 5 y 15 minutos). Meta pidió frenar o no contestó; más tarde
 *    puede salir bien.
 *  - permission y other → no se reintenta. El mismo token contra la misma
 *    cuenta devuelve el mismo error, y repetirlo solo gasta cuota. El intento
 *    queda `failed` en `meta_sync_runs` y el panel lo enseña.
 *  - un fallo propio (la base, un bug) → el job se marca fallido sin reintentos,
 *    para que se vea en `failed_jobs` en vez de repetirse a ciegas.
 */
class SyncMetaAdsInsights implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> segundos de espera antes del 2.º, 3.º y 4.º intento */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $trigger = 'schedule')
    {
        // Carril comercial: analítica de fondo que no espera nadie con el
        // teléfono en la mano, y el que ya tiene worker en producción.
        $lane = (array) config('queue.lanes.commercial');
        $this->onQueue($lane['queue'] ?? 'commercial');
    }

    public function handle(MetaAdsSync $sync): void
    {
        try {
            $result = $sync->run($this->trigger);
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        // Con el gasto al día, el alcance de los rangos del panel. Accesorio: no
        // lanza, y su fallo queda en el log del canal sin tocar la pasada.
        if (($result['status'] ?? null) === 'ok') {
            $sync->refreshReach();
        }

        if (($result['status'] ?? null) === 'failed' && ($result['retryable'] ?? false) === true) {
            $code = (string) ($result['error_code'] ?? MetaAdsApiException::TRANSIENT);

            // El mensaje lo escribimos aquí, sin texto de Meta: acaba en
            // `failed_jobs` si se agotan los intentos.
            throw new MetaAdsApiException(
                $code,
                "Meta Ads no respondió bien ({$code}) en la pasada #".($result['run_id'] ?? '?').'; se reintentará.',
            );
        }
    }

    public function failed(?Throwable $e): void
    {
        ChannelLog::error('meta.ads.sync.gave_up', [
            'trigger' => $this->trigger,
            'exception' => $e !== null ? class_basename($e) : null,
            'error_code' => $e instanceof MetaAdsApiException ? $e->category : null,
        ]);
    }
}
