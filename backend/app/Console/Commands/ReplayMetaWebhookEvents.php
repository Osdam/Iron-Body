<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\MetaWebhookEvent;
use App\Services\Meta\MetaWebhookIngestService;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;

/**
 * Rescate de eventos de Meta que se quedaron sin procesar.
 *
 * Esto existe porque el worker se cae, la base se satura o un despliegue pilla
 * un job a mitad. Antes, ese mensaje se perdía sin que nadie se enterara; ahora
 * el cuerpo original está guardado y este comando lo vuelve a poner en la cola.
 *
 * Es seguro ejecutarlo de más: reprocesar un evento no duplica nada, porque la
 * idempotencia por `meta_message_id` sigue vigente aguas abajo.
 */
class ReplayMetaWebhookEvents extends Command
{
    protected $signature = 'marketing:replay-webhooks
        {--id= : Reprocesa un evento concreto por id, sin importar su antigüedad}
        {--minutes= : Antigüedad mínima para considerarlo atascado}
        {--limit=100 : Máximo de eventos por corrida}
        {--include-dead : Incluye también los que agotaron sus reintentos}
        {--dry-run : Lista lo que se reprocesaría sin encolar nada}';

    protected $description = 'Reprocesa eventos de Meta que quedaron pendientes, atascados o fallidos.';

    public function handle(MetaWebhookIngestService $ingest): int
    {
        if ($id = $this->option('id')) {
            return $this->replayOne((int) $id);
        }

        $minutes = (int) ($this->option('minutes') ?? config('observability.raw_events.stuck_after_minutes', 10));
        $limit = max(1, (int) $this->option('limit'));

        $events = $ingest->stuck($minutes, $limit);

        if ($this->option('include-dead')) {
            $dead = MetaWebhookEvent::where('status', MetaWebhookEvent::STATUS_DEAD)
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $events = $events->concat($dead)->unique('id');
        }

        if ($events->isEmpty()) {
            $this->info('No hay eventos atascados. El canal está al día.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'estado', 'intentos', 'recibido', 'último error'],
            $events->map(fn (MetaWebhookEvent $e) => [
                $e->id,
                $e->status,
                $e->attempts,
                $e->created_at?->diffForHumans(),
                $e->last_error_class ?: '—',
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Simulación: no se encoló nada.');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            ProcessMetaWebhookEvent::dispatch($event->id);
        }

        ChannelLog::info('meta.event.replayed', [
            'count' => $events->count(),
            'stuck_after_minutes' => $minutes,
        ]);

        $this->info(sprintf('%d evento(s) reencolado(s).', $events->count()));

        return self::SUCCESS;
    }

    private function replayOne(int $id): int
    {
        $event = MetaWebhookEvent::find($id);

        if ($event === null) {
            $this->error("No existe el evento #{$id}.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("Se reprocesaría el evento #{$id} (estado: {$event->status}).");

            return self::SUCCESS;
        }

        // Un evento ya procesado se puede reprocesar a mano: la idempotencia
        // impide que se duplique nada, y a veces es justo lo que se necesita
        // para depurar. Se vuelve a poner en 'pending' para que el job entre.
        if ($event->status === MetaWebhookEvent::STATUS_PROCESSED) {
            $event->forceFill(['status' => MetaWebhookEvent::STATUS_PENDING])->save();
        }

        ProcessMetaWebhookEvent::dispatch($event->id);

        ChannelLog::info('meta.event.replayed', ['count' => 1, 'event_id' => $event->id]);
        $this->info("Evento #{$id} reencolado.");

        return self::SUCCESS;
    }
}
