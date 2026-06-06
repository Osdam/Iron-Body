<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gestión del acceso de STAFF a Story Live desde el CRM (Bloque extra). Solo el
 * área administrativa puede otorgar/quitar `is_staff` (poder crear/transmitir
 * lives). El usuario móvil NUNCA puede auto-marcarse staff: esta ruta es del
 * CRM (patrón del CRM, sin auth a nivel de ruta; el acceso se controla allí) y
 * no está dentro del grupo `auth.member`.
 */
class MemberStaffController extends Controller
{
    public function show(Member $member): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $member->only([
                'id', 'full_name', 'document_number', 'phone', 'is_staff', 'status',
            ]),
        ]);
    }

    public function updateStaffAccess(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'is_staff' => ['required', 'boolean'],
            // Opcional: id del admin que ejecuta (para auditoría).
            'admin_id' => ['nullable', 'integer'],
        ]);

        $member->forceFill(['is_staff' => $data['is_staff']])->save();

        Log::info('member.staff_access.updated', [
            'by_admin_id' => $data['admin_id'] ?? null,
            'member_id'   => $member->id,
            'is_staff'    => (bool) $data['is_staff'],
        ]);

        // Real-time: "Transmitir en vivo" aparece/desaparece sin relogin.
        \App\Services\RealtimeEvents::livePermissions($member->id);

        return response()->json([
            'ok' => true,
            'message' => $data['is_staff']
                ? 'Acceso de staff (Story Live) otorgado.'
                : 'Acceso de staff (Story Live) retirado.',
            'data' => $member->fresh()->only([
                'id', 'full_name', 'document_number', 'is_staff', 'status',
            ]),
        ]);
    }
}
