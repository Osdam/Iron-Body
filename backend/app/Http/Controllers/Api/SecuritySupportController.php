<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSecurityEvent;
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
