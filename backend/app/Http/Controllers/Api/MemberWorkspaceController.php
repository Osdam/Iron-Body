<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\TrainerFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espacios disponibles para el MIEMBRO autenticado. Permite a la app decidir si
 * mostrar "Cambiar a Portal de entrenador" en cuentas dobles, SIN revelar nada
 * al miembro normal: si la identidad no tiene un perfil profesional activo (o el
 * feature flag está apagado para ella), la respuesta es exactamente la de
 * cualquier miembro (`has_trainer_portal: false`).
 *
 * El backend es la autoridad: cambiar de miembro a entrenador exige el acceso
 * profesional completo (OTP/biometría, step-up); este endpoint solo expone la
 * disponibilidad, nunca concede acceso.
 */
class MemberWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $hasTrainerPortal = false;
        if ($member->identity_id !== null) {
            $identity = $member->identity;
            $hasTrainerPortal = $identity !== null
                && $identity->hasActiveTrainerProfile()
                && TrainerFeatures::enabled('workspace_switching_enabled', $identity);
        }

        return response()->json([
            'ok' => true,
            'workspaces' => $hasTrainerPortal ? ['member', 'trainer'] : ['member'],
            'has_trainer_portal' => $hasTrainerPortal,
        ]);
    }
}
