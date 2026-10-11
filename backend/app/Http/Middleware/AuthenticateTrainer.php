<?php

namespace App\Http\Middleware;

use App\Models\TrainerDeviceSession;
use App\Services\Trainer\TrainerSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación del portal profesional por bearer token. Resuelve la sesión de
 * dispositivo del entrenador (emitida tras verificar OTP o desbloqueo
 * biométrico). Una sesión revocada o un entrenador desactivado en el CRM dejan
 * de resolver: el cliente recibe 401 con un `code` estable y debe reautenticarse.
 *
 * Aislamiento de scope: este middleware NUNCA acepta un token de miembro y
 * viceversa (tablas y hashes de token separados).
 */
class AuthenticateTrainer
{
    public function __construct(private readonly TrainerSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('token_required', 'Token requerido.');
        }

        $session = $this->sessions->resolveByToken($token);
        if ($session) {
            $trainer = $session->trainer;
            if (! $trainer) {
                return $this->unauthorized('invalid_session', 'Sesión inválida.');
            }
            if (! $trainer->isActive()) {
                return $this->unauthorized('trainer_inactive', 'Tu acceso profesional fue desactivado.');
            }
            if ($session->isEphemeral() && $this->expired($session)) {
                $this->sessions->revoke($session, 'expired');

                return $this->unauthorized('session_expired', 'Tu sesión en esta tablet terminó. Vuelve a identificarte.');
            }

            $this->sessions->touch($session);
            $request->attributes->set('auth_trainer', $trainer);
            $request->attributes->set('auth_trainer_session', $session);

            return $next($request);
        }

        // Token de una sesión revocada (cierre remoto / desactivación).
        $revoked = TrainerDeviceSession::query()
            ->whereNotNull('revoked_at')
            ->where('token_hash', TrainerDeviceSession::hashToken($token))
            ->first();
        if ($revoked) {
            return $this->unauthorized('session_revoked', 'Tu sesión profesional se cerró.');
        }

        return $this->unauthorized('invalid_token', 'Token inválido.');
    }

    /** Sesión de kiosko vencida: pasó su hora límite o lleva demasiado sin uso. */
    private function expired(TrainerDeviceSession $session): bool
    {
        $idle = (int) config('workforce.kiosk.trainer_session_idle_minutes', 15);

        return $session->expires_at->isPast()
            || ($session->last_seen_at !== null && $session->last_seen_at->lt(now()->subMinutes($idle)));
    }

    private function unauthorized(string $code, string $message): Response
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ], 401);
    }
}
