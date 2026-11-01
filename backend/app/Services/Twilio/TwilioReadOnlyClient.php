<?php

namespace App\Services\Twilio;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;

/**
 * Cliente de SOLO LECTURA de la API de Twilio: cinco consultas fijas que dicen
 * en qué estado está la cuenta, qué números tiene, cuántos caller ID
 * verificados, cuántas llamadas hubo en 7 días y qué alertas en 24 horas. No
 * crea, cambia ni borra nada en Twilio: el único verbo que usa es GET, no tiene
 * otro método público que hable con Twilio, y una prueba estática lo vigila
 * sobre este fichero.
 *
 * Reglas que no se negocian:
 *
 *  1. HOSTS FIJOS EN PRODUCCIÓN. Con `app()->isProduction()`, siempre
 *     `api.twilio.com` y `monitor.twilio.com`. La base del constructor solo
 *     vale fuera de producción (el simulador local) y solo si es una URL
 *     http(s) limpia: sin usuario, consulta ni fragmento. No lee `TWILIO_BASE`,
 *     `TWILIO_VERIFY_BASE` ni ninguna otra configuración: todo llega por el
 *     constructor.
 *  2. PRIMERA PÁGINA SIEMPRE. No sigue `next_page_uri`, `meta.next_page_url` ni
 *     ninguna URL que venga en una respuesta: la escribe un tercero, y la
 *     credencial viajaría con ella.
 *  3. TIEMPO CORTO, SIN REDIRECCIONES NI REINTENTOS. Como mucho
 *     {@see MAX_TIMEOUT_SECONDS} por consulta y {@see CONNECT_TIMEOUT_SECONDS}
 *     para conectar. Una redirección no se sigue (llevaría la credencial a otro
 *     sitio): cuenta como un estado inesperado.
 *  4. NUNCA LANZA POR LA RED. 401/403 → `auth_failed`; sin conexión o tiempo
 *     agotado → `unreachable`; cualquier otro estado, o un cuerpo que no es un
 *     objeto JSON → `error`. En un fallo no se devuelve el cuerpo: el error de
 *     Twilio puede repetir un número de teléfono.
 *  5. LA CREDENCIAL NO SALE DE AQUÍ: ni en el resultado, ni en el log, ni en el
 *     mensaje de una excepción, ni en una traza (`#[SensitiveParameter]`), ni
 *     en un volcado o una serialización del objeto (se guarda envuelta en
 *     `SensitiveParameterValue`, que `dump()`, `var_dump()` y `print_r()` no
 *     enseñan y `serialize()` rechaza). La ficha de la cuenta trae el
 *     `auth_token` de Twilio: se quita antes de devolver el cuerpo.
 *
 * Lo que sí lanza (InvalidArgumentException) son los errores de quien lo usa:
 * un SID que no casa con {@see ACCOUNT_SID_PATTERN} (nunca llega a la ruta un
 * texto libre), una credencial vacía o un paso que no existe. Ninguno sale a la
 * red, y ningún mensaje lleva el valor recibido.
 *
 * EL CUERPO DE UNA RESPUESTA BUENA ES CRUDO (menos el `auth_token`): lleva
 * números completos, destinos de llamadas y textos de alertas. Es materia prima
 * para {@see TwilioProbeSanitizer} y nada más: no se registra, no se guarda y no
 * se devuelve a nadie.
 */
final class TwilioReadOnlyClient
{
    public const PRODUCTION_API_BASE = 'https://api.twilio.com';

    public const PRODUCTION_MONITOR_BASE = 'https://monitor.twilio.com';

    public const STEPS = ['account', 'numbers', 'caller_ids', 'calls', 'alerts'];

    /** Filas que se piden en la primera (y única) página de cada lista. */
    public const PAGE_SIZES = ['numbers' => 20, 'caller_ids' => 20, 'calls' => 50, 'alerts' => 50];

    /**
     * La forma del SID de la cuenta. Con `D`, `$` es el final de verdad: sin él,
     * PCRE acepta un salto de línea al final y llegaría a la ruta.
     */
    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-f]{32}$/D';

    /** Techo del tiempo de espera de una consulta, aunque quien llama pida más. */
    public const MAX_TIMEOUT_SECONDS = 10;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const ERROR_AUTH_FAILED = 'auth_failed';

    public const ERROR_UNREACHABLE = 'unreachable';

    public const ERROR_OTHER = 'error';

    /** Las respuestas de Twilio tienen 4 o 5 niveles; un JSON más hondo no es suyo. */
    private const JSON_MAX_DEPTH = 32;

    private readonly string $accountSid;

    private readonly SensitiveParameterValue $username;

    private readonly SensitiveParameterValue $secret;

    /** La base del simulador, limpia y sin barra final; null = los hosts oficiales. */
    private readonly ?string $nonProductionBase;

    private readonly int $timeoutSeconds;

    /** $nonProductionBase se IGNORA si app()->isProduction(): ahí, siempre los dos hosts oficiales (para api y monitor). */
    public function __construct(
        #[SensitiveParameter] string $accountSid,
        #[SensitiveParameter] string $username,
        #[SensitiveParameter] string $secret,
        ?string $nonProductionBase = null,
        int $timeoutSeconds = 10,
    ) {
        if (preg_match(self::ACCOUNT_SID_PATTERN, $accountSid) !== 1) {
            // Sin el valor: si alguien cruzó las variables, aquí vendría el token.
            throw new InvalidArgumentException('El SID de la cuenta de Twilio no tiene la forma AC + 32 hexadecimales.');
        }
        if (trim($username) === '' || trim($secret) === '') {
            throw new InvalidArgumentException('Falta la credencial de Twilio (usuario o secreto vacío).');
        }

        $this->accountSid = $accountSid;
        $this->username = new SensitiveParameterValue($username);
        $this->secret = new SensitiveParameterValue($secret);
        $this->nonProductionBase = self::cleanBase($nonProductionBase);
        // Defensa: nunca por encima del techo, aunque quien llama se equivoque.
        $this->timeoutSeconds = max(1, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));
    }

    /**
     * Un GET, siempre a la primera página. Nunca sigue next_page_uri ni ninguna URL que venga en la respuesta.
     *   account    → {api}/2010-04-01/Accounts/{sid}.json
     *   numbers    → {api}/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json?PageSize=20
     *   caller_ids → {api}/2010-04-01/Accounts/{sid}/OutgoingCallerIds.json?PageSize=20
     *   calls      → {api}/2010-04-01/Accounts/{sid}/Calls.json?PageSize=50&StartTime>=<hoy−7 días, YYYY-MM-DD en UTC>
     *   alerts     → {monitor}/v1/Alerts?PageSize=50&StartDate=<ahora−24 h, YYYY-MM-DDThh:mm:ssZ en UTC>
     * Nunca lanza por la red: 401/403 → auth_failed; sin conexión o tiempo agotado → unreachable; otro estado o JSON no válido → error.
     *
     * @return array{ok: bool, status: ?int, body: ?array<string,mixed>, error: null|'auth_failed'|'unreachable'|'error'}
     *
     * @throws InvalidArgumentException si `$step` no está en {@see STEPS} (sin red)
     */
    public function get(string $step, DateTimeInterface $now): array
    {
        [$url, $query] = $this->target($step, $now);

        try {
            $respuesta = Http::withBasicAuth($this->username->getValue(), $this->secret->getValue())->acceptJson()->withoutRedirecting()->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $this->timeoutSeconds))->timeout($this->timeoutSeconds)->get($url, $query);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`preventStrayRequests`): que se vea.
            throw $e;
        } catch (ConnectionException) {
            return self::failure(self::ERROR_UNREACHABLE, null);
        } catch (Throwable $e) {
            // Otro fallo del cliente HTTP. Se convierte en resultado a propósito
            // (quien llama nunca recibe un fallo de Twilio como excepción) y se
            // registra solo su clase: el mensaje puede llevar la URL.
            Log::warning('TwilioReadOnlyClient: fallo inesperado del cliente HTTP', ['step' => $step, 'exception' => $e::class]);

            return self::failure(self::ERROR_OTHER, null);
        }

        $estado = $respuesta->status();
        if ($estado === 401 || $estado === 403) {
            return self::failure(self::ERROR_AUTH_FAILED, $estado);
        }
        if ($estado < 200 || $estado > 299) {
            return self::failure(self::ERROR_OTHER, $estado);
        }

        $cuerpo = json_decode($respuesta->body(), true, self::JSON_MAX_DEPTH);
        if (! is_array($cuerpo)) {
            return self::failure(self::ERROR_OTHER, $estado);
        }
        // La ficha de la cuenta (GET Accounts/{sid}.json) devuelve su auth_token.
        unset($cuerpo['auth_token']);

        return ['ok' => true, 'status' => $estado, 'body' => $cuerpo, 'error' => null];
    }

    /**
     * La URL y la consulta de cada paso: las cinco únicas, y ninguna con `Page`
     * ni `PageToken`. Los hosts se deciden en cada llamada, no al construir.
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function target(string $step, DateTimeInterface $now): array
    {
        $oficiales = app()->isProduction() || $this->nonProductionBase === null;
        $api = $oficiales ? self::PRODUCTION_API_BASE : $this->nonProductionBase;
        $monitor = $oficiales ? self::PRODUCTION_MONITOR_BASE : $this->nonProductionBase;
        $cuenta = "{$api}/2010-04-01/Accounts/{$this->accountSid}";
        $utc = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));

        return match ($step) {
            'account' => ["{$cuenta}.json", []],
            'numbers' => ["{$cuenta}/IncomingPhoneNumbers.json", ['PageSize' => self::PAGE_SIZES['numbers']]],
            'caller_ids' => ["{$cuenta}/OutgoingCallerIds.json", ['PageSize' => self::PAGE_SIZES['caller_ids']]],
            // `StartTime>` = «empezó ese día o después», con la fecha en UTC
            // (documentación oficial del recurso Call, consultada el 31-10-2026).
            'calls' => ["{$cuenta}/Calls.json", ['PageSize' => self::PAGE_SIZES['calls'], 'StartTime>' => $utc->modify('-7 days')->format('Y-m-d')]],
            // `StartDate` en GMT, con la forma YYYY-MM-DDThh:mm:ssZ (documentación
            // oficial de Monitor Alerts; no admite consultas de más de 30 días).
            'alerts' => ["{$monitor}/v1/Alerts", ['PageSize' => self::PAGE_SIZES['alerts'], 'StartDate' => $utc->modify('-24 hours')->format('Y-m-d\TH:i:s\Z')]],
            default => throw new InvalidArgumentException('Paso de Twilio desconocido; los válidos son: '.implode(', ', self::STEPS).'.'),
        };
    }

    /**
     * La base del simulador si es una URL http(s) limpia (sin usuario, clave,
     * consulta ni fragmento), sin la barra final; si no, null: los oficiales.
     */
    private static function cleanBase(?string $base): ?string
    {
        $base = trim((string) $base);
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $partes = parse_url($base);
        $limpia = is_array($partes)
            && in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true)
            && ($partes['host'] ?? '') !== ''
            && ! isset($partes['user'])
            && ! isset($partes['pass'])
            && ! isset($partes['query'])
            && ! isset($partes['fragment']);

        return $limpia ? rtrim($base, '/') : null;
    }

    /** @return array{ok: false, status: ?int, body: null, error: 'auth_failed'|'unreachable'|'error'} */
    private static function failure(string $error, ?int $status): array
    {
        return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $error];
    }
}
