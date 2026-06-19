<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blindaje administrativo / CRM: exige el secreto compartido
 * (config('admin.api_token'), env ADMIN_API_TOKEN) en `Authorization: Bearer
 * <token>`. Sin él la API responde 401; con un valor distinto, 403.
 *
 * Se usa como middleware de ruta (alias `auth.admin`) y SIEMPRE exige el token:
 * blinda rutas CRM que NO viven bajo el prefijo /admin (dashboard, users,
 * reports, attendances, turnstile, routines y la ESCRITURA de planes/clases/
 * entrenadores). La cobertura global de /api/admin/* y los pagos legacy la hace
 * ProtectAdminPaths (que reutiliza el mismo `challenge`).
 *
 * SEGURIDAD:
 *  - Comparación en tiempo constante (hash_equals) para no filtrar el secreto.
 *  - Falla CERRADO: si el secreto no está configurado, deniega (503).
 */
class EnsureAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($response = self::challenge($request)) {
            return $response;
        }

        return $next($request);
    }

    /**
     * Valida el secreto administrativo. Devuelve la respuesta de rechazo
     * (401/403/503) o null si el token es correcto. Reutilizable por el guard
     * global de rutas /api/admin/* y pagos legacy (ProtectAdminPaths).
     */
    public static function challenge(Request $request): ?Response
    {
        $expected = config('admin.api_token');

        // Falla cerrado: sin secreto configurado no hay forma segura de
        // autenticar; preferimos denegar antes que reabrir el panel.
        if (! is_string($expected) || $expected === '') {
            Log::error('auth:admin:misconfigured', ['path' => $request->path()]);

            return self::deny($request, 'admin_auth_unconfigured', 'Acceso administrativo no configurado.', 503);
        }

        $token = $request->bearerToken();

        if (! $token) {
            return self::deny($request, 'admin_token_required', 'Token administrativo requerido.', 401);
        }

        if (! hash_equals($expected, $token)) {
            return self::deny($request, 'admin_token_invalid', 'Token administrativo inválido.', 403);
        }

        return null;
    }

    private static function deny(Request $request, string $code, string $message, int $status): Response
    {
        Log::info('auth:admin:failed', [
            'reason' => $code,
            'path'   => $request->path(),
            'ip'     => $request->ip(),
        ]);

        return response()->json([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
        ], $status);
    }
}
