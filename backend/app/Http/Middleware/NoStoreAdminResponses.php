<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Cache-Control: no-store, private` en TODAS las respuestas de la API
 * administrativa (`/api/admin` y `/api/admin/*`): JSON, descargas, flujos SSE,
 * errores y rechazos (contrato del Hito 7 de ULTRON STUDIO, paquete B7-a).
 *
 * Por qué: el CRM se usa en puestos compartidos y por esta API pasan datos de
 * socios, la caja, las lecturas del motor de ULTRON y el libro de auditoría con
 * nombres del personal. Sin cabecera, Symfony responde `no-cache, private`,
 * que deja guardar la respuesta en el disco del navegador (solo obliga a
 * revalidarla), y `response()->download()` llega a responder `public`.
 * `no-store` prohíbe guardarla en cualquier caché.
 *
 * Va GLOBAL y al principio de la pila (bootstrap/app.php, `prepend`), y no en
 * el grupo `api`, porque un middleware de grupo no ve las respuestas que se
 * emiten antes del enrutado: 404 y 405 de rutas inexistentes, el 413 del tope
 * de cuerpo y de `post_max_size`, el modo mantenimiento y la verificación
 * previa de CORS. Al ser la capa más externa, su cabecera es la última que se
 * escribe: sustituye a la que hubiera puesto un controlador (`no-cache` de
 * los SSE, `public` de una descarga).
 *
 * Fuera de `/api/admin` no toca nada: la app móvil, el CRM legado, `/up` y los
 * ficheros estáticos (estos los sirve nginx sin pasar por PHP) conservan sus
 * cabeceras. Sin `Pragma`: el proyecto no la usa en ninguna respuesta.
 */
class NoStoreAdminResponses
{
    public const CACHE_CONTROL = 'no-store, private';

    public function handle(Request $request, Closure $next): Response
    {
        $administrativa = $request->is('api/admin', 'api/admin/*');

        $response = $next($request);

        if ($administrativa) {
            $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        }

        return $response;
    }
}
