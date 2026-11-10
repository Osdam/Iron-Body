<?php

namespace App\Jobs\Outreach;

use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EJECUTAR un trabajo ya reclamado (contrato H9, D9-8 etapas 6 y 7): un job
 * de cola por trabajo, en el carril `commercial` (P4, `config/queue.php`), UN
 * SOLO INTENTO y sin reintentos de la cola: si el proceso muere a mitad, el
 * trabajo queda `claimed` o `dispatching` y la conciliación lo pasa a
 * `ambiguous` pasados 10 min (nunca se reenvía solo). Todo lo que decide está
 * en {@see OutreachDispatcher::process()}, que vuelve a mirarlo todo antes de
 * ejecutar; este job solo lo llama con el reloj de ahora.
 */
class DispatchOutreachJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $outreachJobId)
    {
        $lane = (array) config('queue.lanes.'.OutreachConfig::queueLane());
        $this->onQueue(is_string($lane['queue'] ?? null) ? $lane['queue'] : OutreachConfig::queueLane());
    }

    public function handle(OutreachDispatcher $dispatcher): void
    {
        $dispatcher->process($this->outreachJobId, now());
    }

    public function failed(Throwable $e): void
    {
        // Sin reintento: el trabajo queda reclamado y la conciliación lo cierra como ambiguo (D9-8 «Durabilidad»). Solo la clase.
        Log::error('outreach.dispatch.job_failed', ['outreach_job_id' => $this->outreachJobId, 'error_class' => $e::class]);
    }
}
