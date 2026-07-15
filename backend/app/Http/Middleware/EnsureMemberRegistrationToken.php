<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberRegistrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.member_registration.token');

        if (! $expectedToken) {
            // Fail-closed en producción: sin token configurado, este grupo (login
            // OTP, registro, identidad/biometría, borrado de miembro) quedaría
            // PÚBLICO. En producción se rechaza; fuera de producción (local/tests)
            // se permite para no exigir el secreto en desarrollo.
            if (app()->environment('production')) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Registro no disponible temporalmente.',
                ], 503);
            }

            return $next($request);
        }

        $providedToken = $request->bearerToken();

        if (! $providedToken || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'ok' => false,
                'message' => 'Token de autenticacion invalido.',
            ], 401);
        }

        return $next($request);
    }
}
