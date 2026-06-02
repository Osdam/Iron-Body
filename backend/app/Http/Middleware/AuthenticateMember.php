<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\MemberDeviceSession;
use App\Services\DeviceSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación de miembros por bearer token. Resuelve en este orden:
 *   1. `session_token` de dispositivo (emitido tras verificar OTP). Permite
 *      revocación remota y control de concurrencia.
 *   2. `access_hash` permanente del miembro (compatibilidad hacia atrás).
 *
 * Una sesión revocada deja de resolver por (1); el cliente recibe 401 y debe
 * volver a iniciar sesión.
 *
 * Toda respuesta 401 incluye un `code` estable para que la app distinga el
 * motivo (token_required | invalid_session | session_revoked | invalid_token)
 * sin depender del texto del mensaje.
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
            return $this->unauthorized($request, 'token_required', 'Token requerido.');
        }

        // (1) Sesión por dispositivo.
        $session = $this->sessions->resolveByToken($token);
        if ($session) {
            $member = $session->member;
            if (! $member) {
                return $this->unauthorized($request, 'invalid_session', 'Sesión inválida.');
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
            return $this->unauthorized(
                $request,
                'session_revoked',
                'Tu sesión se cerró porque la cuenta se está usando en otro dispositivo.',
            );
        }

        // (2) Compatibilidad: access_hash permanente.
        $member = Member::where('access_hash', $token)->first();
        if (! $member) {
            return $this->unauthorized($request, 'invalid_token', 'Token inválido.');
        }

        $request->attributes->set('auth_member', $member);

        return $next($request);
    }

    /**
     * Respuesta 401 con `code` estable + log seguro (nunca el token completo).
     */
    private function unauthorized(Request $request, string $code, string $message): Response
    {
        Log::info('auth:member:failed', [
            'reason' => $code,
            'path'   => $request->path(),
            'ip'     => $request->ip(),
        ]);

        return response()->json([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
        ], 401);
    }
}
