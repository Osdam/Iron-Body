<?php

namespace App\Http\Middleware;

use App\Services\Admin\StreamTicketService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de base para todas las respuestas (BACK-014).
 *
 * Se limita a cabeceras seguras y universales que no dependen del contenido:
 *   - X-Content-Type-Options: nosniff  → evita MIME sniffing de respuestas/archivos.
 *   - X-Frame-Options: SAMEORIGIN      → mitiga clickjacking del CRM.
 *   - Referrer-Policy                  → no filtra URLs internas al navegar afuera.
 *   - X-Permitted-Cross-Domain-Policies: none.
 *   - Con un vale en la URL (streams y fotos): `no-store` y `no-referrer`.
 *
 * NO fija CSP (requiere inventariar los orígenes del CRM Blade) ni HSTS (se
 * gestiona en el proxy/nginx sobre HTTPS); esos se dejan como endurecimiento
 * posterior para no romper el front en producción.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Petición con un vale en la URL (`?ticket=` en una ruta que lo admite,
        // ver StreamTicketService): la respuesta no se guarda en ninguna caché
        // —la del navegador usa la URL, con el vale dentro, como clave, y la
        // foto biométrica pedía una hora— y lo que se abra desde ella no manda
        // la URL en `Referer` (P9-25). Sustituye lo que pusiera la ruta.
        if (self::carriesTicket($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    private static function carriesTicket(Request $request): bool
    {
        $ticket = $request->query('ticket');

        return is_string($ticket) && $ticket !== '' && StreamTicketService::scopeFor($request) !== null;
    }
}
