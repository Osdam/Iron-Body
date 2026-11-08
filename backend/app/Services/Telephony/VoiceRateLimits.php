<?php

namespace App\Services\Telephony;

use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use Illuminate\Http\JsonResponse;
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
 *    registrar nada. Ese cubo NUNCA frena una credencial buena.
 *
 * Las claves de llamada se guardan como huella (nunca el valor recibido).
 * Usa el limitador de Laravel (la caché de la aplicación), como `throttle`.
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

    private const PREFIX = 'voice-rl:';

    /** Lo que identifica la llamada en el cuerpo de una herramienta, en este orden ({@see VoiceTools::BODY_KEYS}). */
    private const TOOL_CALL_KEYS = ['conversation_id', 'call_sid', 'call_ref'];

    /**
     * Una petición AUTENTICADA: null si pasa (y cuenta), o los segundos para
     * reintentar si su ruta y su llamada ya agotaron el minuto.
     */
    public static function authenticated(string $route, ?string $callKey): ?int
    {
        $clave = self::PREFIX.self::route($route).'|'.($callKey === null || $callKey === '' ? self::NO_CALL : substr(hash('sha256', $callKey), 0, 40));
        if (RateLimiter::tooManyAttempts($clave, self::PER_CALL[$route])) {
            return max(1, RateLimiter::availableIn($clave));
        }
        RateLimiter::hit($clave, self::DECAY_SECONDS);

        return null;
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
            return max(1, RateLimiter::availableIn($clave));
        }
        RateLimiter::hit($clave, self::DECAY_SECONDS);

        return null;
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
