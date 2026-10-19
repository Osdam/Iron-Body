<?php

namespace App\Services\UltronStudio;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * El cliente PROPIO del laboratorio contra la Responses API de OpenAI
 * (contrato H4 §A5), con `Http::`: sin SDK y sin dependencias nuevas. No usa
 * nada del motor ni de IRON IA: ni su clave, ni su modelo, ni su cliente.
 *
 *  - `POST https://api.openai.com/v1/responses`. En `production` la URL es
 *    FIJA aunque exista `ULTRON_STUDIO_LAB_OPENAI_BASE_URL`; fuera, esa
 *    variable apunta al simulador local del E2E.
 *  - `withToken(<la clave del laboratorio>)`, leída de la configuración en el
 *    momento de llamar y nunca guardada en una propiedad, un resultado ni un
 *    registro. `connectTimeout(5)`, `timeout(<configurado, ≤ 60>)`, sin
 *    redirecciones y SIN `retry()`: un reintento podría pagar dos veces.
 *  - Cuerpo: `model`, `store:false` (si se omite vale true y OpenAI guarda la
 *    respuesta al menos 30 días), `instructions`, `input` con un único
 *    mensaje de usuario, `max_output_tokens` (incluye el razonamiento) y
 *    `text.format` `json_schema` estricto. `temperature` y
 *    `reasoning.effort`, solo si están configurados. Nunca `user`,
 *    `safety_identifier` ni `metadata`.
 *
 * Si el laboratorio no está configurado para `openai` (modo, clave propia no
 * compartida, modelo, precios, presupuesto), NO HAY PETICIÓN: vuelve
 * `not_configured`.
 *
 * CLASIFICACIÓN (tabla de A5; {@see classify()} es pura y la prueban los
 * casos del tester): timeout, sin conexión, 401/403, 429 de cuota, otro 429,
 * 400/404/422, 5xx, y en un 200: negativa, respuesta incompleta o salida que
 * no cumple el esquema. Nunca se devuelve el cuerpo del proveedor: solo la
 * clase, el estado HTTP y el `usage`.
 */
final class StudioLabOpenAiClient implements StudioLabModelClient
{
    /** La única URL en producción. */
    public const PRODUCTION_BASE_URL = 'https://api.openai.com';

    public const PATH = '/v1/responses';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Un 429 de cuota (contrato §A5 y punto 21 del README del fixture,
     * congelado por la Enmienda 1): `insufficient_quota` en `error.code` o en
     * `error.type`. Cualquier otro 429 es de ritmo, también uno sin JSON. La
     * guía oficial de códigos de error (consultada el 19-10-2026) nombra
     * además `credit_balance_exhausted` y los límites de gasto, cuyo `type`
     * «puede seguir siendo insufficient_quota»: si llegan sin ese `type`, hoy
     * cuentan como ritmo (coste 0, el paso se retrasa). Ampliar esta lista
     * exige una enmienda.
     */
    public const QUOTA_CODES = ['insufficient_quota'];

    /** cURL: el tiempo se agotó (al conectar o esperando la respuesta). */
    private const CURL_TIMEOUT = 28;

    /** cURL: no se resolvió el nombre, no se conectó o falló el apretón TLS. La petición no salió. */
    private const CURL_NEVER_SENT = [6, 7, 35];

    public function complete(string $role, string $instructions, string $inputJson, array $schema, int $maxOutputTokens): array
    {
        $nombre = StudioLabPrompts::schemaName($role);
        $config = StudioLabConfig::current();
        if (! $config->isOpenAi() || ! $config->configured() || $config->model === null) {
            return self::result(self::CLASS_NOT_CONFIGURED);
        }

        $cuerpo = [
            'model' => $config->model,
            'store' => false,
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => $inputJson]],
            // Defensa: nunca por encima del tope duro de su papel, aunque quien llama se equivoque.
            'max_output_tokens' => max(StudioLabConfig::MIN_OUTPUT_TOKENS, min($role === self::ROLE_JUDGE ? StudioLabConfig::HARD_MAX_JUDGE_OUTPUT_TOKENS : StudioLabConfig::HARD_MAX_ACTOR_OUTPUT_TOKENS, $maxOutputTokens)),
            'text' => ['format' => ['type' => 'json_schema', 'name' => $nombre, 'strict' => true, 'schema' => $schema]],
        ];
        if ($config->temperature !== null) {
            $cuerpo['temperature'] = $config->temperature;
        }
        if ($config->reasoningEffort !== null) {
            $cuerpo['reasoning'] = ['effort' => $config->reasoningEffort];
        }

        $inicio = hrtime(true);
        try {
            $respuesta = Http::withToken(trim((string) config('ultron_studio.lab.openai.api_key')))
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout($config->timeoutSeconds)
                ->post(self::url(), $cuerpo);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException $e) {
            return self::result(self::connectionClass($e), latencyMs: self::since($inicio), exception: class_basename($e));
        } catch (Throwable $e) {
            // Cualquier otro fallo del cliente HTTP: no se sabe si la petición
            // llegó y se cobró, así que cuenta como un timeout (se cobra la
            // reserva). Se registra la CLASE, nunca el mensaje.
            return self::result(self::CLASS_TIMEOUT, latencyMs: self::since($inicio), exception: class_basename($e));
        }

        $clasificado = self::classify($respuesta->status(), $respuesta->json(), $schema);

        return [
            'status' => $clasificado['class'] === null ? self::STATUS_OK : self::STATUS_ERROR,
            'output' => $clasificado['output'],
            'usage' => $clasificado['usage'],
            'class' => $clasificado['class'],
            'http' => $respuesta->status(),
            'latencyMs' => self::since($inicio),
            'exception' => null,
        ];
    }

    /**
     * Una respuesta HTTP → su clase, la salida (si la hay y cumple el
     * esquema) y el `usage` informado (null si no lo hay). Pura.
     *
     * @param  array<string,mixed>  $schema
     * @return array{class:string|null,output:array<string,mixed>|null,usage:array{inputTokens:int,outputTokens:int,reasoningTokens:int}|null}
     */
    public static function classify(int $http, mixed $body, array $schema): array
    {
        $error = static fn (string $clase, ?array $usage = null): array => ['class' => $clase, 'output' => null, 'usage' => $usage];

        if ($http < 200 || $http >= 300) {
            return $error(self::httpClass($http, $body));
        }

        $usage = self::parseUsage(is_array($body) ? ($body['usage'] ?? null) : null);
        if (! is_array($body)) {
            return $error(self::CLASS_INVALID_OUTPUT, $usage);
        }

        $estado = $body['status'] ?? null;
        if ($estado === 'incomplete') {
            return $error(self::CLASS_INCOMPLETE, $usage);
        }
        if ($estado !== 'completed') {
            // `failed`, `cancelled`… o sin estado: no hay salida que leer.
            return $error(self::CLASS_INVALID_OUTPUT, $usage);
        }

        $textos = [];
        foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $parte) {
                if (! is_array($parte)) {
                    continue;
                }
                if (($parte['type'] ?? null) === 'refusal') {
                    return $error(self::CLASS_REFUSAL, $usage);
                }
                if (($parte['type'] ?? null) === 'output_text' && is_string($parte['text'] ?? null)) {
                    $textos[] = $parte['text'];
                }
            }
        }
        if ($textos === []) {
            return $error(self::CLASS_INVALID_OUTPUT, $usage);
        }

        $salida = json_decode(implode('', $textos), true);
        if (! is_array($salida) || ! StudioLabPrompts::conforms($schema, $salida)) {
            return $error(self::CLASS_INVALID_OUTPUT, $usage);
        }

        return ['class' => null, 'output' => $salida, 'usage' => $usage];
    }

    /** La clase de una respuesta que no es 2xx. */
    public static function httpClass(int $http, mixed $body): string
    {
        return match (true) {
            $http === 401, $http === 403 => self::CLASS_AUTH,
            $http === 429 => self::isQuota($body) ? self::CLASS_QUOTA : self::CLASS_RATE_LIMITED,
            $http === 400, $http === 404, $http === 422 => self::CLASS_BAD_REQUEST,
            // 408: el servidor no recibió la petición entera a tiempo; no la procesó.
            $http === 408, $http >= 500 && $http <= 599 => self::CLASS_SERVER,
            // Cualquier otro 4xx es una petición que el proveedor no acepta: insistir no lo arregla.
            $http >= 400 && $http <= 499 => self::CLASS_BAD_REQUEST,
            default => self::CLASS_SERVER,
        };
    }

    /**
     * La URL de la llamada. En `production`, siempre la oficial; fuera, la
     * base configurada si es una URL http(s) limpia (sin usuario, consulta ni
     * fragmento), para el simulador local.
     */
    public static function url(): string
    {
        $base = self::PRODUCTION_BASE_URL;
        if (! app()->isProduction()) {
            $propia = config('ultron_studio.lab.openai.base_url');
            if (is_string($propia) && self::cleanBase(trim($propia))) {
                $base = rtrim(trim($propia), '/');
            }
        }

        return $base.self::PATH;
    }

    /** ¿El 429 es de facturación (cuota, saldo o límite de gasto)? */
    private static function isQuota(mixed $body): bool
    {
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        return in_array($error['code'] ?? null, self::QUOTA_CODES, true) || in_array($error['type'] ?? null, self::QUOTA_CODES, true);
    }

    /**
     * Un fallo sin respuesta: timeout (no se sabe si se cobró) o sin conexión
     * (la petición no salió). Se decide por el código de cURL del contexto de
     * Guzzle (o, en las pruebas, por el prefijo `cURL error N:` del mensaje,
     * que solo se lee aquí y nunca se registra). Lo que no se puede decidir
     * cuenta como timeout: es la opción que no subestima el gasto.
     */
    private static function connectionClass(ConnectionException $e): string
    {
        $origen = $e->getPrevious();
        $errno = null;
        if ($origen instanceof ConnectException || $origen instanceof GuzzleRequestException) {
            $contexto = $origen->getHandlerContext();
            $errno = is_int($contexto['errno'] ?? null) ? $contexto['errno'] : null;
        }
        if ($errno === null && preg_match('/^cURL error (\d+):/', $e->getMessage(), $m) === 1) {
            $errno = (int) $m[1];
        }

        if ($errno === self::CURL_TIMEOUT) {
            return self::CLASS_TIMEOUT;
        }
        if (in_array($errno, self::CURL_NEVER_SENT, true) || ($errno === null && $origen instanceof ConnectException)) {
            return self::CLASS_CONNECTION;
        }

        return self::CLASS_TIMEOUT;
    }

    /**
     * El `usage` del proveedor (`{input_tokens, output_tokens,
     * output_tokens_details.reasoning_tokens, …}`), o null si no viene
     * completo. `output_tokens` ya incluye el razonamiento y los tokens en
     * caché se cobran como entrada normal: no se suma ni se resta nada.
     *
     * @return array{inputTokens:int,outputTokens:int,reasoningTokens:int}|null
     */
    public static function parseUsage(mixed $usage): ?array
    {
        $u = is_array($usage) ? $usage : null;
        if ($u === null || ! is_int($u['input_tokens'] ?? null) || ! is_int($u['output_tokens'] ?? null)) {
            return null;
        }
        $detalles = is_array($u['output_tokens_details'] ?? null) ? $u['output_tokens_details'] : [];

        return [
            'inputTokens' => max(0, $u['input_tokens']),
            'outputTokens' => max(0, $u['output_tokens']),
            'reasoningTokens' => is_int($detalles['reasoning_tokens'] ?? null) ? max(0, $detalles['reasoning_tokens']) : 0,
        ];
    }

    private static function cleanBase(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $partes = parse_url($url);

        return is_array($partes)
            && in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true)
            && ($partes['host'] ?? '') !== ''
            && ! isset($partes['user'])
            && ! isset($partes['pass'])
            && ! isset($partes['query'])
            && ! isset($partes['fragment']);
    }

    private static function since(int $inicio): int
    {
        return intdiv(max(0, hrtime(true) - $inicio), 1_000_000);
    }

    /**
     * Un resultado de error sin salida.
     *
     * @return array{status:string,output:null,usage:null,class:string,http:null,latencyMs:int,exception:string|null}
     */
    private static function result(string $class, int $latencyMs = 0, ?string $exception = null): array
    {
        if (! in_array($class, self::CLASSES, true)) {
            throw new InvalidArgumentException('Clase de fallo desconocida.');
        }

        return ['status' => self::STATUS_ERROR, 'output' => null, 'usage' => null, 'class' => $class, 'http' => null, 'latencyMs' => $latencyMs, 'exception' => $exception];
    }
}
