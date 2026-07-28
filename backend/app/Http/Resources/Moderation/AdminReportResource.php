<?php

namespace App\Http\Resources\Moderation;

use App\Models\ContentReport;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la cola de moderación del CRM.
 *
 * GARANTÍA CENTRAL: aquí NO existe ninguna clave que identifique al
 * reportante. Ni `reporter_member_id`, ni su nombre, ni su documento, ni un
 * identificador derivado que permita correlacionar entre casos. El moderador
 * ve el motivo y la evidencia; nunca a quién denunció.
 *
 * Lo único que se expone del volumen de denuncias es `unique_reporters`, un
 * entero agregado.
 *
 * @mixin ContentReport
 */
class AdminReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ContentReport $report */
        $report = $this->resource;

        return [
            // Identificador PÚBLICO: el `id` secuencial no sale nunca (evita
            // enumeración y no filtra el volumen del sistema).
            'id' => $report->public_id,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'content_type' => $report->content_type,
            'reason_code' => $report->reason_code,
            'reason_label' => ReportReason::labelFor($report->reason_code),
            'severity' => $report->severity,
            'priority' => (int) $report->priority,
            'status' => $report->status,
            'status_label' => ReportStatus::label($report->status),
            'unique_reporters' => (int) ($report->unique_reporters ?? 1),
            'reported_member' => $report->reported_member_id ? [
                'id' => (int) $report->reported_member_id,
                'name' => $this->reportedName($report),
            ] : null,
            'assigned_admin' => $report->assigned_admin_id ? [
                'id' => (int) $report->assigned_admin_id,
                'name' => $report->assignedAdmin?->name,
            ] : null,
            'has_evidence' => (bool) ($report->has_evidence ?? false),
            'has_appeal' => (bool) ($report->has_appeal ?? false),
            'open_minutes' => $report->openMinutes(),
            'resolution_code' => $report->resolution_code,
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            // Versión para el control optimista del CRM.
            'lock_version' => (int) $report->lock_version,
        ];
    }

    private function reportedName(ContentReport $report): string
    {
        $name = trim((string) ($report->reportedMember?->full_name ?? ''));

        return $name !== '' ? $name : 'Miembro Iron Body';
    }
}
