<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard GLOBAL del grupo `api`: exige el secreto administrativo en TODAS las
 * rutas /api/admin/* y los pagos legacy (/api/payments), presentes y futuras,
 * sin depender de envolver a mano cada bloque (eran ~22 dispersos).
 *
 * Reutiliza EnsureAdminAuth::challenge() para no duplicar la validación. Solo
 * actúa sobre esas rutas; el resto del tráfico (app, iron-ai, webhooks, wompi)
 * pasa intacto y conserva su propia auth (auth.member / firma de webhook).
 *
 * Las rutas CRM fuera del prefijo /admin (dashboard, users, reports, etc.) NO
 * se cubren aquí —comparten prefijo con rutas auth.member (classes/*, trainers/*)
 * y se blindan con el alias `auth.admin` por ruta para no colisionar.
 */
class ProtectAdminPaths
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requiresAdminAuth($request) && $response = EnsureAdminAuth::challenge($request)) {
            return $response;
        }

        return $next($request);
    }

    /**
     * ¿La petición apunta a /api/admin/* o a los pagos legacy? Los pagos in-app
     * del miembro (payments/wompi/*) se excluyen: ya están protegidos por
     * auth.member y NO deben exigir el secreto del CRM.
     */
    private function requiresAdminAuth(Request $request): bool
    {
        // El login del CRM (email+contraseña) debe ser PÚBLICO: vive bajo
        // /api/admin/* pero es la puerta para obtener el token. `me`/`logout`
        // sí exigen sesión (las protege el alias `auth.admin` por ruta).
        if ($request->is('api/admin/auth/login')) {
            return false;
        }

        if ($request->is('api/admin', 'api/admin/*')) {
            return true;
        }

        if ($request->is('api/payments/wompi', 'api/payments/wompi/*')) {
            return false;
        }

        return $request->is('api/payments', 'api/payments/*');
    }
}
