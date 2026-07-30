<?php

namespace App\Services\Moderation;

use App\Models\ContentReport;
use App\Models\Member;
use App\Models\ModerationAuditLog;
use App\Models\Story;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creación de reportes de contenido.
 *
 * Nada de lo que llega del cliente es autoridad:
 *  - El reportante sale del bearer, jamás de un `reporter_id` del body.
 *  - El autor reportado sale de la Story REAL en base de datos, jamás de un
 *    `author_id` del body.
 *  - La severidad y la prioridad se derivan del catálogo de motivos.
 *  - El estado inicial es siempre `submitted`.
 *
 * Anti-abuso incorporado: dedup por (reportante, contenido, caso abierto),
 * límite por hora y conteo de reportantes ÚNICOS (varios reportes de la misma
 * persona cuentan como uno).
 */
class ReportService
{
    public function __construct(
        private EvidenceService $evidence,
        private ModerationAudit $audit,
        private ModerationNotifier $notifier,
    ) {}

    /**
     * Registra un reporte sobre una Story.
     *
     * @return array{report: ContentReport, created: bool}
     *
     * @throws RuntimeException con códigos estables: `reports_disabled`,
     *                          `content_not_found`, `cannot_report_own_content`,
     *                          `invalid_reason`, `rate_limited`.
     */
    public function reportStory(
        Member $reporter,
        int $storyId,
        string $reasonCode,
        ?string $detail = null,
        ?Request $request = null,
    ): array {
        if (! config('ugc.reports_enabled', true)) {
            throw new RuntimeException('reports_disabled');
        }

        if (! ReportReason::isValid($reasonCode)) {
            throw new RuntimeException('invalid_reason');
        }

        // La Story se resuelve del servidor, incluyendo las ya eliminadas: se
        // puede reportar contenido que el autor borró segundos antes.
        $story = Story::withTrashed()->find($storyId);
        if (! $story) {
            throw new RuntimeException('content_not_found');
        }

        if ($story->isAuthoredByMember((int) $reporter->id)) {
            throw new RuntimeException('cannot_report_own_content');
        }

        $this->assertWithinRateLimit((int) $reporter->id);

        $created = false;

        /** @var ContentReport $report */
        $report = DB::transaction(function () use (
            $reporter,
            $story,
            $reasonCode,
            $detail,
            &$created
        ): ContentReport {
            // Dedup idempotente: si ya hay un caso ABIERTO de este reportante
            // sobre este contenido, se reutiliza. Reportar dos veces no crea
            // dos casos ni sube el conteo de reportantes únicos.
            $existing = ContentReport::query()
                ->where('reporter_member_id', $reporter->id)
                ->forContent(ContentReport::CONTENT_TYPE_STORY, (int) $story->id)
                ->whereIn('status', ReportStatus::open())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = true;

            $report = ContentReport::create([
                'reporter_member_id' => $reporter->id,
                'reported_member_id' => $story->author_type === 'member'
                    ? $story->author_id
                    : null,
                'reported_author_type' => $story->author_type,
                'reported_author_id' => $story->author_id,
                'content_type' => ContentReport::CONTENT_TYPE_STORY,
                'content_id' => $story->id,
                'reason_code' => $reasonCode,
                'reason_detail' => $this->sanitizeDetail($detail),
                'status' => ReportStatus::SUBMITTED,
                'severity' => ReportReason::severityFor($reasonCode),
                'priority' => ReportReason::priorityFor($reasonCode),
                'submitted_at' => now(),
            ]);

            // Evidencia congelada en la MISMA transacción: si algo falla, no
            // queda un caso sin nada que revisar.
            $this->evidence->capture($report, $story);

            // Conteo de reportantes ÚNICOS sobre el contenido — se recalcula,
            // no se incrementa a ciegas.
            $this->refreshUniqueReporters($story);

            return $report;
        });

        if (! $created) {
            return ['report' => $report, 'created' => false];
        }

        $this->audit->member(
            (int) $reporter->id,
            ModerationAuditLog::ACTION_REPORT_SUBMITTED,
            'content_report',
            (int) $report->id,
            [
                'public_id' => $report->public_id,
                'reason_code' => $report->reason_code,
                'severity' => $report->severity,
                'content_type' => $report->content_type,
                'content_id' => $report->content_id,
            ],
            $request,
        );

        // Regla defensiva (nunca sanciona a nadie): cuarentena temporal si
        // suficientes personas distintas reportaron el mismo contenido.
        $this->maybeAutoQuarantine($story->fresh());

        $this->notifier->reportReceived($reporter, $report);
        $this->notifier->notifyModerators($report);

        return ['report' => $report, 'created' => true];
    }

    /**
     * Registra un reporte sobre una PERSONA (no sobre una publicación).
     *
     * Necesario por dos razones. La de cumplimiento: Google Play exige poder
     * denunciar usuarios además de contenido. Y la funcional: a alguien sin una
     * Story activa no había forma de reportarlo, porque el único acceso era el
     * visor de estados.
     *
     * No captura snapshot de medios —no hay publicación concreta— pero sí deja
     * constancia de a quién se reporta y por qué. Si la conducta se refiere a
     * una publicación, el reporte de Story sigue siendo el camino correcto y
     * conserva la evidencia.
     *
     * @return array{report: ContentReport, created: bool}
     *
     * @throws RuntimeException `reports_disabled`, `member_not_found`,
     *                          `cannot_report_own_content`, `invalid_reason`,
     *                          `rate_limited`.
     */
    public function reportMember(
        Member $reporter,
        int $reportedMemberId,
        string $reasonCode,
        ?string $detail = null,
        ?Request $request = null,
    ): array {
        if (! config('ugc.reports_enabled', true)) {
            throw new RuntimeException('reports_disabled');
        }

        if (! ReportReason::isValid($reasonCode)) {
            throw new RuntimeException('invalid_reason');
        }

        if ((int) $reporter->id === $reportedMemberId) {
            throw new RuntimeException('cannot_report_own_content');
        }

        // El objetivo se resuelve contra la base de datos: reportar a un id
        // inventado no puede crear un caso fantasma en la bandeja.
        $reported = Member::query()->whereKey($reportedMemberId)->first();
        if (! $reported) {
            throw new RuntimeException('member_not_found');
        }

        $this->assertWithinRateLimit((int) $reporter->id);

        $created = false;

        /** @var ContentReport $report */
        $report = DB::transaction(function () use (
            $reporter,
            $reported,
            $reasonCode,
            $detail,
            &$created
        ): ContentReport {
            // Mismo criterio de dedup que en las stories: un caso abierto por
            // reportante y objetivo. Insistir no multiplica los casos.
            $existing = ContentReport::query()
                ->where('reporter_member_id', $reporter->id)
                ->forContent(ContentReport::CONTENT_TYPE_MEMBER, (int) $reported->id)
                ->whereIn('status', ReportStatus::open())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = true;

            return ContentReport::create([
                'reporter_member_id' => $reporter->id,
                'reported_member_id' => $reported->id,
                'reported_author_type' => 'member',
                'reported_author_id' => $reported->id,
                'content_type' => ContentReport::CONTENT_TYPE_MEMBER,
                // Para un reporte de perfil el "contenido" es la propia cuenta.
                'content_id' => $reported->id,
                'reason_code' => $reasonCode,
                'reason_detail' => $this->sanitizeDetail($detail),
                'status' => ReportStatus::SUBMITTED,
                'severity' => ReportReason::severityFor($reasonCode),
                'priority' => ReportReason::priorityFor($reasonCode),
                'submitted_at' => now(),
            ]);
        });

        if (! $created) {
            return ['report' => $report, 'created' => false];
        }

        $this->audit->member(
            (int) $reporter->id,
            ModerationAuditLog::ACTION_REPORT_SUBMITTED,
            'content_report',
            (int) $report->id,
            [
                'public_id' => $report->public_id,
                'reason_code' => $report->reason_code,
                'severity' => $report->severity,
                'content_type' => $report->content_type,
                'content_id' => $report->content_id,
            ],
            $request,
        );

        $this->notifier->reportReceived($reporter, $report);
        $this->notifier->notifyModerators($report);

        return ['report' => $report, 'created' => true];
    }

    /**
     * Límite por hora del reportante. Se cuenta sobre la tabla, no sobre caché:
     * un reinicio de Redis no debe abrir la puerta a una campaña de reportes.
     */
    private function assertWithinRateLimit(int $reporterId): void
    {
        $limit = (int) config('ugc.report_rate_limit_per_hour', 10);
        if ($limit <= 0) {
            return;
        }

        $recent = ContentReport::query()
            ->where('reporter_member_id', $reporterId)
            ->where('submitted_at', '>=', now()->subHour())
            ->count();

        if ($recent >= $limit) {
            throw new RuntimeException('rate_limited');
        }
    }

    /**
     * Recalcula cuántas PERSONAS DISTINTAS reportaron una Story.
     *
     * Distinto de "número de reportes": si alguien abre y cierra diez casos,
     * sigue contando como una persona. Es la métrica que alimenta la cuarentena
     * automática y la que ve el moderador.
     */
    public function refreshUniqueReporters(Story $story): int
    {
        $count = (int) ContentReport::query()
            ->forContent(ContentReport::CONTENT_TYPE_STORY, (int) $story->id)
            ->distinct('reporter_member_id')
            ->count('reporter_member_id');

        $story->forceFill(['reports_count' => $count])->saveQuietly();

        return $count;
    }

    /**
     * Cuarentena automática — apagada por defecto.
     *
     * Qué hace: OCULTA temporalmente del feed. Qué NO hace: eliminar contenido,
     * sancionar al autor, cerrar el caso ni aplicar nada permanente. Todo eso
     * exige un moderador humano.
     */
    private function maybeAutoQuarantine(?Story $story): void
    {
        if (! $story || ! config('ugc.auto_quarantine_enabled', false)) {
            return;
        }
        if ($story->moderation_state !== Story::MODERATION_VISIBLE) {
            return;
        }

        // Umbral más bajo para motivos críticos (menores, autolesión, ilegal),
        // que igualmente van a revisión humana obligatoria.
        $hasCritical = ContentReport::query()
            ->forContent(ContentReport::CONTENT_TYPE_STORY, (int) $story->id)
            ->whereIn('reason_code', [
                ReportReason::CHILD_SAFETY,
                ReportReason::SELF_HARM,
                ReportReason::ILLEGAL,
            ])
            ->exists();

        $threshold = $hasCritical
            ? (int) config('ugc.auto_quarantine_critical_reporters', 2)
            : (int) config('ugc.auto_quarantine_unique_reporters', 5);

        if ($threshold <= 0 || (int) $story->reports_count < $threshold) {
            return;
        }

        $story->forceFill([
            'moderation_state' => Story::MODERATION_QUARANTINED,
            'moderated_at' => now(),
            'moderation_reason_code' => 'auto_quarantine_threshold',
        ])->saveQuietly();

        $this->audit->system(
            ModerationAuditLog::ACTION_CONTENT_QUARANTINED,
            'story',
            (int) $story->id,
            [
                'unique_reporters' => (int) $story->reports_count,
                'threshold' => $threshold,
                'automatic' => true,
            ],
        );
    }

    /**
     * Texto libre del reportante: se neutraliza (el CRM lo renderiza) y se
     * acota. Nunca es autoridad de negocio — el motivo del catálogo sí lo es.
     */
    private function sanitizeDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $clean = trim(strip_tags($detail));
        if ($clean === '') {
            return null;
        }

        $max = (int) config('ugc.report_detail_max_length', 500);

        return mb_substr($clean, 0, $max);
    }
}
