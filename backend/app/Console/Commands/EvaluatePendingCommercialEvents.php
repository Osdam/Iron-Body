<?php

namespace App\Console\Commands;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;

/**
 * La red que recoge los eventos que nadie llegó a evaluar.
 *
 * Un hecho se registra dentro de la transacción que lo produce y su evaluación
 * se encola después del commit. Entre esas dos cosas caben varios accidentes:
 * Redis caído al despachar, un worker que muere a mitad, o —el caso más
 * probable y menos dramático— que el motor estuviera apagado cuando el hecho
 * ocurrió y se encienda después.
 *
 * `evaluated_at IS NULL` es, literalmente, la cola de trabajo pendiente. Como
 * el registro del hecho y su evaluación están separados, esa columna sobrevive
 * a todo lo anterior: nada se pierde, solo se retrasa.
 */
class EvaluatePendingCommercialEvents extends Command
{
    protected $signature = 'commercial:evaluate-pending
                            {--limit= : Máximo de eventos por corrida}
                            {--max-age-days=7 : Ignora hechos más viejos que esto}';

    protected $description = 'Encola la evaluación de los hechos comerciales sin evaluar';

    public function handle(): int
    {
        if (! (bool) config('commercial.enabled')) {
            $this->warn('commercial.enabled está en false: no se evalúa nada.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('commercial.evaluation_batch', 100));
        $maxAge = max(1, (int) $this->option('max-age-days'));

        $events = CommercialEvent::query()
            ->whereNull('evaluated_at')
            // Un hecho de hace un mes ya no describe el mundo actual. Actuar
            // sobre él sería contestar a algo que la persona ya olvidó.
            ->where('occurred_at', '>=', now()->subDays($maxAge))
            ->orderBy('occurred_at')
            ->limit(max(1, $limit))
            ->get(['id']);

        foreach ($events as $event) {
            EvaluateCommercialSubject::dispatch($event->id);
        }

        // Los que quedaron fuera por viejos se marcan como vistos: si no, la
        // consulta los arrastra en cada corrida para siempre.
        $stale = CommercialEvent::query()
            ->whereNull('evaluated_at')
            ->where('occurred_at', '<', now()->subDays($maxAge))
            ->update(['evaluated_at' => now()]);

        $this->info("Encolados: {$events->count()}. Descartados por antigüedad: {$stale}.");

        ChannelLog::info('commercial.evaluate_pending.completed', [
            'queued' => $events->count(),
            'discarded_stale' => $stale,
        ]);

        return self::SUCCESS;
    }
}
