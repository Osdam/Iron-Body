<?php

namespace App\Services\Moderation;

use App\Models\ContentReport;
use App\Models\ModerationAuditLog;
use App\Models\ReportContentSnapshot;
use App\Models\Story;
use App\Services\FirebaseStorageService;
use App\Support\Moderation\ReportStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Captura y acceso a la evidencia de un reporte.
 *
 * El problema: una Story vive 24 h y su autor puede borrarla en cualquier
 * momento. Si el moderador llega después, no puede quedarse sin nada.
 *
 * La solución: al crear el reporte se congela aquí la METADATA y la REFERENCIA
 * al objeto (ruta en el bucket). El binario NO se copia — duplicar contenido
 * potencialmente ilegal en otro almacén sería peor, no mejor. Lo que se hace
 * es impedir que se borre mientras haya un caso abierto o retención pendiente.
 *
 * Nunca se guarda una URL pública permanente. Para revisar, el CRM pide una
 * URL firmada de pocos minutos ({@see signedEvidenceUrl}).
 */
class EvidenceService
{
    public function __construct(
        private FirebaseStorageService $firebase,
        private ModerationAudit $audit,
    ) {}

    /**
     * Congela la evidencia de una Story para un reporte. Idempotente: si el
     * reporte ya tiene snapshot, se devuelve el existente.
     */
    public function capture(ContentReport $report, Story $story): ReportContentSnapshot
    {
        $existing = $report->snapshot()->first();
        if ($existing) {
            return $existing;
        }

        return ReportContentSnapshot::create([
            'report_id' => $report->id,
            'original_story_id' => $story->id,
            'author_type' => $story->author_type,
            'author_member_id' => $story->author_type === 'member' ? $story->author_id : null,
            'media_type' => $story->type,
            // Referencia interna al objeto. NO es una URL.
            'media_storage_path' => $story->file_path,
            'media_disk' => $story->disk,
            // Solo para stories legacy en el disco público de Laravel, donde la
            // ruta ya era pública por diseño previo. Para Firebase queda null:
            // la `download_url` tokenizada es permanente y no se archiva.
            'media_url_snapshot' => $story->disk === 'firebase' ? null : $story->file_path,
            'caption_snapshot' => $this->sanitizeCaption($story->caption),
            'published_at' => $story->created_at,
            'expires_at' => $story->expires_at,
            'checksum' => null,
            'metadata' => [
                'type' => $story->type,
                'disk' => $story->disk,
                'size_bytes' => $story->size_bytes,
                'duration_ms' => $story->duration_ms,
                'author_name' => $story->author_name,
            ],
            'captured_at' => now(),
            // Se recalcula al cerrar el caso; este es el suelo mínimo.
            'purge_after' => now()->addDays((int) config('ugc.evidence_retention_days', 90)),
        ]);
    }

    /**
     * URL firmada TEMPORAL para que un moderador autorizado vea la evidencia.
     *
     * Devuelve null cuando no hay nada que mostrar (binario purgado, objeto
     * inexistente o Storage no disponible). El CRM lo refleja como "evidencia
     * no disponible" en vez de romperse.
     */
    public function signedEvidenceUrl(ReportContentSnapshot $snapshot): ?string
    {
        if (! $snapshot->hasReviewableMedia()) {
            return null;
        }

        $minutes = (int) config('ugc.evidence_signed_url_minutes', 10);

        if ($snapshot->media_disk === 'firebase') {
            return $this->firebase->signedUrl((string) $snapshot->media_storage_path, $minutes);
        }

        // Contenido legacy en el disco de Laravel. `temporaryUrl` no está
        // disponible en el driver local, así que caemos a la URL del disco
        // (que ya era pública antes de este sistema) solo en ese caso.
        try {
            $disk = Storage::disk($snapshot->media_disk ?: 'public');
            if (! $disk->exists((string) $snapshot->media_storage_path)) {
                return null;
            }

            return $disk->url((string) $snapshot->media_storage_path);
        } catch (Throwable $e) {
            Log::warning('moderation.evidence_url_failed', [
                'snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ¿Se puede borrar físicamente el binario de esta Story?
     *
     * NO se puede si existe cualquier reporte abierto sobre ella, ni si algún
     * snapshot sigue dentro de su ventana de retención. Es la guarda que
     * convierte "no borres evidencia" en una comprobación real.
     */
    public function canPurgeMedia(int $storyId): bool
    {
        $hasOpenReport = ContentReport::query()
            ->forContent(ContentReport::CONTENT_TYPE_STORY, $storyId)
            ->whereIn('status', ReportStatus::open())
            ->exists();

        if ($hasOpenReport) {
            return false;
        }

        return ! ReportContentSnapshot::query()
            ->where('original_story_id', $storyId)
            ->whereNull('media_purged_at')
            ->where(function ($q) {
                $q->whereNull('purge_after')->orWhere('purge_after', '>', now());
            })
            ->exists();
    }

    /**
     * Fija la fecha a partir de la cual se puede purgar el binario de un caso
     * que acaba de cerrarse.
     */
    public function scheduleRetention(ContentReport $report): void
    {
        $snapshot = $report->snapshot()->first();
        if (! $snapshot) {
            return;
        }

        $snapshot->update([
            'purge_after' => now()->addDays((int) config('ugc.evidence_retention_days', 90)),
        ]);
    }

    /**
     * Purga el binario de un snapshot cuya retención venció. Idempotente: si ya
     * estaba purgado devuelve false sin tocar nada.
     */
    public function purgeMedia(ReportContentSnapshot $snapshot): bool
    {
        if ($snapshot->isMediaPurged()) {
            return false;
        }
        if (! $this->canPurgeMedia((int) $snapshot->original_story_id)) {
            return false;
        }

        $path = (string) $snapshot->media_storage_path;

        try {
            if ($path !== '') {
                if ($snapshot->media_disk === 'firebase') {
                    $this->firebase->deleteObject($path);
                } else {
                    Storage::disk($snapshot->media_disk ?: 'public')->delete($path);
                }
            }
        } catch (Throwable $e) {
            Log::warning('moderation.evidence_purge_failed', [
                'snapshot_id' => $snapshot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $snapshot->update([
            'media_purged_at' => now(),
            // La referencia deja de tener sentido una vez borrado el objeto.
            'media_storage_path' => null,
            'media_url_snapshot' => null,
        ]);

        $this->audit->system(
            ModerationAuditLog::ACTION_EVIDENCE_PURGED,
            'report_content_snapshot',
            (int) $snapshot->id,
            ['original_story_id' => (int) $snapshot->original_story_id],
        );

        return true;
    }

    /**
     * El caption es texto de usuario: se neutraliza antes de archivarlo porque
     * el CRM lo renderiza.
     */
    private function sanitizeCaption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }

        $clean = trim(strip_tags($caption));

        return $clean === '' ? null : mb_substr($clean, 0, 500);
    }
}
