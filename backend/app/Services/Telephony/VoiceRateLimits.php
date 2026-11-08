<?php

namespace App\Services\Telephony;

use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * LOS LÍMITES DE LAS CUATRO RUTAS PÚBLICAS de la voz (contrato H8, D8-16;
 * Enmienda 5, E5-4, hallazgo M-1 de SEC8). Nada se limita por IP ANTES de
 * autenticar: ElevenLabs llama desde dos IP que comparten todos sus clientes,
 * y con el `throttle:N,1` de antes las cuatro rutas compartían un contador
 * `sha1(dominio|IP)`, así que otro cliente podía dejar nuestra iniciación en
 * 429. Ahora, DESPUÉS de comprobar la credencial:
 *
 *  - una petición AUTENTICADA cuenta en el cubo de su ruta y su llamada
 *    ({@see authenticated()}): la iniciación por `call_sid`, el post-llamada
 *    por evento (tipo + conversación), las herramientas por llamada (además
 *    del tope de 20 de la base, `vc_bindings_once`) y el aviso de Twilio por
 *    su `IdempotencyToken`;
 *  - un FALLO de autenticación cuenta en su propio cubo `ruta|IP|fallo`
 *    ({@see failure()}): pasado el cupo se responde 429 en vez de 401, sin
 *    registrar cada uno. Ese cubo NUNCA frena una credencial buena.
 *
 * Enmienda 7 (E7-2; SEC8-b-lite, notas residuales 1 y 3):
 *  - lo autenticado cuenta ADEMÁS en el techo GLOBAL de su ruta
 *    (`ruta|global`, holgado, `voice.public_limits.global_per_minute`): con
 *    una credencial válida ya no se puede estrenar un cubo por petición sin
 *    tope. Quien no tiene la credencial no lo gasta (nunca llega aquí);
 *  - con el cubo de fallos agotado, una línea en el canal `voice` como mucho
 *    una vez por minuto y ruta, con cuántos fallos se silenciaron desde la
 *    anterior (sin la IP ni nada de la petición): otro cliente de ElevenLabs
 *    que salga por las mismas IP ya no apaga en silencio nuestros avisos
 *    `voice.*.unauthorized`.
 *
 * Las claves de llamada se guardan como huella (nunca el valor recibido).
 * Usa el limitador de Laravel (la caché de la aplicación), como `throttle`;
 * todas empiezan por {@see PREFIX} (`voice:prune-rate-limits` purga las
 * caducadas de la caché `database`, PERF8 P8-3).
 */
final class VoiceRateLimits
{
    public const ROUTE_INITIATION = 'initiation';

    public const ROUTE_POST_CALL = 'post-call';

    public const ROUTE_TOOLS = 'tools';

    public const ROUTE_USAGE_TRIGGER = 'usage-trigger';

    /** Peticiones autenticadas por minuto, por ruta y por llamada (o evento). */
    public const PER_CALL = [
        self::ROUTE_INITIATION => 10,
        self::ROUTE_POST_CALL => 10,
        self::ROUTE_TOOLS => 60,
        self::ROUTE_USAGE_TRIGGER => 10,
    ];

    /** Fallos de autenticación por minuto, por ruta e IP (su cubo propio). */
    public const FAILURES_PER_MINUTE = 30;

    public const DECAY_SECONDS = 60;

    /** El cubo de una petición autenticada sin llamada que la identifique (un cuerpo mal formado). */
    public const NO_CALL = 'sin-llamada';

    /** E7-2: el cubo del techo global de lo autenticado de una ruta (`voice-rl:<ruta>|global`). */
    public const GLOBAL = 'global';

    /**
     * E7-2: el techo global por defecto (peticiones autenticadas por minuto y
     * ruta), si la configuración falta o no es un entero válido. Los mismos
     * que `config/voice.php`.
     */
    public const GLOBAL_PER_MINUTE = [
        self::ROUTE_INITIATION => 120,
        self::ROUTE_POST_CALL => 240,
        self::ROUTE_TOOLS => 600,
        self::ROUTE_USAGE_TRIGGER => 60,
    ];

    /** E7-2: como mucho una línea de fallos silenciados por ruta en esta ventana (s). */
    public const SILENCED_NOTICE_SECONDS = 60;

    /**
     * E7-2: cuánto vive el contador de fallos silenciados sin otra línea (s):
     * más que la ventana del aviso, para no perder los de un minuto a otro.
     */
    public const SILENCED_COUNTER_SECONDS = 3600;

    /** El comienzo de TODAS las claves de estos límites en la caché (también los `:timer` de Laravel). */
    public const PREFIX = 'voice-rl:';

    /** Lo que identifica la llamada en el cuerpo de una herramienta, en este orden ({@see VoiceTools::BODY_KEYS}). */
    private const TOOL_CALL_KEYS = ['conversation_id', 'call_sid', 'call_ref'];

    /**
     * Una petición AUTENTICADA: null si pasa (y cuenta), o los segundos para
     * reintentar si su ruta y su llamada ya agotaron el minuto.
     */
    public static function authenticated(string $route, ?string $callKey): ?int
    {
        // E7-2: el techo de la ruta entre todas las llamadas, y el de su llamada. Una petición
        // frenada por cualquiera de los dos no cuenta en ninguno.
        $global = self::PREFIX.self::route($route).'|'.self::GLOBAL;
        $clave = self::PREFIX.$route.'|'.($callKey === null || $callKey === '' ? self::NO_CALL : substr(hash('sha256', $callKey), 0, 40));
        foreach ([$global => self::globalPerMinute($route), $clave => self::PER_CALL[$route]] as $cubo => $cupo) {
            if (RateLimiter::tooManyAttempts($cubo, $cupo)) {
                return max(1, RateLimiter::availableIn($cubo));
            }
        }
        RateLimiter::hit($global, self::DECAY_SECONDS);
        RateLimiter::hit($clave, self::DECAY_SECONDS);

        return null;
    }

    /** E7-2: el techo global de lo autenticado de una ruta, de la configuración y recortado como allí. */
    public static function globalPerMinute(string $route): int
    {
        $techos = config('voice.public_limits.global_per_minute');
        $valor = is_array($techos) ? ($techos[str_replace('-', '_', self::route($route))] ?? null) : null;

        return is_int($valor) ? min(self::GLOBAL_PER_MINUTE[$route] * 10, max(self::PER_CALL[$route], $valor)) : self::GLOBAL_PER_MINUTE[$route];
    }

    /**
     * Un FALLO de autenticación: null si todavía cabe en el cubo `ruta|IP|fallo`
     * (se responde 401 y cuenta), o los segundos para reintentar si ya se agotó
     * (se responde 429).
     */
    public static function failure(string $route, ?string $ip): ?int
    {
        $clave = self::PREFIX.self::route($route).'|'.($ip ?? 'sin-ip').'|fallo';
        if (RateLimiter::tooManyAttempts($clave, self::FAILURES_PER_MINUTE)) {
            self::silenced($route);

            return max(1, RateLimiter::availableIn($clave));
        }
        RateLimiter::hit($clave, self::DECAY_SECONDS);

        return null;
    }

    /**
     * E7-2: un fallo SILENCIADO (cubo de fallos agotado: 429 sin su aviso). Se
     * cuenta, y como mucho una vez por minuto y ruta sale una línea con
     * cuántos se silenciaron desde la anterior (esta incluida). Nada de la
     * petición: ni la IP, ni la credencial, ni el cuerpo.
     */
    private static function silenced(string $route): void
    {
        $contador = self::PREFIX.$route.'|silenciados';
        $silenciados = RateLimiter::hit($contador, self::SILENCED_COUNTER_SECONDS);
        $aviso = self::PREFIX.$route.'|silenciados-aviso';
        if (RateLimiter::tooManyAttempts($aviso, 1)) {
            return;
        }
        RateLimiter::hit($aviso, self::SILENCED_NOTICE_SECONDS);
        RateLimiter::clear($contador);
        Log::channel('voice')->warning('voice.public.failures_silenced', ['route' => $route, 'silenced' => $silenciados]);
    }

    /** La respuesta de un cubo agotado. */
    public static function tooMany(int $retryAfter): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'too_many_requests'], 429, ['Retry-After' => (string) $retryAfter]);
    }

    // ── Las claves de llamada (del cuerpo ya autenticado) ───────────────────

    /** La iniciación: su `call_sid`. */
    public static function initiationKey(string $rawBody): ?string
    {
        $datos = json_decode($rawBody, true, 8);

        return is_array($datos) ? VoiceIds::callSidOrNull($datos['call_sid'] ?? null) : null;
    }

    /** El post-llamada: el evento (su tipo y su conversación, como su clave de idempotencia). */
    public static function postCallKey(VoiceWebhookVerdict $verdict): ?string
    {
        $evento = $verdict->conversationId ?? ($verdict->callSid === null ? null : 'sid:'.$verdict->callSid);

        return $evento === null ? null : $verdict->kind.'|'.$evento;
    }

    /** Las herramientas: la llamada (conversación, llamada de Twilio o `call_ref`, la primera que venga). */
    public static function toolKey(string $rawBody): ?string
    {
        $datos = json_decode($rawBody, true, 8);
        if (! is_array($datos)) {
            return null;
        }
        foreach (self::TOOL_CALL_KEYS as $campo) {
            if (is_string($datos[$campo] ?? null) && $datos[$campo] !== '') {
                return $campo.':'.$datos[$campo];
            }
        }

        return null;
    }

    /** El aviso de Twilio: su `IdempotencyToken`. */
    public static function usageTriggerKey(string $rawBody): ?string
    {
        parse_str($rawBody, $campos);
        $token = $campos['IdempotencyToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private static function route(string $route): string
    {
        if (! array_key_exists($route, self::PER_CALL)) {
            throw new \InvalidArgumentException('Ruta pública de voz desconocida.');
        }

        return $route;
    }
}
