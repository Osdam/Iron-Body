<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use App\Models\MemberBiometric;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Vale de corta vida para abrir un stream SSE o cargar una face-image.
 *
 * POR QUÉ EXISTE
 * --------------
 * `EventSource` y `<img src>` no pueden mandar la cabecera Authorization, así
 * que el CRM venía poniendo el token de sesión del administrador en la query
 * string. Nginx registra la línea de petición completa, de modo que esos
 * tokens quedaban en claro en access.log y en sus 13 rotaciones: 812
 * peticiones y 3 tokens distintos en un solo día. Quien pudiera leer los logs
 * —o una copia de seguridad de ellos— tenía sesión de administrador.
 *
 * El vale sustituye a ese token en la URL. Es distinto en cuatro cosas que
 * importan:
 *   1. es de UN SOLO USO ({@see redeem()}): la primera petición que lo trae lo
 *      gasta, y dos que lleguen a la vez no lo gastan dos veces;
 *   2. caduca en un minuto, no cuando el admin cierra sesión;
 *   3. tiene FINALIDAD: el de los streams no abre fotos ni al revés, y
 *      ninguno abre la API: solo las rutas EXACTAS de su finalidad y solo con
 *      GET ({@see ROUTES}, {@see scopeFor()});
 *   4. no es el token: filtrarlo no entrega la sesión.
 *
 * Antes de P9-25 (hallazgo R-1 de SEC9-a) valía 5 minutos, se podía repetir y
 * el mismo vale abría cualquier stream y las fotos biométricas: quien leyera
 * el access.log a tiempo entraba en todo eso como esa sesión. nginx escribe la
 * línea al TERMINAR la petición, así que un vale que aparece en el log ya lo
 * gastó la conexión que lo trajo; lo que queda es un vale que no llegó a PHP
 * (un 502, un 429 previo a la puerta), que caduca en ese minuto.
 *
 * Va cifrado con la clave de la app (`Crypt`, que además autentica), así que
 * no se puede fabricar uno ni alterar su caducidad o su finalidad. Al emitirlo
 * se anota en la caché; canjearlo es retirar esa anotación bajo un cerrojo.
 * Sin la anotación el vale no abre nada: si la caché se pierde, los vales
 * pendientes dejan de valer (falla cerrado) y el CRM pide otro al reconectar.
 */
class StreamTicketService
{
    /** Lo justo para pedirlo y abrir con él: el CRM pide uno por conexión y por foto. */
    public const TTL_SECONDS = 60;

    /** Los canales SSE del CRM ({@see ROUTES}). */
    public const SCOPE_STREAM = 'stream';

    /** Las fotos biométricas (`attendances/face-image/{userId}`). */
    public const SCOPE_FACE_IMAGE = 'face-image';

    public const SCOPES = [self::SCOPE_STREAM, self::SCOPE_FACE_IMAGE];

    /**
     * Las ÚNICAS rutas que admiten un vale, por finalidad: el `uri()` EXACTO de
     * la ruta de Laravel que atiende la petición, y solo con GET
     * ({@see scopeFor()}). Hasta SC-6 (revisión de seguridad del cierre) la
     * finalidad salía del CAMINO (acabar en `/stream` o contener
     * `/face-image/`) y un vale autenticaba también rutas que solo lo
     * parecían por un parámetro (`marketing/analytics/breakdown/{dimension}` con `stream`,
     * `receivables/people/{type}/{id}` o `members/{member}/identity/{side}`
     * con `face-image`): hoy sin datos, pero una ruta futura con un parámetro
     * libre quedaría abierta al vale.
     *
     * `stream`: los canales SSE que el CRM abre con `openStream()` o con
     * `withTicket()` (el canal en vivo del Studio y los demás del CRM: sin
     * cabecera `Authorization`, `EventSource` no tiene otra forma de
     * autenticarse). `face-image`: la foto biométrica que el catálogo facial
     * carga con `<img src>`. Una ruta SSE nueva entra aquí a mano;
     * `StreamTicketRouteScopeTest` compara esta lista con el enrutador.
     */
    public const ROUTES = [
        self::SCOPE_STREAM => [
            'api/admin/classes/stream',
            'api/admin/earnings/stream',
            'api/admin/exercises/stream',
            'api/admin/marketing/agent/stream',
            'api/admin/marketing/appointments/stream',
            'api/admin/memberships/stream',
            'api/admin/moderation/stream',
            'api/admin/notifications/stream',
            'api/admin/ultron-studio/stream',
            'api/admin/users/stream',
            'api/attendances/stream',
        ],
        self::SCOPE_FACE_IMAGE => [
            'api/attendances/face-image/{userId}',
        ],
    ];

    /**
     * SC-7: vales `stream` por PERSONA y minuto. Un canal SSE genérico cierra a
     * los 20 s (`SseStream`) y el CRM lo reabre con un vale nuevo: ≈ 3 por
     * minuto y canal abierto (el del Studio, ≈ 1). 600 son ≈ 200 canales a la
     * vez de la misma cuenta (varias pestañas y equipos): holgado para el uso
     * real y un techo para quien los pide en bucle.
     */
    public const STREAM_PER_MINUTE = 600;

    /**
     * SC-7: el cupo de `face-image` sigue al catálogo facial, que pide UN vale
     * POR SOCIO CON FOTO y todos a la vez (`buildReferences()` con
     * `Promise.all`): al abrir Asistencia, tras cada rostro enrolado o borrado
     * (lo avisa el canal de notificaciones) y con «Actualizar rostros», y otra
     * pantalla con la misma cuenta lo repite. Cupo = fotos ×
     * {@see FACE_IMAGE_REBUILDS_PER_MINUTE}, nunca menos de
     * {@see FACE_IMAGE_FLOOR_PER_MINUTE} ({@see perMinute()}).
     */
    public const FACE_IMAGE_REBUILDS_PER_MINUTE = 8;

    public const FACE_IMAGE_FLOOR_PER_MINUTE = 1000;

    /** La ventana del limitador. */
    public const RATE_WINDOW_SECONDS = 60;

    /**
     * El prefijo de TODO lo que el vale deja en la caché: las anotaciones
     * (`<prefijo><sha256 del jti>`), el cubo del limitador
     * (`<prefijo>rate:<finalidad>:<persona>`) y la cuenta de fotos
     * (`<prefijo>faces`). `admin:prune-stream-tickets` purga las caducadas.
     */
    public const CACHE_PREFIX = 'admin-stream-ticket:';

    /** Formato del vale. Los de antes (sin finalidad ni `jti`) ya no valen. */
    private const VERSION = 2;

    /** Cuánto se guarda la cuenta de fotos del catálogo (una consulta cada diez minutos como mucho). */
    private const FACES_COUNT_TTL_SECONDS = 600;

    /** El cerrojo cubre retirar la anotación, no la conexión. */
    private const REDEEM_LOCK_SECONDS = 10;

    /**
     * @return array{ticket: string, expires_in: int, scope: string}
     *
     * @throws InvalidArgumentException con una finalidad desconocida
     */
    public function issue(AdminSession $session, string $scope = self::SCOPE_STREAM): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Finalidad de vale desconocida.');
        }

        $jti = Str::random(40);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        Cache::put(self::cacheKey($jti), $scope, $expiresAt);

        return [
            'ticket' => Crypt::encryptString(json_encode([
                'v' => self::VERSION,
                'sid' => $session->id,
                'scope' => $scope,
                'jti' => $jti,
                'exp' => $expiresAt->getTimestamp(),
            ], JSON_THROW_ON_ERROR)),
            'expires_in' => self::TTL_SECONDS,
            'scope' => $scope,
        ];
    }

    /**
     * Canjea el vale para esta finalidad: devuelve su sesión UNA sola vez, o null.
     *
     * Devuelve null —y nunca lanza— ante cualquier problema: un vale caducado,
     * manipulado, ya usado o de otra finalidad se trata igual que no haber
     * mandado nada. El de otra finalidad no se gasta: probarlo en la ruta
     * equivocada no le quita la conexión a quien lo pidió.
     */
    public function redeem(string $ticket, string $scope): ?AdminSession
    {
        $payload = $this->payload($ticket);
        if ($payload === null || $payload['scope'] !== $scope) {
            return null;
        }

        if ($payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        if (! $this->consume($payload['jti'], $scope)) {
            return null;
        }

        return AdminSession::query()->active()->whereKey($payload['sid'])->first();
    }

    /**
     * La sesión de un vale que TODAVÍA se puede canjear (vigente y sin gastar),
     * leída sin canjearlo; null en cualquier otro caso. Solo sirve para
     * AGRUPAR: el limitador del canal corre antes que la puerta y no puede
     * gastar el vale. Un vale gastado —el que se lee en el access.log— no
     * cuenta como de su dueño, así que repetirlo no le agota el cupo. Nunca
     * autoriza nada; para eso, {@see redeem()}.
     */
    public function peekSessionId(string $ticket): ?int
    {
        $payload = $this->payload($ticket);
        if ($payload === null || $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return Cache::has(self::cacheKey($payload['jti'])) ? $payload['sid'] : null;
    }

    /**
     * El contenido del vale si se puede descifrar y tiene la forma actual.
     *
     * @return array{v: int, sid: int, scope: string, jti: string, exp: int}|null
     */
    private function payload(string $ticket): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ! is_string($payload['scope'] ?? null)
            || ! is_int($payload['sid'] ?? null)
            || ! is_int($payload['exp'] ?? null)
            || ! is_string($payload['jti'] ?? null)
            || $payload['jti'] === '') {
            return null;
        }

        return $payload;
    }

    /**
     * La finalidad que admite la RUTA de la petición, o null si no admite
     * vales. Decide la ruta que la atiende ({@see ROUTES}), nunca el camino, y
     * solo GET: `EventSource` y `<img>` no piden otra cosa, así que un HEAD, un
     * POST o un `_method` no se autentican con un vale.
     */
    public static function scopeFor(Request $request): ?string
    {
        if ($request->getRealMethod() !== 'GET' || ! $request->isMethod('GET')) {
            return null;
        }
        $uri = self::routeUri($request);
        if ($uri === null) {
            return null;
        }
        foreach (self::ROUTES as $scope => $uris) {
            if (in_array($uri, $uris, true)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * El `uri()` de la ruta que atiende la petición. Las guardas corren
     * después del enrutado (grupo `api` y alias `auth.admin`), así que la
     * ruta ya está puesta; si no lo está (quien llama a la guarda a mano, como
     * las pruebas), se resuelve con el MISMO enrutador. Sin ruta no hay vale.
     */
    private static function routeUri(Request $request): ?string
    {
        $route = $request->route();
        if ($route instanceof Route) {
            return $route->uri();
        }
        try {
            return app('router')->getRoutes()->match($request)->uri();
        } catch (HttpExceptionInterface) {
            // Tragado a propósito: un camino sin ruta (404/405) no admite vale (falla cerrado).
            return null;
        }
    }

    /**
     * Retira la anotación del vale. Solo una petición la encuentra: `pull` es
     * leer y borrar, y el cerrojo impide que dos lo hagan a la vez. Tomar el
     * cerrojo es atómico en los almacenes de la app: en `database` (el de
     * producción) es un INSERT sobre la clave primaria de `cache_locks`, y el
     * segundo choca; en `file`, `flock`; en redis, `SET NX`. El cerrojo se
     * suelta al salir y la anotación desaparece: un vale canjeado no deja fila
     * en la tabla `cache`.
     */
    private function consume(string $jti, string $scope): bool
    {
        $key = self::cacheKey($jti);

        try {
            $taken = Cache::lock($key.':redeem', self::REDEEM_LOCK_SECONDS)
                ->get(static fn () => Cache::pull($key));
        } catch (Throwable $e) {
            // Sin almacén no hay forma de saber si ya se usó: se rechaza.
            Log::warning('auth:admin:ticket_store_failed', ['error_class' => class_basename($e)]);

            return false;
        }

        return $taken === $scope;
    }

    /** La anotación se guarda por la huella del `jti`, nunca por el `jti` en claro. */
    private static function cacheKey(string $jti): string
    {
        return self::CACHE_PREFIX.hash('sha256', $jti);
    }

    /**
     * SC-7: cuántos vales de esta finalidad puede pedir UNA persona por minuto.
     *
     * @throws InvalidArgumentException con una finalidad desconocida
     */
    public function perMinute(string $scope): int
    {
        return match ($scope) {
            self::SCOPE_STREAM => self::STREAM_PER_MINUTE,
            self::SCOPE_FACE_IMAGE => max(self::FACE_IMAGE_FLOOR_PER_MINUTE, self::FACE_IMAGE_REBUILDS_PER_MINUTE * $this->enrolledFaces()),
            default => throw new InvalidArgumentException('Finalidad de vale desconocida.'),
        };
    }

    /** SC-7: el cubo del limitador, por finalidad y PERSONA (todas sus sesiones, pestañas y equipos). */
    public static function rateKey(string $scope, int $adminId): string
    {
        return self::CACHE_PREFIX.'rate:'.$scope.':'.$adminId;
    }

    /**
     * Las fotos que el catálogo facial puede pedir de golpe: una por socio con
     * fila en `member_biometrics` (es única por socio). Cuenta de más, nunca de
     * menos, frente a `AttendanceController::faceReferences()`, que además
     * exige el usuario: el cupo nunca queda por debajo del catálogo.
     */
    private function enrolledFaces(): int
    {
        return (int) Cache::remember(self::CACHE_PREFIX.'faces', self::FACES_COUNT_TTL_SECONDS, static fn (): int => MemberBiometric::query()->count());
    }
}
