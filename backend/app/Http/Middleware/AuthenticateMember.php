<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\MemberDeviceSession;
use App\Services\DeviceSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación de miembros por bearer token. Resuelve en este orden:
 *   1. `session_token` de dispositivo (emitido tras verificar OTP). Permite
 *      revocación remota y control de concurrencia.
 *   2. `access_hash` permanente del miembro (compatibilidad hacia atrás).
 *
 * Una sesión revocada deja de resolver por (1); el cliente recibe 401 y debe
 * volver a iniciar sesión.
 */
class AuthenticateMember
{
    public function __construct(private DeviceSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['ok' => false, 'message' => 'Token requerido.'], 401);
        }

        // (1) Sesión por dispositivo.
        $session = $this->sessions->resolveByToken($token);
        if ($session) {
            $member = $session->member;
            if (! $member) {
                return response()->json(['ok' => false, 'message' => 'Sesión inválida.'], 401);
            }
            $this->sessions->touch($session);
            $request->attributes->set('auth_member', $member);
            $request->attributes->set('auth_device_session', $session);

            return $next($request);
        }

        // Token de una sesión REVOCADA (relevo/cierre desde otro dispositivo):
        // se avisa con un código para que la app redirija al login.
        $revoked = MemberDeviceSession::query()
            ->whereNotNull('revoked_at')
            ->where('token_hash', MemberDeviceSession::hashToken($token))
            ->first();
        if ($revoked) {
            return response()->json([
                'ok'      => false,
                'code'    => 'session_revoked',
                'message' => 'Tu sesión se cerró porque la cuenta se está usando en otro dispositivo.',
            ], 401);
        }

        // (2) Compatibilidad: access_hash permanente.
        $member = Member::where('access_hash', $token)->first();
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Token inválido.'], 401);
        }

        $request->attributes->set('auth_member', $member);

        return $next($request);
    }
}
