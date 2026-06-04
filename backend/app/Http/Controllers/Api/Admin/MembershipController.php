<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de membresías desde el CRM (Bloque 3). Sigue el patrón del resto del
 * CRM (rutas admin sin auth a nivel de ruta; el acceso se controla en el CRM).
 *
 * Permite ver el estado, cancelar (programada o inmediata) y reactivar la
 * renovación. NUNCA borra datos del miembro.
 */
class MembershipController extends Controller
{
    public function __construct(private MembershipService $memberships)
    {
    }

    public function show(Member $member): JsonResponse
    {
        $member->loadMissing('user');
        if (! $member->user) {
            return $this->noUser();
        }
        return response()->json(['ok' => true, 'data' => $this->memberships->snapshot($member->user)]);
    }

    public function cancel(Request $request, Member $member): JsonResponse
    {
        $member->loadMissing('user');
        if (! $member->user) {
            return $this->noUser();
        }
        $immediate = $request->boolean('immediate');
        return response()->json([
            'ok' => true,
            'message' => $immediate
                ? 'Membresía cancelada de inmediato.'
                : 'Renovación cancelada al término del periodo.',
            'data' => $this->memberships->adminCancel($member->user, $immediate),
        ]);
    }

    public function reactivate(Member $member): JsonResponse
    {
        $member->loadMissing('user');
        if (! $member->user) {
            return $this->noUser();
        }
        return response()->json([
            'ok' => true,
            'message' => 'Renovación reactivada.',
            'data' => $this->memberships->adminReactivate($member->user),
        ]);
    }

    private function noUser(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'El miembro no tiene una cuenta de membresía asociada.',
        ], 422);
    }
}
