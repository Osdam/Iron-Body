<?php

namespace App\Http\Middleware;

use App\Models\Identity;
use App\Models\Member;
use App\Models\Trainer;
use App\Support\TrainerFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica un feature flag del portal profesional EN EL BACKEND. Si la bandera
 * está apagada para el contexto, responde 404 para no filtrar la existencia de
 * la funcionalidad (anti-enumeración). El backend es la autoridad; ocultar en
 * Flutter no basta.
 *
 * Resuelve la identidad del contexto disponible (entrenador o miembro
 * autenticado) para soportar pilotos por identidad. En rutas sin sesión (p.ej.
 * inicio del acceso profesional) solo aplica la bandera global.
 *
 * Uso: `->middleware('trainer.feature:trainer_auth_enabled')`.
 */
class EnsureTrainerFeature
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! TrainerFeatures::enabled($flag, $this->resolveIdentity($request))) {
            return response()->json([
                'ok' => false,
                'code' => 'not_found',
                'message' => 'Recurso no disponible.',
            ], 404);
        }

        return $next($request);
    }

    private function resolveIdentity(Request $request): ?Identity
    {
        $trainer = $request->attributes->get('auth_trainer');
        if ($trainer instanceof Trainer && $trainer->identity_id !== null) {
            return $trainer->identity;
        }

        $member = $request->attributes->get('auth_member');
        if ($member instanceof Member && $member->identity_id !== null) {
            return $member->identity;
        }

        return null;
    }
}
