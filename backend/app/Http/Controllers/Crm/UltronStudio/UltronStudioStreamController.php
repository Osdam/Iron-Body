<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Controller;
use App\Services\UltronStudio\Stream\StreamCountable;
use App\Services\UltronStudio\Stream\StreamCursors;
use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\Stream\StreamPacer;
use App\Services\UltronStudio\Stream\StreamRateLimit;
use App\Services\UltronStudio\Stream\StreamTopic;
use App\Services\UltronStudio\Stream\StreamTopics;
use App\Services\UltronStudio\Stream\StreamViewerGate;
use App\Services\UltronStudio\Stream\SystemStreamClock;
use App\Services\UltronStudio\StudioAccess;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * El CANAL SSE ÚNICO de ULTRON STUDIO (Hito 9, paquete SSE9).
 *
 *   GET /api/admin/ultron-studio/stream?ticket=<vale>&topics=a,b[&after=a:1,b:2]
 *
 * Cerraduras, en orden: la puerta (`ultron.view`, AuthorizationMap) → una
 * PERSONA ({@see StudioAccess::requirePerson()}: el token compartido no abre
 * streams y el vale resuelve a una sesión) → la BANDERA (409
 * `stream_disabled`; se comprueba después de la puerta para no revelarla a
 * quien no entra) → los TEMAS: cada uno exige además el permiso del GET de
 * lista de su módulo; el que no se puede leer se omite en silencio y se anota
 * en el log de la aplicación, nunca en una tabla (el canal no escribe).
 *
 * Un evento por fila nueva de cada tema: `event: <tema>`, `data: {type, id,
 * state, at[, hash|ref]}` ({@see StreamEvent}: lista blanca, sin datos
 * personales) e `id: <vector de cursores>` ({@see StreamCursors}). Al abrir,
 * `event: ready` con los cursores de partida: `resync: true` si algún tema
 * arranca en la cabeza o con un cursor recortado (el cliente relee las API
 * canónicas), `resync: false` cuando se honraron exactamente los cursores que
 * trajo (una reconexión limpia no obliga a releer). Un commit tardío bajo el
 * cursor de un tema por id ({@see StreamCountable}) se detecta por recuento
 * en cada sondeo y manda un `ready` con `resync: true` solo para ese tema.
 * Latido `: ping` cada 15 s, cierre a los 55 s ({@see StreamPacer}); el
 * cliente reconecta con `?after=` (el navegador no manda `Last-Event-ID` en
 * una conexión nueva).
 *
 * Vive en routes/ultron-studio.php, en su bloque, con los demás controladores
 * del Studio (L9-43): el vale (`?ticket=`) abre rutas que terminan en
 * `/stream`, y esta lo es. Su entrada en AuthorizationMap (`ultron.view`) y la
 * variable del ejemplo de entorno van en la rama del Hito 9 (ver el informe).
 *
 * Limitador con nombre `studio-stream` ({@see StreamRateLimit}), por tique:
 * se declara aquí como middleware del controlador y se registra al
 * declararlo, así vale también con las rutas en caché.
 */
class UltronStudioStreamController extends Controller implements HasMiddleware
{
    /** Las rutas de este paquete (L9-38): las guardas que suman el total del Studio las añaden en la rama del Hito 9. */
    public const ROUTE_COUNT = 1;

    public const CODE_DISABLED = 'stream_disabled';

    public const CODE_NO_TOPICS = 'stream_no_topics';

    /** Aviso dentro del canal (no es un tema): un tema dejó de poder leerse en esta conexión. */
    public const NOTICE_EVENT = 'stream.notice';

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        StreamRateLimit::register();

        return [new Middleware('throttle:'.StreamRateLimit::NAME)];
    }

    public function stream(Request $request): Response
    {
        $access = StudioAccess::requirePerson($request);

        if (! self::enabled()) {
            return response()->json([
                'ok' => false,
                'code' => self::CODE_DISABLED,
                'message' => 'El canal en tiempo real del Studio está apagado en el servidor. El panel sigue consultando las API.',
            ], 409);
        }

        $registry = app()->bound(StreamTopics::class) ? app(StreamTopics::class) : StreamTopics::for($access);
        $requested = $this->requestedTopics($request, $registry);

        // Los temas que esta persona puede leer. El resto se omite y se anota.
        /** @var array<string, StreamTopic> $active */
        $active = [];
        foreach ($requested as $name) {
            $topic = $registry->get($name);
            $permitido = $access->can($topic->permission())
                && (! $topic instanceof StreamViewerGate || $topic->readableBy($access));
            if ($permitido) {
                $active[$name] = $topic;
            } else {
                Log::notice('ultron_studio.stream_topic_denied', ['admin_id' => $access->id(), 'topic' => $name]);
            }
        }
        if ($active === []) {
            return response()->json([
                'ok' => false,
                'code' => self::CODE_NO_TOPICS,
                'message' => 'Ningún tema del canal está disponible para tu sesión.',
            ], 403);
        }

        [$cursors, $honored] = $this->startingCursors($request, $active);
        $limit = max(1, (int) config('ultron_studio_stream.batch_limit', 50));

        // Lo visible hasta el cursor en cada tema que sabe contar: el oído de
        // los commits tardíos (ids reservados antes del commit).
        $visible = [];
        foreach ($active as $name => $topic) {
            if ($topic instanceof StreamCountable) {
                $visible[$name] = $topic->countThrough($cursors[$name]);
            }
        }

        return $this->pacer()->response(
            function () use (&$cursors, $honored): void {
                SseStream::emit('ready', ['topics' => (object) $cursors, 'resync' => ! $honored], StreamCursors::format($cursors));
            },
            function () use (&$active, &$cursors, &$visible, $limit): void {
                foreach ($active as $name => $topic) {
                    try {
                        if ($topic instanceof StreamCountable) {
                            // Una fila nueva BAJO el cursor: un commit que llegó tarde. El panel relee su lista.
                            $count = $topic->countThrough($cursors[$name]);
                            if ($count !== $visible[$name]) {
                                SseStream::emit('ready', ['topics' => (object) [$name => $cursors[$name]], 'resync' => true], StreamCursors::format($cursors));
                            }
                        }
                        $events = $topic->since($cursors[$name], $limit);
                        if ($topic instanceof StreamCountable) {
                            // No se vuelve a contar aquí: taparía un commit tardío ocurrido durante este sondeo.
                            $visible[$name] = $count + count($events);
                        }
                    } catch (Throwable $e) {
                        // Un tema que no se puede leer no tumba el canal: se anota,
                        // se avisa al cliente y se deja de sondear en esta conexión.
                        Log::warning('ultron_studio.stream_topic_failed', ['topic' => $name, 'error_class' => class_basename($e)]);
                        unset($active[$name]);
                        SseStream::emit(self::NOTICE_EVENT, [
                            'type' => 'topic_unavailable', 'id' => $name, 'state' => null, 'at' => now()->utc()->toIso8601String(),
                        ], StreamCursors::format($cursors));

                        continue;
                    }
                    foreach ($events as $event) {
                        $cursors[$name] = $event->cursor;
                        SseStream::emit($name, $event->data, StreamCursors::format($cursors));
                    }
                }
            },
        );
    }

    /** Solo `true`/1/on/yes encienden (config/ultron_studio_stream.php). */
    public static function enabled(): bool
    {
        return config('ultron_studio_stream.enabled') === true;
    }

    /**
     * `topics=a,b,c`: obligatorio, sin repetidos, solo nombres del registro.
     *
     * @return list<string>
     *
     * @throws ValidationException 422
     */
    private function requestedTopics(Request $request, StreamTopics $registry): array
    {
        $data = $request->validate([
            'topics' => ['required', 'string', 'max:256'],
            'after' => ['sometimes', 'nullable', 'string', 'max:'.StreamCursors::MAX_LENGTH],
        ]);

        $names = [];
        foreach (explode(',', $data['topics']) as $name) {
            $name = trim($name);
            if (preg_match(StreamCursors::TOPIC, $name) !== 1 || ! $registry->has($name)) {
                throw ValidationException::withMessages(['topics' => ['Tema desconocido: '.mb_substr($name, 0, 40)]]);
            }
            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Dónde empieza cada tema activo: lo que el cliente trae (`?after=` manda
     * sobre `Last-Event-ID`) o la cabeza actual. Un cursor numérico por
     * delante de la cabeza se recorta a ella (como el canal de membresías):
     * uno inventado no dejaría al cliente sin eventos hasta alcanzarlo.
     * Devuelve también si se HONRARON exactamente todos los cursores del
     * cliente (ninguno arrancó en la cabeza ni fue recortado).
     *
     * @param  array<string, StreamTopic>  $active
     * @return array{0: array<string, string>, 1: bool}
     *
     * @throws ValidationException 422 si `?after=` no tiene la forma del vector
     */
    private function startingCursors(Request $request, array $active): array
    {
        $given = [];
        $after = $request->query('after');
        if (is_string($after) && $after !== '') {
            try {
                $given = StreamCursors::parse($after);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['after' => ['El vector de cursores no tiene la forma tema:cursor,tema:cursor.']]);
            }
        } else {
            $header = $request->header('Last-Event-ID');
            if (is_string($header) && $header !== '') {
                try {
                    $given = StreamCursors::parse($header);
                } catch (InvalidArgumentException) {
                    // El navegador devuelve lo que se le dio; algo distinto se ignora.
                    $given = [];
                }
            }
        }

        $cursors = [];
        $honored = true;
        foreach ($active as $name => $topic) {
            $head = $topic->cursor();
            if (! isset($given[$name])) {
                $cursors[$name] = $head;
                $honored = false;

                continue;
            }
            $cursor = $given[$name];
            if (ctype_digit($cursor) && ctype_digit($head) && strlen($cursor) <= 18 && strlen($head) <= 18 && (int) $cursor > (int) $head) {
                $cursor = $head;
                $honored = false;
            }
            $cursors[$name] = $cursor;
        }

        return [$cursors, $honored];
    }

    /** El compás real, salvo que una prueba haya dejado otro en el contenedor. */
    private function pacer(): StreamPacer
    {
        return app()->bound(StreamPacer::class) ? app(StreamPacer::class) : StreamPacer::fromConfig(new SystemStreamClock);
    }
}
