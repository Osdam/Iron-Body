<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['ok' => false, 'message' => 'Token requerido.'], 401);
        }

        $member = Member::where('access_hash', $token)->first();

        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Token inválido.'], 401);
        }

        $request->attributes->set('auth_member', $member);

        return $next($request);
    }
}
