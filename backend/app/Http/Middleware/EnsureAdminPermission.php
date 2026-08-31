<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Access\CrmPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza una operación del CRM por PERMISO (no por rol).
 *
 * Gemelo administrativo de {@see EnsureTrainerPermission}. Va DESPUÉS del
 * blindaje de credencial (ProtectAdminPaths para /api/admin/*, o el alias
 * `auth.admin`), que es quien deja el admin autenticado en `auth_admin`.
 * Centraliza la comprobación: nada de `role == 'Super Admin'` disperso por los
 * controladores.
 *
 * Uso en rutas: `->middleware('admin.can:caja.sell')`.
 *
 * Responde 403 con `required_permission`, el mismo contrato que ya usa el
 * módulo de moderación, para que el CRM pueda explicar qué permiso falta en vez
 * de mostrar un error genérico.
 *
 * Ausencia de `auth_admin` NO es un 401 aquí: significa que se entró con el
 * token compartido de automatizaciones, que sí es una credencial válida.
 * {@see CrmPermission::forAdmin()} le concede solo lectura, así que una
 * automatización nunca cobra ni mueve existencias.
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->attributes->get('auth_admin');
        $admin = $admin instanceof Admin ? $admin : null;

        if (CrmPermission::allows($admin, $permission)) {
            return $next($request);
        }

        // Un intento de escribir sin permiso es señal de seguridad, no ruido:
        // queda registrado con quién, qué y desde dónde.
        Log::warning('auth:admin:forbidden', [
            'required_permission' => $permission,
            'admin_id' => $admin?->id,
            'admin_role' => $admin?->role ?? 'shared_token',
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'No tienes permiso para esta acción.',
            'required_permission' => $permission,
        ], 403);
    }
}
