<?php

namespace App\Services\Twilio;

use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\TelephonyCarrier;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * El operador de la SUBCUENTA DE VOZ de Twilio (contrato H8, D8-3, D8-5, D8-20
 * nivel 0b y D8-21). Nueva y aparte de {@see TwilioReadOnlyClient}, cuyos pasos
 * y métodos vigila otra prueba, y del OTP, que no se toca (otra cuenta y otra
 * credencial: `voice.twilio.*`, nunca `otp.*`).
 *
 * NO INICIA LLAMADAS (con la integración nativa las crea ElevenLabs). Solo:
 *  - {@see hangup()}: `POST /2010-04-01/Accounts/{AC}/Calls/{CA}.json` con
 *    `Status=completed`, que según la documentación oficial cuelga la llamada
 *    aunque ya esté en curso. Idempotente.
 *  - {@see listCalls()}: `GET /2010-04-01/Accounts/{AC}/Calls.json`, UNA
 *    página (`PageSize` = {@see PAGE_SIZE}, `StartTime>` = la fecha UTC).
 *
 * Reglas que no se negocian (las de la sonda del H6):
 *  1. HOSTS FIJOS EN PRODUCCIÓN: siempre `api.twilio.com`. La base del
 *     simulador (`voice.twilio.base_url`) solo vale fuera de producción y si es
 *     una URL http(s) limpia.
 *  2. NUNCA SIGUE UNA URL QUE VENGA EN UNA RESPUESTA: de `next_page_uri` solo
 *     se toman `Page` y `PageToken`, se comprueban y se construye la URL propia
 *     contra el host fijo. Si no tienen forma, la página dice `truncated`.
 *  3. TIEMPO CORTO, SIN REDIRECCIONES NI REINTENTOS.
 *  4. NUNCA LANZA POR LA RED: los fallos son resultados.
 *  5. LA CREDENCIAL (la API key RESTRINGIDA de Laravel: SID `SK…` y su
 *     secreto) se lee en el momento de usarla, sin guardarla en una propiedad,
 *     un resultado ni el mensaje de una excepción (D8-5: lector enumerado).
 *  6. SIN NÚMEROS: de `to` y `from` solo salen los cuatro últimos dígitos.
 *
 * No mira `TWILIO_VOICE_ENABLED`: cortar y conciliar tienen que poder hacerse
 * con la voz ya apagada (D8-20). Sin la credencial completa, `not_configured`
 * sin red ({@see configured()}).
 */
final class TwilioVoiceCarrier implements TelephonyCarrier
{
    public const PRODUCTION_API_BASE = 'https://api.twilio.com';

    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-f]{32}$/D';

    public const API_KEY_SID_PATTERN = '/^SK[0-9a-f]{32}$/D';

    /** Una página de la conciliación (el máximo de Twilio es 1000). */
    public const PAGE_SIZE = 100;

    public const TIMEOUT_SECONDS = 10;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Las respuestas de Twilio tienen 4 o 5 niveles; un JSON más hondo no es suyo. */
    private const JSON_MAX_DEPTH = 32;

    /** ¿Están el SID de la subcuenta, el SID de la key y su secreto, con forma? Sin red. */
    public static function configured(): bool
    {
        return self::accountSid() !== null && self::apiKeySid() !== null && trim((string) config('voice.twilio.api_key_secret')) !== '';
    }

    public function hangup(string $callSid): CarrierResult
    {
        $cuenta = self::accountSid();
        $llave = self::apiKeySid();
        if ($cuenta === null || $llave === null || ! self::configured()) {
            return new CarrierResult(CarrierResult::OUTCOME_NOT_CONFIGURED, null, null, null, null);
        }
        if (! VoiceIds::isCallSid($callSid)) {
            return new CarrierResult(CarrierResult::OUTCOME_INVALID_REQUEST, null, null, null, null);
        }

        try {
            $respuesta = Http::withBasicAuth($llave, trim((string) config('voice.twilio.api_key_secret')))
                ->acceptJson()
                ->asForm()
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::base()."/2010-04-01/Accounts/{$cuenta}/Calls/{$callSid}.json", ['Status' => 'completed']);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException) {
            return new CarrierResult(CarrierResult::OUTCOME_UNREACHABLE, $callSid, null, null, null);
        } catch (Throwable) {
            // Otro fallo del cliente. Colgar es idempotente: quien corta puede
            // repetirlo. Ni la clase ni el mensaje salen (pueden llevar la credencial).
            return new CarrierResult(CarrierResult::OUTCOME_ERROR, $callSid, null, null, null);
        }

        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        $cuerpo = json_decode($respuesta->body(), true, self::JSON_MAX_DEPTH);
        $cuerpo = is_array($cuerpo) ? $cuerpo : [];

        if ($estado >= 200 && $estado <= 299) {
            $estadoLlamada = $cuerpo['status'] ?? null;

            return new CarrierResult(
                CarrierResult::OUTCOME_REQUESTED,
                $callSid,
                is_string($estadoLlamada) && in_array($estadoLlamada, CarrierResult::PROVIDER_STATUSES, true) ? $estadoLlamada : null,
                $http,
                null,
            );
        }
        $codigo = $cuerpo['code'] ?? null;
        $codigo = is_int($codigo) && $codigo >= 0 && $codigo <= 999999 ? $codigo : null;

        return match (true) {
            $estado === 401, $estado === 403 => new CarrierResult(CarrierResult::OUTCOME_AUTH_FAILED, $callSid, null, $http, $codigo),
            $estado === 404 => new CarrierResult(CarrierResult::OUTCOME_NOT_FOUND, $callSid, null, $http, $codigo),
            $estado >= 400 && $estado <= 499 => new CarrierResult(CarrierResult::OUTCOME_REJECTED, $callSid, null, $http, $codigo),
            default => new CarrierResult(CarrierResult::OUTCOME_ERROR, $callSid, null, $http, $codigo),
        };
    }

    public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
    {
        $cuenta = self::accountSid();
        $llave = self::apiKeySid();
        if ($cuenta === null || $llave === null || ! self::configured()) {
            return new CarrierCallsPage(CarrierCallsPage::OUTCOME_NOT_CONFIGURED, [], null, false, null);
        }
        $consulta = [
            'PageSize' => self::PAGE_SIZE,
            // `StartTime>` = «empezó ese día o después», con la fecha en UTC (recurso Call).
            'StartTime>' => DateTimeImmutable::createFromInterface($since)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
        ];
        if ($pageToken !== null) {
            if (preg_match(CarrierCallsPage::PAGE_TOKEN_PATTERN, $pageToken) !== 1) {
                return new CarrierCallsPage(CarrierCallsPage::OUTCOME_INVALID_REQUEST, [], null, false, null);
            }
            [$pagina, $token] = explode(':', $pageToken, 2);
            $consulta['Page'] = (int) $pagina;
            $consulta['PageToken'] = $token;
        }

        try {
            $respuesta = Http::withBasicAuth($llave, trim((string) config('voice.twilio.api_key_secret')))
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::base()."/2010-04-01/Accounts/{$cuenta}/Calls.json", $consulta);
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new CarrierCallsPage(CarrierCallsPage::OUTCOME_UNREACHABLE, [], null, false, null);
        } catch (Throwable) {
            return new CarrierCallsPage(CarrierCallsPage::OUTCOME_ERROR, [], null, false, null);
        }

        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        if ($estado === 401 || $estado === 403) {
            return new CarrierCallsPage(CarrierCallsPage::OUTCOME_AUTH_FAILED, [], null, false, $http);
        }
        $cuerpo = $estado >= 200 && $estado <= 299 ? json_decode($respuesta->body(), true, self::JSON_MAX_DEPTH) : null;
        if (! is_array($cuerpo) || ! is_array($cuerpo['calls'] ?? null) || ! array_is_list($cuerpo['calls'])) {
            return new CarrierCallsPage(CarrierCallsPage::OUTCOME_ERROR, [], null, false, $http);
        }

        $llamadas = [];
        foreach ($cuerpo['calls'] as $fila) {
            $llamada = is_array($fila) ? self::call($fila) : null;
            if ($llamada === null) {
                // Una fila sin SID con forma no se puede conciliar: la página
                // entera falla en voz alta en vez de quedar incompleta en silencio.
                return new CarrierCallsPage(CarrierCallsPage::OUTCOME_ERROR, [], null, false, $http);
            }
            $llamadas[] = $llamada;
        }

        [$siguiente, $truncada] = self::nextPage($cuerpo['next_page_uri'] ?? null);

        return new CarrierCallsPage(CarrierCallsPage::OUTCOME_LISTED, $llamadas, $siguiente, $truncada, $http);
    }

    /**
     * La huella de la key de Laravel, sin red: los primeros 8 hexadecimales de
     * `hash_hmac('sha256', <secreto recortado>, $key)`, o null si no está. La
     * clave la pone quien describe la configuración (S8).
     *
     * @return array{api_key_secret: ?string}
     *
     * @throws InvalidArgumentException si $key === ''
     */
    public static function secretFingerprints(#[\SensitiveParameter] string $key): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('La clave de las huellas no puede estar vacía.');
        }
        $secreto = trim((string) config('voice.twilio.api_key_secret'));

        return ['api_key_secret' => $secreto === '' ? null : substr(hash_hmac('sha256', $secreto, $key), 0, TwilioRequestSignature::FINGERPRINT_HEX_LENGTH)];
    }

    // ── Piezas puras ────────────────────────────────────────────────────────

    /**
     * Una fila del recurso Call → {@see CarrierCall}, o null si su SID no
     * tiene forma. Lo demás que no tenga forma queda en null.
     *
     * @param  array<string, mixed>  $fila
     */
    private static function call(array $fila): ?CarrierCall
    {
        $sid = VoiceIds::callSidOrNull($fila['sid'] ?? null);
        if ($sid === null) {
            return null;
        }
        $estado = $fila['status'] ?? null;
        $direccion = $fila['direction'] ?? null;
        $precio = $fila['price'] ?? null;
        $moneda = $fila['price_unit'] ?? null;

        return new CarrierCall(
            sid: $sid,
            parentSid: VoiceIds::callSidOrNull($fila['parent_call_sid'] ?? null),
            status: is_string($estado) && in_array($estado, CarrierResult::PROVIDER_STATUSES, true) ? $estado : null,
            direction: is_string($direccion) && in_array($direccion, CarrierCall::DIRECTIONS, true) ? $direccion : null,
            startTime: self::utc($fila['start_time'] ?? null),
            endTime: self::utc($fila['end_time'] ?? null),
            durationSeconds: self::seconds($fila['duration'] ?? null),
            price: is_string($precio) && preg_match(CarrierCall::PRICE_PATTERN, $precio) === 1 ? $precio : null,
            priceUnit: is_string($moneda) && preg_match(CarrierCall::PRICE_UNIT_PATTERN, $moneda) === 1 ? $moneda : null,
            toLast4: self::last4($fila['to'] ?? null),
            fromLast4: self::last4($fila['from'] ?? null),
        );
    }

    /**
     * De `next_page_uri` solo `Page` y `PageToken`, comprobados: el token opaco
     * y si la lista quedó truncada (había más y no se puede seguir sin confiar
     * en la URL ajena).
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function nextPage(mixed $uri): array
    {
        if ($uri === null || $uri === '') {
            return [null, false];
        }
        $consulta = is_string($uri) ? parse_url($uri, PHP_URL_QUERY) : null;
        $pares = is_string($consulta) ? TwilioRequestSignature::formPairs($consulta) : [];
        $pagina = null;
        $token = null;
        foreach ($pares as [$clave, $valor]) {
            if ($clave === 'Page') {
                $pagina = $valor;
            } elseif ($clave === 'PageToken') {
                $token = $valor;
            }
        }
        $opaco = $pagina !== null && $token !== null ? $pagina.':'.$token : null;

        return $opaco !== null && preg_match(CarrierCallsPage::PAGE_TOKEN_PATTERN, $opaco) === 1 ? [$opaco, false] : [null, true];
    }

    /** Una hora de Twilio (RFC 2822, en GMT) → ISO 8601 UTC; null si no se lee. */
    private static function utc(mixed $hora): ?string
    {
        if (! is_string($hora) || $hora === '' || strlen($hora) > 64) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC2822, $hora);
        if ($fecha === false) {
            return null;
        }

        return $fecha->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function seconds(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor >= 0 ? $valor : null;
        }

        return is_string($valor) && preg_match('/^[0-9]{1,9}$/D', $valor) === 1 ? (int) $valor : null;
    }

    /** Los cuatro últimos dígitos de un E.164; de una URI de cliente o SIP, null. */
    private static function last4(mixed $numero): ?string
    {
        return is_string($numero) && preg_match('/^\+[0-9]{4,15}$/D', $numero) === 1 ? substr($numero, -4) : null;
    }

    private static function accountSid(): ?string
    {
        $sid = config('voice.twilio.account_sid');

        return is_string($sid) && preg_match(self::ACCOUNT_SID_PATTERN, $sid) === 1 ? $sid : null;
    }

    private static function apiKeySid(): ?string
    {
        $sid = config('voice.twilio.api_key_sid');

        return is_string($sid) && preg_match(self::API_KEY_SID_PATTERN, $sid) === 1 ? $sid : null;
    }

    /** `api.twilio.com` en producción; fuera, la base del simulador si es una URL http(s) limpia. */
    private static function base(): string
    {
        $base = trim((string) config('voice.twilio.base_url'));
        if (app()->isProduction() || $base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return self::PRODUCTION_API_BASE;
        }
        $partes = parse_url($base);
        $limpia = is_array($partes)
            && in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true)
            && ($partes['host'] ?? '') !== ''
            && ! isset($partes['user'])
            && ! isset($partes['pass'])
            && ! isset($partes['query'])
            && ! isset($partes['fragment']);

        return $limpia ? rtrim($base, '/') : self::PRODUCTION_API_BASE;
    }
}
