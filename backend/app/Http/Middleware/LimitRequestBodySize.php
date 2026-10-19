<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tope de tamaño del CUERPO de la petición para un grupo de rutas cuyo cuerpo
 * legítimo es pequeño y conocido. Por encima: 413 JSON
 * `{ok:false, code:'payload_too_large', message}`, sin llegar al controlador ni
 * a la validación.
 *
 * Es defensa en profundidad: los topes globales (`post_max_size` de PHP, 8 MB
 * en producción, y `client_max_body_size` de nginx, 10 MB) están pensados para
 * subir ficheros, no para un JSON de configuración, y dejan pasar cuerpos de
 * cientos de KB cuya validación cuesta segundos de CPU en un pool de cinco
 * workers (revisión de seguridad de la API del Hito G de ULTRON STUDIO, M-2).
 *
 * Mide la cabecera `Content-Length` si llega y, siempre, la longitud real del
 * cuerpo: con `Transfer-Encoding: chunked` no hay cabecera. El cuerpo ya lo
 * leyó Laravel al decodificar el JSON, así que medirlo no cuesta nada.
 *
 * Uso: `->middleware(LimitRequestBodySize::class.':65536')` (bytes).
 */
class LimitRequestBodySize
{
    public function handle(Request $request, Closure $next, string $maxBytes): Response
    {
        $tope = ctype_digit($maxBytes) ? (int) $maxBytes : 0;
        if ($tope <= 0) {
            // Un tope mal escrito en la ruta es un fallo de código: mejor un 500
            // ruidoso que dejar la ruta sin tope o rechazarlo todo en silencio.
            throw new InvalidArgumentException('LimitRequestBodySize necesita un tope en bytes, entero y positivo.');
        }

        $declarado = $request->headers->get('Content-Length');
        $declaradoExcede = is_string($declarado) && ctype_digit($declarado) && (int) $declarado > $tope;
        if ($declaradoExcede || strlen((string) $request->getContent()) > $tope) {
            return response()->json([
                'ok' => false,
                'code' => 'payload_too_large',
                'message' => sprintf('La petición supera el tamaño máximo (%d KB).', intdiv($tope, 1024)),
            ], 413);
        }

        return $next($request);
    }
}
