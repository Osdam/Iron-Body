<?php

namespace App\Http\Resources\Moderation;

use App\Models\ContentReport;
use App\Models\ModerationAction;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle de un caso para el CRM.
 *
 * Igual que la fila de la cola: NINGÚN dato del reportante. Lo que sí se
 * incluye —y es imprescindible para decidir— es el motivo, la evidencia
 * (metadata; la URL se pide aparte y es temporal), el historial de reportes
 * sobre el mismo contenido, el historial de sanciones del autor y la línea de
 * tiempo del caso.
 *
 * La URL del medio NO viaja aquí: se solicita al endpoint de evidencia, que
 * exige permiso, firma una URL de minutos y deja traza en auditoría.
 *
 * @mixin ContentReport
 */
class AdminReportDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ContentReport $report */
        $report = $this->resource;
        $snapshot = $report->snapshot;

        return [
            'id' => $report->public_id,
            'status' => $report->status,
            'status_label' => ReportStatus::label($report->status),
            'allowed_transitions' => ReportStatus::transitions()[$report->status] ?? [],
            'severity' => $report->severity,
            'priority' => (int) $report->priority,
            'reason_code' => $report->reason_code,
            'reason_label' => ReportReason::labelFor($report->reason_code),
            // Texto libre del reportante — ya saneado al guardarse. No permite
            // identificarlo: es su descripción del problema.
            'reason_detail' => $report->reason_detail,
            'content_type' => $report->content_type,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'resolution_code' => $report->resolution_code,
            'moderator_notes' => $report->moderator_notes,
            'lock_version' => (int) $report->lock_version,
            'open_minutes' => $report->openMinutes(),

            // Reportante ANONIMIZADO por diseño.
            'reporter' => [
                'anonymous' => true,
                'label' => 'Reportante confidencial',
            ],
            'unique_reporters' => (int) ($report->unique_reporters ?? 1),

            'reported_member' => $report->reported_member_id ? [
                'id' => (int) $report->reported_member_id,
                'name' => trim((string) ($report->reportedMember?->full_name ?? ''))
                    ?: 'Miembro Iron Body',
                'document' => null, // el documento no es necesario para moderar
            ] : null,

            'assigned_admin' => $report->assigned_admin_id ? [
                'id' => (int) $report->assigned_admin_id,
                'name' => $report->assignedAdmin?->name,
            ] : null,

            // Estado actual del contenido (puede estar borrado por su autor).
            'content' => [
                'story_id' => (int) $report->content_id,
                'still_exists' => $report->story !== null,
                'is_deleted' => $report->story?->trashed() ?? true,
                'moderation_state' => $report->story?->moderation_state,
                'expired' => $report->story?->expires_at?->isPast() ?? true,
            ],

            // Evidencia congelada: metadata sí, binario no. `media_available`
            // indica si todavía se puede pedir una URL firmada.
            'evidence' => $snapshot ? [
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
                'media_type' => $snapshot->media_type,
                'caption' => $snapshot->caption_snapshot,
                'published_at' => $snapshot->published_at?->toIso8601String(),
                'expires_at' => $snapshot->expires_at?->toIso8601String(),
                'media_available' => $snapshot->hasReviewableMedia(),
                'media_purged_at' => $snapshot->media_purged_at?->toIso8601String(),
                'retention_until' => $snapshot->purge_after?->toIso8601String(),
                'metadata' => $snapshot->metadata,
            ] : null,

            // Historial de OTROS reportes sobre el mismo contenido (sin
            // reportantes: solo motivos y fechas).
            'related_reports' => $this->relatedReports($report),

            // Historial de sanciones del autor — reincidencia visible.
            'member_history' => $this->memberHistory($report),

            // Línea de tiempo del caso.
            'timeline' => $this->timeline($report),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function relatedReports(ContentReport $report): array
    {
        return ContentReport::query()
            ->forContent($report->content_type, (int) $report->content_id)
            ->whereKeyNot($report->id)
            ->orderByDesc('submitted_at')
            ->limit(50)
            ->get()
            ->map(fn (ContentReport $r) => [
                'id' => $r->public_id,
                'reason_code' => $r->reason_code,
                'reason_label' => ReportReason::labelFor($r->reason_code),
                'status' => $r->status,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function memberHistory(ContentReport $report): array
    {
        if (! $report->reported_member_id) {
            return ['total_reports' => 0, 'actions' => []];
        }

        $totalReports = ContentReport::query()
            ->where('reported_member_id', $report->reported_member_id)
            ->count();

        $actions = ModerationAction::query()
            ->where('target_member_id', $report->reported_member_id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ModerationAction $a) => [
                'id' => $a->public_id,
                'type' => $a->action_type,
                'type_label' => ActionType::label($a->action_type),
                'scope' => $a->scope,
                'starts_at' => $a->starts_at?->toIso8601String(),
                'ends_at' => $a->ends_at?->toIso8601String(),
                'revoked_at' => $a->revoked_at?->toIso8601String(),
            ])->all();

        return [
            'total_reports' => $totalReports,
            'actions' => $actions,
            'is_repeat_offender' => count($actions) >= 2,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(ContentReport $report): array
    {
        $events = [];

        $events[] = [
            'at' => $report->submitted_at?->toIso8601String(),
            'label' => 'Reporte recibido',
            'detail' => ReportReason::labelFor($report->reason_code),
        ];

        if ($report->reviewed_at) {
            $events[] = [
                'at' => $report->reviewed_at->toIso8601String(),
                'label' => 'En revisión',
                'detail' => null,
            ];
        }

        foreach ($report->actions()->orderBy('created_at')->get() as $action) {
            $events[] = [
                'at' => $action->created_at?->toIso8601String(),
                'label' => ActionType::label($action->action_type),
                'detail' => $action->reason,
            ];

            if ($action->revoked_at) {
                $events[] = [
                    'at' => $action->revoked_at->toIso8601String(),
                    'label' => 'Medida revocada',
                    'detail' => $action->revoke_reason,
                ];
            }
        }

        if ($report->resolved_at) {
            $events[] = [
                'at' => $report->resolved_at->toIso8601String(),
                'label' => 'Caso resuelto',
                'detail' => $report->resolution_code,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }
}
