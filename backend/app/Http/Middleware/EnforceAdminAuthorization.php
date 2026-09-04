<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorización GLOBAL del CRM: toda ruta administrativa exige el permiso que
 * {@see AuthorizationMap} le asigna.
 *
 * Va detrás de {@see ProtectAdminPaths}, que es quien exige credencial. Este
 * decide, con esa credencial ya validada, si esa persona puede hacer ESTO.
 *
 * FALLA CERRADO. Una ruta que el mapa no sepa resolver se deniega y se
 * registra. Es deliberado y es la diferencia con el modelo anterior: antes,
 * una ruta nueva nacía abierta a cualquier administrador y nadie se enteraba;
 * ahora nace cerrada y se nota el primer día. `auth:coverage` y su test
 * impiden que eso llegue a producción.
 */
class EnforceAdminAuthorization
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route === null || ! AuthorizationMap::isAdministrative($route)) {
            return $next($request);
        }

        $permiso = AuthorizationMap::resolve($route);

        // Puerta de entrada: el login no puede exigir sesión.
        if ($permiso === AuthorizationMap::PUBLIC) {
            return $next($request);
        }

        // La identidad puede no estar resuelta todavía: ProtectAdminPaths la
        // deja lista para /api/admin/*, pero las rutas del CRM fuera de ese
        // prefijo usan el alias `auth.admin`, que es middleware DE RUTA y por
        // tanto corre DESPUÉS de este. Se resuelve aquí con el mismo resolutor
        // —no con otro— para que la puerta funcione sin depender del orden.
        if (! $request->attributes->get('auth_admin') instanceof Admin) {
            if ($rechazo = EnsureAdminAuth::challenge($request)) {
                return $rechazo;
            }
        }

        $admin = $request->attributes->get('auth_admin');
        $admin = $admin instanceof Admin ? $admin : null;

        // Acciones sobre uno mismo: basta la sesión que ProtectAdminPaths ya
        // validó. El token compartido de automatizaciones no es nadie, así que
        // tampoco pasa por aquí.
        if ($permiso === AuthorizationMap::SELF) {
            return $admin !== null ? $next($request) : $this->deny($request, null, 'sesión de administrador');
        }

        // Permiso que depende de los DATOS, no de la URL: los turnos de caja,
        // donde `cash.products` y `cash.gym` son permisos distintos y el tipo
        // no se conoce hasta cargar el turno. Aquí se exige lo mismo que en
        // cualquier otra ruta —una sesión de administrador— y la comprobación
        // fina la hace el controlador, que deniega por defecto.
        //
        // Esto NO es una puerta abierta: el centinela solo llega hasta aquí si
        // alguien lo escribió en OVERRIDES para una ruta concreta, y esa ruta
        // tiene que estar además en CONTROLLER_RESOLVED, que una prueba
        // compara contra lo que el mapa resuelve de verdad.
        if ($permiso === AuthorizationMap::CONTROLLER) {
            return $admin !== null ? $next($request) : $this->deny($request, null, 'sesión de administrador');
        }

        if ($permiso === null) {
            Log::error('auth:admin:unmapped_route', [
                'route' => AuthorizationMap::routeKey($route),
                'admin_id' => $admin?->id,
                'ip' => $request->ip(),
            ]);

            return $this->deny($request, null, 'ruta sin clasificar');
        }

        if (CrmPermission::allows($admin, $permiso)) {
            return $next($request);
        }

        return $this->deny($request, $permiso);
    }

    private function deny(Request $request, ?string $permiso, ?string $motivo = null): Response
    {
        // Un intento de actuar sin permiso es señal de seguridad, no ruido.
        Log::warning('auth:admin:forbidden', [
            'required_permission' => $permiso,
            'reason' => $motivo,
            'route' => $request->route() ? AuthorizationMap::routeKey($request->route()) : $request->path(),
            'admin_id' => $request->attributes->get('auth_admin')?->id,
            'ip' => $request->ip(),
        ]);

        return response()->json(array_filter([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'No tienes permiso para esta acción.',
            'required_permission' => $permiso,
        ], fn ($v) => $v !== null), 403);
    }
}
