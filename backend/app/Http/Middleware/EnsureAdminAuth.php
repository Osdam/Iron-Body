<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blindaje administrativo / CRM. Acepta DOS credenciales en `Authorization:
 * Bearer <token>`:
 *   1. Una sesión admin real (login email+contraseña → {@see AdminSessionService}).
 *      Al resolver, expone el admin en `auth_admin` / `auth_admin_session`.
 *   2. El secreto compartido `config('admin.api_token')` como FALLBACK para
 *      automatizaciones (n8n) — comparación en tiempo constante (hash_equals).
 *
 * Sin token → 401; token que no resuelve a ninguna de las dos → 403.
 *
 * Se usa como middleware de ruta (alias `auth.admin`) en rutas CRM fuera de
 * /admin (dashboard, users, reports, ...). La cobertura global de /api/admin/* y
 * los pagos legacy la hace ProtectAdminPaths (que reutiliza este `challenge`).
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
     * Valida la credencial administrativa. Devuelve la respuesta de rechazo
     * (401/403) o null si es válida. Como efecto, cuando autentica por sesión
     * real deja el admin en los atributos del request. Reutilizable por el guard
     * global de rutas /api/admin/* y pagos legacy (ProtectAdminPaths).
     */
    public static function challenge(Request $request): ?Response
    {
        $token = $request->bearerToken();

        // El navegador NO puede enviar la cabecera Authorization en recursos que
        // carga directo: SSE (`EventSource`) e imágenes (`<img src>`). Para esas
        // rutas se admite un VALE por query (`?ticket=`), nunca el token de
        // sesión.
        //
        // Antes se aceptaba `?token=` con el token real, y nginx registra la
        // línea de petición entera: quedaban en claro en access.log y en sus
        // rotaciones, y quien pudiera leerlos tenía sesión de administrador.
        // El vale caduca en minutos y sólo abre streams, así que filtrarlo ya
        // no entrega la sesión. Ver StreamTicketService.
        if (! $token && ($request->is('*/stream') || $request->is('*/face-image/*'))) {
            $ticket = $request->query('ticket');
            if (is_string($ticket) && $ticket !== '') {
                $session = app(StreamTicketService::class)->resolve($ticket);
                $admin = $session?->admin;
                if ($session && $admin instanceof Admin && $admin->isActive()) {
                    $request->attributes->set('auth_admin', $admin);
                    $request->attributes->set('auth_admin_session', $session);

                    return null;
                }

                return self::deny($request, 'admin_ticket_invalid', 'Vale de stream inválido o caducado.', 403);
            }
        }

        if (! $token) {
            return self::deny($request, 'admin_token_required', 'Token administrativo requerido.', 401);
        }

        // 1) Sesión admin real (login del CRM).
        $sessions = app(AdminSessionService::class);
        $session = $sessions->resolveByToken($token);
        if ($session) {
            $admin = $session->admin;
            if ($admin instanceof Admin && $admin->isActive()) {
                $sessions->touch($session);
                $request->attributes->set('auth_admin', $admin);
                $request->attributes->set('auth_admin_session', $session);

                return null;
            }
            // Sesión de un admin deshabilitado/eliminado: se trata como inválida.
        }

        // 2) Fallback: secreto compartido (n8n / automatizaciones internas).
        $expected = config('admin.api_token');
        if (is_string($expected) && $expected !== '' && hash_equals($expected, $token)) {
            return null;
        }

        return self::deny($request, 'admin_token_invalid', 'Token administrativo inválido.', 403);
    }

    private static function deny(Request $request, string $code, string $message, int $status): Response
    {
        Log::info('auth:admin:failed', [
            'reason' => $code,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
