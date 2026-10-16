<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El CRM antiguo en Blade (`/crm/*`) queda APAGADO salvo que se encienda a
 * propósito con LEGACY_WEB_CRM_ENABLED=true.
 *
 * POR QUÉ. Esas rutas viven en el grupo `web` sin ninguna autenticación: el
 * CSRF no protege nada cuando quien ataca es el propio cliente (pide el
 * formulario, obtiene el token y la cookie, y escribe). Desde internet se podía
 * leer el listado de entrenadores y rutinas, crear entrenadores con identidad y
 * roles del portal, y asignar o desasignar socios. El CRM de verdad es el de
 * Angular contra `api/*`; en los registros de nginx no había uso legítimo de
 * `/crm/*` (revisión de seguridad del 16-10-2026).
 *
 * Se apaga con un middleware en vez de borrar las rutas para que el cambio sea
 * reversible con una variable y para que funcione igual con la caché de rutas.
 * Responde 404, como si no existieran: no anuncia un módulo que nadie debe usar.
 */
class EnsureLegacyWebCrmEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('app.legacy_web_crm', false)) {
            abort(404);
        }

        return $next($request);
    }
}
