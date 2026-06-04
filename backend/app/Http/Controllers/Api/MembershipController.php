<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renovación / cancelación de membresía para el miembro autenticado (Bloque 3).
 *
 * Flujo de cancelación en dos pasos (claro, reversible, sin borrar datos):
 *   1) cancel-request → vista previa real (hasta cuándo conserva acceso).
 *   2) cancel-confirm → ejecuta. El miembro mantiene el acceso hasta el fin del
 *      periodo vigente; luego la membresía queda 'cancelled'.
 * reactivate deshace la cancelación mientras el periodo siga vigente.
 *
 * No exige OTP: es una acción REVERSIBLE que no elimina datos ni revoca acceso
 * inmediato (a diferencia de borrar cuenta / desvincular dispositivo, que sí
 * llevan 2FA). El acceso al Home no se corta hasta que expire el periodo.
 */
class MembershipController extends Controller
{
    public function __construct(private MembershipService $memberships)
    {
    }

    public function status(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    public function cancelRequest(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user || ! $user->plan) {
            return $this->noMembership();
        }
        return response()->json([
            'ok' => true,
            'data' => $this->memberships->previewCancellation($user),
        ]);
    }

    public function cancelConfirm(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user || ! $user->plan) {
            return $this->noMembership();
        }
        return response()->json([
            'ok' => true,
            'message' => 'Tu renovación fue cancelada. Conservas el acceso hasta el fin del periodo.',
            'data' => $this->memberships->requestCancellation($user, 'member'),
        ]);
    }

    public function reactivate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user || ! $user->plan) {
            return $this->noMembership();
        }
        return response()->json([
            'ok' => true,
            'message' => 'Tu renovación quedó activa nuevamente.',
            'data' => $this->memberships->reactivate($user, 'member'),
        ]);
    }

    private function respond(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user) {
            return response()->json([
                'ok' => true,
                'data' => ['status' => MembershipService::STATUS_NONE, 'is_active' => false],
            ]);
        }
        return response()->json(['ok' => true, 'data' => $this->memberships->snapshot($user)]);
    }

    private function user(Request $request)
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');
        $member?->loadMissing('user');
        return $member?->user;
    }

    private function noMembership(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'No tienes una membresía activa para gestionar.',
        ], 422);
    }
}
