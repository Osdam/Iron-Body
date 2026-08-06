<?php

namespace App\Console\Commands;

use App\Models\MarketingMessageAttachment;
use App\Services\Meta\MetaWebhookIngestService;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Retención: borra los binarios que ya cumplieron su plazo y los eventos crudos
 * viejos ya procesados.
 *
 * Las fotos, notas de voz y documentos que manda un prospecto no tienen por qué
 * vivir en el servidor para siempre. Al vencer, se borra el ARCHIVO y la ficha
 * queda como `expired`: el inbox sigue pudiendo decir "aquí llegó una nota de
 * voz el 3 de marzo" sin conservar el contenido.
 *
 * Un archivo compartido por deduplicación (varias fichas, un solo binario) solo
 * se borra cuando ya no queda ninguna ficha viva apuntando a él.
 */
class PruneWhatsappMedia extends Command
{
    protected $signature = 'marketing:prune-media
        {--dry-run : Muestra qué se borraría sin borrar nada}
        {--limit=500 : Máximo de adjuntos por corrida}';

    protected $description = 'Aplica la retención de adjuntos de WhatsApp y de eventos crudos de Meta.';

    public function handle(MetaWebhookIngestService $ingest): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $expired = MarketingMessageAttachment::query()
            ->where('status', MarketingMessageAttachment::STATUS_STORED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $deleted = 0;
        $kept = 0;

        foreach ($expired as $attachment) {
            // ¿Otra ficha viva comparte este binario? La deduplicación hace que
            // un archivo pueda pertenecer a varias conversaciones; borrarlo
            // dejaría rotas las demás.
            $stillInUse = MarketingMessageAttachment::query()
                ->where('path', $attachment->path)
                ->where('disk', $attachment->disk)
                ->where('id', '!=', $attachment->id)
                ->where('status', MarketingMessageAttachment::STATUS_STORED)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();

            if ($dryRun) {
                $this->line(sprintf(
                    '  %s #%d %s%s',
                    $stillInUse ? 'CONSERVA' : 'BORRA   ',
                    $attachment->id,
                    $attachment->kind,
                    $stillInUse ? ' (compartido)' : '',
                ));

                continue;
            }

            if (! $stillInUse) {
                try {
                    Storage::disk((string) $attachment->disk)->delete((string) $attachment->path);
                } catch (Throwable $e) {
                    // Que no se pueda borrar un archivo no debe detener el barrido.
                    ChannelLog::warning('media.prune.delete_failed', [
                        'attachment_id' => $attachment->id,
                        'error_class' => class_basename($e),
                    ]);

                    continue;
                }
                $deleted++;
            } else {
                $kept++;
            }

            // La ficha sobrevive al binario: el historial del inbox no se agujerea.
            $attachment->forceFill([
                'status' => MarketingMessageAttachment::STATUS_EXPIRED,
                'path' => null,
                'thumbnail_path' => null,
            ])->save();
        }

        $purgedEvents = $dryRun ? 0 : $ingest->purgeProcessedOlderThan(
            (int) config('observability.raw_events.retention_days', 30),
        );

        if (! $dryRun) {
            ChannelLog::info('media.prune.completed', [
                'attachments_expired' => $expired->count(),
                'binaries_deleted' => $deleted,
                'binaries_shared_kept' => $kept,
                'raw_events_purged' => $purgedEvents,
            ]);
        }

        $this->info(sprintf(
            '%sadjuntos vencidos: %d · binarios borrados: %d · compartidos conservados: %d · eventos crudos purgados: %d',
            $dryRun ? '[simulación] ' : '',
            $expired->count(), $deleted, $kept, $purgedEvents,
        ));

        return self::SUCCESS;
    }
}
