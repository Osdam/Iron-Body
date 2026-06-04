<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\IronAiMembershipAccessService;
use App\Services\IronAiUserContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Contexto saneado del miembro para el coach IRON IA. Reutiliza el mismo
 * IronAiUserContextService que ya alimenta al coach server-side, así que no
 * hay datos inventados ni hardcodeados. Solo lectura.
 *
 * El acceso operativo a la IA sigue gateado por membresía (ai_enabled): sin
 * plan activo, el coach se limita según la regla de negocio existente.
 */
class MemberAiContextController extends Controller
{
    public function show(
        Request $request,
        IronAiUserContextService $context,
        IronAiMembershipAccessService $access
    ): JsonResponse {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        // Gating de IA (consistente con /iron-ai/access). Si falla la
        // resolución, por seguridad se asume IA deshabilitada.
        $aiEnabled = false;
        try {
            $aiEnabled = (bool) ($access->resolveAccess($request)['ai_enabled'] ?? false);
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json([
            'ok'          => true,
            'ai_enabled'  => $aiEnabled,
            'context'     => $context->build($member),
            'restrictions' => [
                'medical_disclaimer_required' => true,
                'avoid_diagnosis'             => true,
            ],
        ])->header('Cache-Control', 'no-store, max-age=0, must-revalidate');
    }
}
