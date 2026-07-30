<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\MemberDeviceSession;
use App\Services\DeviceSessionService;
use App\Services\Moderation\SessionEnforcer;
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
            if ($member->status === Member::STATUS_DELETED) {
                return $this->unauthorized($request, 'account_deleted', 'Esta cuenta fue eliminada.');
            }
            if ($member->isSuspended()) {
                return $this->unauthorized($request, 'account_suspended', 'Tu cuenta fue suspendida por seguridad.');
            }
            if ($blocked = $this->moderationBlock($request, $member)) {
                return $blocked;
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
            // Una sesión cortada por una SANCIÓN no puede explicarse como un
            // relevo de dispositivo: al sancionado se le estaría dando un
            // motivo falso, y el aviso de que su membresía sigue intacta —lo
            // que de verdad le preocupa— no llegaría nunca.
            if ($revoked->revoked_reason === SessionEnforcer::REVOKE_REASON
                && ($blocked = $this->moderationBlock($request, $revoked->member))) {
                return $blocked;
            }

            return $this->unauthorized(
                $request,
                'session_revoked',
                'Tu sesión se cerró porque la cuenta se está usando en otro dispositivo.',
            );
        }

        // (2) Compatibilidad: access_hash permanente (rechazado si fue revocado).
        $member = Member::where('access_hash', $token)
            ->whereNull('access_hash_revoked_at')
            ->first();
        if (! $member) {
            // Antes de responder un genérico «token inválido»: si el hash fue
            // revocado precisamente por una sanción de acceso, la app debe poder
            // mostrar la pantalla de cuenta restringida en vez de un error
            // técnico que el usuario no puede interpretar.
            $revokedOwner = Member::where('access_hash', $token)
                ->whereNotNull('access_hash_revoked_at')
                ->first();

            if ($revokedOwner && ($blocked = $this->moderationBlock($request, $revokedOwner))) {
                return $blocked;
            }

            return $this->unauthorized($request, 'invalid_token', 'Token inválido.');
        }
        if ($member->status === Member::STATUS_DELETED) {
            return $this->unauthorized($request, 'account_deleted', 'Esta cuenta fue eliminada.');
        }
        if ($member->isSuspended()) {
            return $this->unauthorized($request, 'account_suspended', 'Tu cuenta fue suspendida por seguridad.');
        }
        if ($blocked = $this->moderationBlock($request, $member)) {
            return $blocked;
        }

        $request->attributes->set('auth_member', $member);

        return $next($request);
    }

    /**
     * Barrera de moderación: una sanción viva de `full_app_access` invalida
     * cualquier token, se haya emitido antes o después de la sanción.
     *
     * Se comprueba en AMBAS vías de autenticación (sesión de dispositivo y
     * `access_hash` legacy) porque cubrir sólo una dejaría la otra como puerta
     * de escape. La revocación al aplicar la sanción es la primera defensa;
     * esta es la que garantiza que ningún token superviviente sirva.
     *
     * Devuelve 401 —no 403— para que la app lo trate como fin de sesión y
     * navegue al login, donde recibirá la explicación completa.
     */
    private function moderationBlock(Request $request, ?Member $member): ?Response
    {
        if ($member === null) {
            return null;
        }

        $suspension = app(SessionEnforcer::class)->blockFor((int) $member->id);

        if ($suspension === null) {
            return null;
        }

        Log::info('auth:member:failed', [
            'reason' => 'account_moderation_suspended',
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return response()->json(SessionEnforcer::payload($suspension), 401);
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
