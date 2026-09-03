<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra el cuerpo de las asistencias que el servidor rechaza.
 *
 * DIAGNÓSTICO TEMPORAL. Se retira en cuanto se identifique al productor.
 *
 * El lector facial del gimnasio lleva desde el 1 de septiembre reintentando el
 * mismo POST cada 15 segundos, y el servidor responde 422 en todos: unas once
 * mil peticiones y ni una asistencia registrada. Por el tamaño de la respuesta
 * —76 bytes, que solo produce `validation.integer`— se sabe que `user_id` no
 * llega como entero, pero no CON QUÉ llega, y sin eso no se puede arreglar el
 * cliente ni distinguir un fallo suyo de un dato corrupto.
 *
 * Nginx no guarda cuerpos y Laravel no registra los 422 porque son excepciones
 * manejadas, así que este es el único punto donde el dato existe.
 *
 * Tres cosas que este middleware NO hace, a propósito:
 *
 *   · No altera la respuesta. Envuelve todo en try/catch: un fallo al registrar
 *     no puede convertir un 422 en un 500. Diagnosticar no puede romper.
 *   · No guarda valores completos. Solo tipo y un recorte, porque el cliente
 *     podría estar mandando una imagen en base64 y llenaría el disco.
 *   · No guarda nada que huela a credencial. La lista de campos a ocultar es
 *     explícita, no una heurística.
 */
class LogAttendanceRejection
{
    /** Campos cuyo valor no se registra jamás, ni recortado. */
    private const SECRETOS = ['token', 'password', 'secret', 'api_key', 'authorization', 'image', 'photo', 'face', 'embedding', 'descriptor'];

    /** Un recorte basta para ver la forma del dato; el valor entero no aporta. */
    private const RECORTE = 80;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 422) {
            try {
                Log::channel('attendance')->warning('POST /api/attendances rechazado', [
                    'ip' => $request->ip(),
                    'content_type' => $request->headers->get('content-type'),
                    'user_agent' => $request->userAgent() ?: '(ninguno)',
                    'campos' => $this->describir($request->all()),
                    'errores' => $this->errores($response),
                ]);
            } catch (Throwable $e) {
                // Silencio deliberado y acotado: este middleware existe para
                // observar, y un observador que rompe lo observado no sirve.
            }
        }

        return $response;
    }

    /**
     * Qué llegó en cada campo: su tipo y una muestra.
     *
     * El tipo es lo que importa aquí — la regla que falla es `integer`, así que
     * saber si viene `"None"`, `774.0` o `["774"]` es exactamente el dato que
     * distingue un bug del cliente de una corrupción de su base local.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, string>
     */
    private function describir(array $entrada): array
    {
        $salida = [];

        foreach ($entrada as $campo => $valor) {
            $tipo = get_debug_type($valor);

            if ($this->esSecreto($campo)) {
                $salida[$campo] = "{$tipo} (oculto)";

                continue;
            }

            $muestra = is_scalar($valor) || $valor === null
                ? var_export($valor, true)
                : json_encode($valor, JSON_UNESCAPED_UNICODE);

            $muestra = (string) $muestra;
            if (mb_strlen($muestra) > self::RECORTE) {
                $muestra = mb_substr($muestra, 0, self::RECORTE).'…';
            }

            $salida[$campo] = "{$tipo}: {$muestra}";
        }

        return $salida;
    }

    private function esSecreto(string $campo): bool
    {
        $campo = mb_strtolower($campo);

        foreach (self::SECRETOS as $prohibido) {
            if (str_contains($campo, $prohibido)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los errores que devolvió el validador, para correlacionarlos con el dato.
     *
     * @return array<string, mixed>
     */
    private function errores(Response $response): array
    {
        $cuerpo = json_decode((string) $response->getContent(), true);

        return is_array($cuerpo) && isset($cuerpo['errors']) && is_array($cuerpo['errors'])
            ? $cuerpo['errors']
            : ['(sin detalle)' => $response->getStatusCode()];
    }
}
