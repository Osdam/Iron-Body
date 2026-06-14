<?php

namespace App\Http\Middleware;

use App\Models\Trainer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza una operación profesional por PERMISO (no por rol). Debe ir después
 * de `auth.trainer`, que deja el entrenador autenticado en los atributos del
 * request. Centraliza la comprobación: nada de `role == 'trainer'` disperso.
 *
 * Uso en rutas: `->middleware('trainer.can:assessments.submit')`.
 */
class EnsureTrainerPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $trainer = $request->attributes->get('auth_trainer');

        if (! $trainer instanceof Trainer) {
            return response()->json([
                'ok' => false,
                'code' => 'trainer_unauthenticated',
                'message' => 'Sesión profesional requerida.',
            ], 401);
        }

        if (! $trainer->hasPermission($permission)) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'No tienes permiso para esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
