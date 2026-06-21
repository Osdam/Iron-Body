<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSecurityEvent;
use App\Models\MemberSupportTicket;
use App\Models\SupportSecurityReport;
use App\Services\DeviceSessionService;
use App\Services\NotificationService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Soporte de seguridad / acceso (Fase 9).
 *
 * - submit(): público (SIN sesión) desde el login. Crea un ticket de seguridad.
 *   NO revela si el documento existe ni desbloquea nada automáticamente.
 * - admin*(): bandeja del CRM para revisar, cambiar estado, dejar nota y, si el
 *   reporte está vinculado a un miembro, revocar todas sus sesiones por soporte.
 */
class SecuritySupportController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private DeviceSessionService $sessions,
        private SecurityEventService $security,
    ) {
    }

    /** POST security/support-report — reporte público (login). */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_type'     => ['required', 'string', Rule::in(SupportSecurityReport::TYPES)],
            'document_number' => ['nullable', 'string', 'max:40'],
            'name'            => ['nullable', 'string', 'max:160'],
            'phone'           => ['nullable', 'string', 'max:40'],
            'email'           => ['nullable', 'email', 'max:160'],
            'description'     => ['nullable', 'string', 'max:2000'],
            'contact_channel' => ['nullable', 'string', 'max:40'],
        ]);

        // Resolver al miembro por documento SOLO para enlazar internamente; NO se
        // refleja en la respuesta (no se revela si la cuenta existe).
        $member = $this->resolveMember($data['document_number'] ?? null);

        $report = SupportSecurityReport::create([
            'member_id'       => $member?->id,
            'document_number' => $data['document_number'] ?? null,
            'name'            => $data['name'] ?? null,
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'report_type'     => $data['report_type'],
            'status'          => SupportSecurityReport::STATUS_PENDING,
            'description'     => $data['description'] ?? null,
            'contact_channel' => $data['contact_channel'] ?? null,
            'ip_address'      => $request->ip(),
            'user_agent'      => mb_substr((string) $request->userAgent(), 0, 512),
            'metadata'        => ['source' => 'login'],
        ]);

        if ($member) {
            $this->security->record($member, MemberSecurityEvent::TYPE_SUPPORT_REPORT, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], ['report_id' => $report->id, 'report_type' => $report->report_type]);
        }

        // El reporte también aterriza en la bandeja de Soporte del CRM, para que
        // el equipo lo gestione desde el mismo lugar que el resto de tickets.
        $this->createSupportTicket($report, $member);

        $this->notifications->notifySecuritySupportReport($report, $member);

        // Respuesta deliberadamente genérica e idéntica exista o no la cuenta.
        return response()->json([
            'ok'      => true,
            'message' => 'Recibimos tu solicitud. El equipo del gimnasio revisará tu caso y te contactará.',
        ]);
    }

    // ── CRM admin ─────────────────────────────────────────────────────────────

    /** GET admin/security/reports — bandeja (filtro opcional ?status=). */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = SupportSecurityReport::query()->latest('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reports = $query->limit(200)->get()->map(fn (SupportSecurityReport $r) => $this->present($r));

        return response()->json(['ok' => true, 'data' => $reports]);
    }

    /** GET admin/security/reports/{report}. */
    public function adminShow(SupportSecurityReport $report): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->present($report, full: true)]);
    }

    /** PATCH admin/security/reports/{report} — estado + nota. */
    public function adminUpdate(Request $request, SupportSecurityReport $report): JsonResponse
    {
        $data = $request->validate([
            'status'          => ['nullable', 'string', Rule::in(SupportSecurityReport::STATUSES)],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
            'resolved_by'     => ['nullable', 'integer'],
        ]);

        $report->fill(array_filter([
            'status'          => $data['status'] ?? null,
            'resolution_note' => $data['resolution_note'] ?? null,
            'resolved_by'     => $data['resolved_by'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return response()->json(['ok' => true, 'data' => $this->present($report, full: true)]);
    }

    /**
     * POST admin/security/reports/{report}/revoke-devices — cierra TODAS las
     * sesiones del miembro vinculado (acción de soporte ante robo). Auditada.
     */
    public function adminRevokeDevices(SupportSecurityReport $report): JsonResponse
    {
        if (! $report->member_id) {
            return response()->json(['ok' => false, 'message' => 'El reporte no está vinculado a un miembro.'], 422);
        }
        $member = Member::find($report->member_id);
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Miembro no encontrado.'], 404);
        }

        $count = 0;
        foreach ($this->sessions->activeSessions($member) as $session) {
            $this->sessions->revoke($session, 'revoked_by_support');
            $count++;
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_REVOKED, [], [
            'scope'         => 'all_by_support',
            'report_id'     => $report->id,
            'revoked_count' => $count,
        ]);

        $report->forceFill([
            'metadata' => array_merge((array) $report->metadata, [
                'support_revoked_devices' => $count,
                'support_revoked_at'      => now()->toIso8601String(),
            ]),
        ])->save();

        return response()->json([
            'ok'            => true,
            'revoked_count' => $count,
            'message'       => "Se cerraron {$count} sesión(es) del miembro.",
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Etiquetas legibles (ES) de cada tipo de reporte de acceso. */
    private const REPORT_TYPE_LABELS = [
        SupportSecurityReport::TYPE_STOLEN_DEVICE       => 'Me robaron el celular',
        SupportSecurityReport::TYPE_LOST_ACCESS         => 'Perdí acceso a mi número',
        SupportSecurityReport::TYPE_PHONE_CHANGED       => 'Cambié de teléfono',
        SupportSecurityReport::TYPE_SUSPICIOUS_ACTIVITY => 'Actividad sospechosa',
        SupportSecurityReport::TYPE_OTHER               => 'Otro',
    ];

    /**
     * Crea un ticket en la bandeja de Soporte del CRM a partir del reporte de
     * acceso. Así el equipo ve y gestiona estas solicitudes desde un solo lugar
     * (el reporte de seguridad sigue existiendo para la acción de revocar
     * dispositivos). Aditivo: nunca rompe el flujo de envío del reporte.
     */
    private function createSupportTicket(SupportSecurityReport $report, ?Member $member): void
    {
        try {
            $label = self::REPORT_TYPE_LABELS[$report->report_type] ?? $report->report_type;

            // Mensaje con el motivo + datos de contacto (la persona NO está
            // autenticada y puede haber perdido el número de la cuenta).
            $lines = ["Solicitud de acceso/seguridad: {$label}."];
            if ($report->description) {
                $lines[] = "Descripción: {$report->description}";
            }
            $contact = array_filter([
                $report->name ? "Nombre: {$report->name}" : null,
                $report->phone ? "Teléfono de contacto: {$report->phone}" : null,
                $report->email ? "Correo: {$report->email}" : null,
                $report->document_number ? "Documento: {$report->document_number}" : null,
            ]);
            if ($contact !== []) {
                $lines[] = 'Contacto: ' . implode(' · ', $contact);
            }

            $ticket = MemberSupportTicket::create([
                'member_id' => $member?->id,
                'user_id'   => $member?->user_id,
                'document'  => $report->document_number,
                'type'      => 'access',
                'message'   => implode("\n", $lines),
                'status'    => MemberSupportTicket::STATUS_NEW,
                'platform'  => 'login',
                'metadata'  => array_filter([
                    'source'           => 'login_security_report',
                    'security_report_id' => $report->id,
                    'report_type'      => $report->report_type,
                    'contact_phone'    => $report->phone,
                    'contact_email'    => $report->email,
                ]),
            ]);

            $this->notifications->notifySupportTicket($ticket->fresh('member'));
        } catch (\Throwable $e) {
            // El ticket es aditivo: si falla, el reporte de seguridad ya quedó.
        }
    }

    private function resolveMember(?string $document): ?Member
    {
        if ($document === null || trim($document) === '') {
            return null;
        }
        $raw    = trim($document);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return Member::query()
            ->where('document_number', $raw)
            ->when($digits !== '' && $digits !== $raw, fn ($q) => $q->orWhere('document_number', $digits))
            ->first();
    }

    private function present(SupportSecurityReport $r, bool $full = false): array
    {
        $base = [
            'id'           => $r->id,
            'report_type'  => $r->report_type,
            'status'       => $r->status,
            'name'         => $r->name,
            'document'     => $r->document_number,
            'phone'        => $r->phone,
            'email'        => $r->email,
            'member_id'    => $r->member_id,
            'created_at'   => $r->created_at?->toIso8601String(),
        ];
        if ($full) {
            $base += [
                'description'     => $r->description,
                'contact_channel' => $r->contact_channel,
                'resolution_note' => $r->resolution_note,
                'resolved_by'     => $r->resolved_by,
                'ip_address'      => $r->ip_address,
                'metadata'        => $r->metadata,
                'member_name'     => $r->member?->full_name,
            ];
        }

        return $base;
    }
}
