<?php

namespace App\Jobs;

use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Observability\ChannelLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Throwable;

/**
 * Una pasada de la sincronización de Meta Ads, fuera de la petición.
 *
 * La programa el scheduler cada hora (si META_ADS_SYNC_ENABLED) y la puede
 * despachar el botón «Actualizar» del panel. Es de solo lectura contra Meta.
 *
 * MULTICUENTA, en abanico:
 *  - sin cuenta, despacha UN job por cuenta conectada y termina. Así cada
 *    cuenta reintenta sola, y un fallo o un límite de Meta en una no toca a las
 *    demás;
 *  - con cuenta, la pasada de esa cuenta y, si sale bien, su alcance.
 *
 * El trabajo de verdad, el cerrojo y el registro del intento viven en
 * {@see MetaAdsSync}; aquí solo se decide si un fallo merece otro intento:
 *
 *  - rate_limit y transient → se relanza y la cola reintenta con espera
 *    creciente (1, 5 y 15 minutos). Meta pidió frenar o no contestó; más tarde
 *    puede salir bien.
 *  - permission y other → no se reintenta. El mismo token contra la misma
 *    cuenta devuelve el mismo error, y repetirlo solo gasta cuota. El intento
 *    queda `failed` en `meta_sync_runs` y el panel lo enseña.
 *  - un fallo propio (la base, un bug) → el job se marca fallido sin reintentos,
 *    para que se vea en `failed_jobs` en vez de repetirse a ciegas.
 *
 * LIMITACIÓN CONOCIDA: el cupo de Meta es de la app y lo comparten todas las
 * cuentas, pero los jobs del abanico no se avisan entre sí. Si Meta frena a
 * una, los de las demás piden igual y, si también reciben el límite, cada uno
 * reintenta con su propia espera. No hay un cerrojo de cupo compartido entre
 * jobs; {@see MetaAdsSync::runAll()} y los comandos sí dejan de pedir en
 * cuanto Meta frena.
 */
class SyncMetaAdsInsights implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> segundos de espera antes del 2.º, 3.º y 4.º intento */
    public array $backoff = [60, 300, 900];

    /**
     * La cuenta conectada de esta pasada; null = el abanico de todas.
     *
     * Propiedad con valor por defecto, y no promovida en el constructor: un job
     * encolado antes de multicuenta no la trae, y al deserializarlo tiene que
     * quedar en null (el abanico), no sin inicializar.
     */
    public ?string $account = null;

    public function __construct(public readonly string $trigger = 'schedule', ?string $account = null)
    {
        $this->account = $account;

        // Carril comercial: analítica de fondo que no espera nadie con el
        // teléfono en la mano, y el que ya tiene worker en producción.
        $lane = (array) config('queue.lanes.commercial');
        $this->onQueue($lane['queue'] ?? 'commercial');
    }

    public function handle(MetaAdsSync $sync): void
    {
        if ($this->account === null) {
            $this->fanOut($sync);

            return;
        }

        try {
            $sync = $sync->forAccount($this->account);
        } catch (InvalidArgumentException) {
            // La cuenta dejó de estar conectada entre el despacho y la ejecución:
            // no hay nada que sincronizar ni que reintentar. Se dice y se termina.
            ChannelLog::warning('meta.ads.sync.account_not_connected', [
                'trigger' => $this->trigger,
                'account' => LeadSourceClassifier::maskId($this->account),
            ]);

            return;
        }

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
            'account' => LeadSourceClassifier::maskId($this->account),
            'exception' => $e !== null ? class_basename($e) : null,
            'error_code' => $e instanceof MetaAdsApiException ? $e->category : null,
        ]);
    }

    /**
     * Un job por cuenta conectada, en su orden, con el mismo disparador. Sin
     * ninguna cuenta no hay abanico: la pasada de siempre deja constancia del
     * motivo (`not_configured`) sin llamar a Meta.
     */
    private function fanOut(MetaAdsSync $sync): void
    {
        $accounts = $sync->accountIds();

        if ($accounts === []) {
            $sync->run($this->trigger);

            return;
        }

        foreach ($accounts as $account) {
            self::dispatch($this->trigger, $account);
        }
    }
}
