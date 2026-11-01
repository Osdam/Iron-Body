<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;

/**
 * El tope de cuerpo de ULTRON STUDIO, ANTES de los middleware globales que
 * recorren el cuerpo (contrato del Hito 7, paquete B7-d; pendiente desde la
 * Enmienda 2 del contrato G).
 *
 * El grupo de rutas del Studio ya tiene su tope de 64 KB
 * (`LimitRequestBodySize::class.':65536'` en routes/ultron-studio.php), pero un
 * middleware de ruta corre DESPUÉS de los globales, y dos de ellos recorren el
 * cuerpo entero: `TrimStrings` pasa una expresión regular por cada texto del
 * JSON y `ConvertEmptyStringsToNull` lo vuelve a recorrer. Medido en local
 * (informe del backend del Hito 7): un cuerpo de 8 MB con 1,4 millones de
 * textos cortos tardaba 2,7–3,3 s en llegar al 413; con este tope, 43–46 ms.
 *
 * LO QUE NO EVITA: `Request::capture()` (public/index.php) decodifica el JSON
 * entero antes de cualquier middleware, así que esa decodificación (unos
 * 40 ms y 75 MB de memoria con ese cuerpo) sigue ahí. Solo la evita un tope
 * delante de PHP (nginx), que es decisión de operación.
 *
 * DÓNDE VA. Laravel no deja insertar un middleware global en medio de la pila,
 * así que este ocupa el hueco de `ValidatePostSize` (bootstrap/app.php,
 * `replace`) y, si no rechaza, delega en él tal cual. Así queda:
 *  - DETRÁS de `HandleCors`: el CRM vive en otro origen (ironbodyneiva.cloud
 *    frente a api.ironbodyneiva.cloud) y sin las cabeceras de CORS no podría
 *    leer el 413; las pone `HandleCors` al volver la respuesta;
 *  - detrás del modo mantenimiento, que sigue respondiendo 503 a todo;
 *  - DELANTE de `TrimStrings` y `ConvertEmptyStringsToNull`.
 *
 * QUÉ MIDE, como `LimitRequestBodySize`: la cabecera `Content-Length` si llega
 * y, siempre, el cuerpo real (con `Transfer-Encoding: chunked` no hay
 * cabecera, y una cabecera puede no decir la verdad). Un JSON ya está en
 * memoria (lo leyó `Request::capture()`) y se mide sin copiarlo; cualquier
 * otro cuerpo se lee del flujo con tope, como mucho 64 KB + 1 bytes. Symfony
 * vuelve a leer `php://input` desde el principio cuando alguien lo pide, así
 * que leerlo aquí no le quita nada a nadie.
 *
 * QUÉ RESPONDE: el mismo 413 que el tope de la ruta, byte a byte (lo comprueba
 * una prueba), también sin sesión: el tamaño se mira antes que la credencial.
 * El tope de la ruta se queda como segunda capa.
 *
 * Fuera de `/api/admin/ultron-studio` no cambia nada: es `ValidatePostSize`.
 */
class ValidatePostSizeWithStudioCap extends ValidatePostSize
{
    /** El tope del Studio, en bytes: el mismo que el de su grupo de rutas (una prueba los ata). */
    public const STUDIO_MAX_BYTES = 65536;

    /** Las rutas del Studio: todo lo que cuelga de su prefijo, exista la ruta o no. */
    public const STUDIO_PATHS = ['api/admin/ultron-studio', 'api/admin/ultron-studio/*'];

    /**
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->is(...self::STUDIO_PATHS) && self::exceeds($request, self::STUDIO_MAX_BYTES)) {
            return response()->json([
                'ok' => false,
                'code' => 'payload_too_large',
                'message' => sprintf('La petición supera el tamaño máximo (%d KB).', intdiv(self::STUDIO_MAX_BYTES, 1024)),
            ], 413);
        }

        return parent::handle($request, $next);
    }

    /** ¿Supera el cuerpo `$tope` bytes, según la cabecera o según lo que llega? Del flujo nunca lee más de `$tope` + 1. */
    private static function exceeds(Request $request, int $tope): bool
    {
        $declarado = $request->headers->get('Content-Length');
        if (is_string($declarado) && ctype_digit($declarado) && (int) $declarado > $tope) {
            return true;
        }

        if ($request->isJson()) {
            return strlen($request->getContent()) > $tope;
        }

        $leido = stream_get_contents($request->getContent(true), $tope + 1);

        return is_string($leido) && strlen($leido) > $tope;
    }
}
