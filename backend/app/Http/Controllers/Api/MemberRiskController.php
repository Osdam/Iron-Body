<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberRiskLock;
use App\Services\AccountRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión CRM de bloqueos de seguridad (Fase 10): ver suspensiones, suspender
 * manualmente, extender y desbloquear. Sigue la convención sin-auth del resto
 * del CRM (la app móvil usa auth.member; el CRM va por su capa de red).
 */
class MemberRiskController extends Controller
{
    public function __construct(private AccountRiskService $risk)
    {
    }

    /** GET admin/security/locks — bloqueos vivos (suspensiones activas). */
    public function index(Request $request): JsonResponse
    {
        $locks = MemberRiskLock::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->live())
            ->with('member:id,full_name,document_number,status,phone')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (MemberRiskLock $l) => [
                'id'              => $l->id,
                'member_id'       => $l->member_id,
                'member_name'     => $l->member?->full_name,
                'document'        => $l->member?->document_number,
                'reason'          => $l->reason,
                'status'          => $l->status,
                'locked_until'    => $l->locked_until?->toIso8601String(),
                'created_by'      => $l->created_by,
                'resolution_note' => $l->resolution_note,
                'created_at'      => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'data' => $locks]);
    }

    /** POST admin/members/{member}/suspend — suspende manualmente. */
    public function suspend(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'reason'      => ['required', 'string', 'max:255'],
            'days'        => ['nullable', 'integer', 'min:1', 'max:365'],
            'resolved_by' => ['nullable', 'integer'],
        ]);

        $lock = $this->risk->suspend(
            $member,
            $data['reason'],
            $data['days'] ?? (int) config('security.suspend_days', 3),
            MemberRiskLock::BY_ADMIN,
            $data['resolved_by'] ?? null,
        );

        return response()->json([
            'ok'           => true,
            'message'      => 'Cuenta suspendida.',
            'locked_until' => $lock->locked_until?->toIso8601String(),
        ]);
    }

    /** POST admin/members/{member}/unlock — levanta la suspensión. */
    public function unlock(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'note'        => ['nullable', 'string', 'max:1000'],
            'resolved_by' => ['nullable', 'integer'],
        ]);

        $this->risk->unlock($member, $data['note'] ?? null, $data['resolved_by'] ?? null);

        return response()->json(['ok' => true, 'message' => 'Cuenta desbloqueada.']);
    }
}
