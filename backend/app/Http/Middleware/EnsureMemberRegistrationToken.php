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
