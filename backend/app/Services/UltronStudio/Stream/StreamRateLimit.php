<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\Admin\AdminSessionService;
use App\Support\Access\AdminActor;
use App\Support\Network\ClientNetwork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * El limitador CON NOMBRE del canal, `studio-stream` (contrato H9, «Paquete
 * SSE9»: «límite por tique, como los de voz»).
 *
 * Cubo por TIQUE cuando la conexión se abre con el vale (`?ticket=`, la forma
 * normal desde el navegador), identificado por una huella del vale, nunca por
 * el vale en claro; por PERSONA cuando llega con la cabecera `Authorization`
 * (pruebas, herramientas); y un cubo propio por red para lo que no trae
 * credencial resoluble (en la práctica la puerta ya lo rechazó antes). Una
 * pestaña abre una conexión cada 55 s y reconecta con retroceso: el tope
 * (`ultron_studio_stream.rate.per_minute`, 30) deja sitio a varias pestañas
 * y corta un bucle de reconexión roto.
 *
 * Se registra desde el propio controlador ({@see register()}, idempotente)
 * y no desde AppServiceProvider como los de la voz: ese fichero no es del
 * paquete SSE9 y lo tocan otros; mover la llamada allí es una línea y deja
 * esta en vacío.
 */
final class StreamRateLimit
{
    public const NAME = 'studio-stream';

    public static function register(): void
    {
        if (RateLimiter::limiter(self::NAME) !== null) {
            return;
        }

        RateLimiter::for(self::NAME, function (Request $request) {
            $clave = self::key($request);
            $tope = str_starts_with($clave, self::NAME.':anon:')
                ? (int) config('ultron_studio_stream.rate.anonymous_per_minute', 10)
                : (int) config('ultron_studio_stream.rate.per_minute', 30);

            return Limit::perMinute(max(1, $tope))->by($clave)->response(fn ($request, array $headers) => response()->json([
                'ok' => false,
                'code' => 'too_many_requests',
                'message' => 'Demasiadas conexiones seguidas al canal del Studio. El panel reintenta solo.',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers));
        });
    }

    /** El cubo: huella del vale, o la persona, o la red. */
    public static function key(Request $request): string
    {
        $ticket = $request->query('ticket');
        if (is_string($ticket) && $ticket !== '') {
            return self::NAME.':ticket:'.substr(hash('sha256', $ticket), 0, 32);
        }

        $admin = AdminActor::from($request);
        $id = $admin?->id;
        if ($id === null && ($token = $request->bearerToken())) {
            $id = app(AdminSessionService::class)->resolveByToken($token)?->admin_id;
        }
        if ($id !== null) {
            return self::NAME.':admin:'.(int) $id;
        }

        return self::NAME.':anon:'.ClientNetwork::of($request->ip());
    }
}
