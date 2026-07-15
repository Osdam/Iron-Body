<?php

namespace App\Http\Middleware;

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
            'X-Content-Type-Options'            => 'nosniff',
            'X-Frame-Options'                   => 'SAMEORIGIN',
            'Referrer-Policy'                   => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
