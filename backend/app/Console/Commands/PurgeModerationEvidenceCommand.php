<?php

namespace App\Console\Commands;

use App\Models\ReportContentSnapshot;
use App\Models\Story;
use App\Services\Moderation\EvidenceService;
use Illuminate\Console\Command;

/**
 * Limpieza de evidencia cuya retención venció.
 *
 * IDEMPOTENTE por diseño: recorre snapshots con `purge_after` en el pasado y
 * `media_purged_at` nulo. Volver a ejecutarlo no borra dos veces ni falla si el
 * objeto ya no existe. {@see EvidenceService::canPurgeMedia()} vuelve a
 * comprobar que no haya un caso abierto justo antes de tocar nada, así que una
 * carrera con un reporte nuevo no destruye evidencia viva.
 *
 * También retira las filas soft-deleted de `stories` cuyo binario ya se purgó y
 * que no tienen ningún reporte pendiente — cierra el ciclo de vida sin dejar
 * huérfanos.
 *
 * Uso:  php artisan moderation:purge-evidence [--dry-run] [--limit=500]
 */
class PurgeModerationEvidenceCommand extends Command
{
    protected $signature = 'moderation:purge-evidence
        {--dry-run : Muestra qué se borraría sin borrar nada}
        {--limit=500 : Máximo de snapshots a procesar en esta pasada}';

    protected $description = 'Purga los binarios de evidencia cuya retención ya venció (idempotente).';

    public function handle(EvidenceService $evidence): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = ReportContentSnapshot::query()
            ->whereNull('media_purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No hay evidencia pendiente de purgar.');

            return self::SUCCESS;
        }

        $purged = 0;
        $skipped = 0;

        foreach ($candidates as $snapshot) {
            if (! $evidence->canPurgeMedia((int) $snapshot->original_story_id)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] snapshot #{$snapshot->id} (story #{$snapshot->original_story_id})");
                $purged++;

                continue;
            }

            if ($evidence->purgeMedia($snapshot)) {
                $purged++;
            } else {
                $skipped++;
            }
        }

        // Cierre del ciclo: stories borradas por su autor cuyo binario ya no
        // existe y que no conservan ninguna evidencia pendiente.
        $removedRows = 0;
        if (! $dryRun) {
            $orphans = Story::onlyTrashed()
                ->whereDoesntHave('views')
                ->limit($limit)
                ->get();

            foreach ($orphans as $story) {
                if ($evidence->canPurgeMedia((int) $story->id)) {
                    $story->forceDelete();
                    $removedRows++;
                }
            }
        }

        $this->info("Evidencia purgada: {$purged} · omitida (caso abierto/retención): {$skipped}"
            .($removedRows ? " · filas de stories eliminadas: {$removedRows}" : ''));

        return self::SUCCESS;
    }
}
