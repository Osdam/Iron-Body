<?php

namespace App\Services\ReplyClassification;

use App\Services\Outreach\Run\Classifier\ReplyClassifierPrompt;
use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * AI9 · EL TRANSPORTE del clasificador de respuestas hacia la Responses API
 * de OpenAI (contrato H9 §4: «OpenAI, para AI9, con `store: false`, solo el
 * texto de la respuesta y sin otros datos de la persona»). Vive FUERA de
 * `App\Services\Outreach` (la guarda estática del H9 prohíbe allí la red y el
 * nombre del proveedor) y solo se usa si el contenedor lo enlaza con
 * {@see ReplyClassifierTransport}. Con la forma del cliente del laboratorio
 * (`StudioLabOpenAiClient`), sin compartir nada con él ni con el motor:
 *
 *  - `POST https://api.openai.com/v1/responses`, URL FIJA (sin base
 *    configurable: nada que redirigir);
 *  - `withToken(<la clave PROPIA del clasificador>)`, leída de
 *    `outreach.classifier.api_key` en el momento de llamar, nunca guardada ni
 *    registrada. Ninguna clave de OpenAI que ya use otra parte del sistema
 *    vale ({@see FOREIGN_KEYS}: la compartida de IRON IA y del cerebro legado,
 *    la del copiloto del CRM y la del laboratorio del Studio): si es la misma,
 *    no está configurado (como el laboratorio con la suya);
 *  - `connectTimeout(3)`, `timeout(<2–10 s>)`, sin redirecciones y SIN
 *    `retry()`: un reintento podría cobrar dos veces;
 *  - cuerpo: `model`, `store: false`, `instructions` (fijas), `input` con UN
 *    mensaje de usuario (el texto ya enmascarado), `max_output_tokens` y
 *    `text.format` `json_schema` estricto. Nunca `user`, `metadata`,
 *    `safety_identifier`, `previous_response_id` ni herramientas.
 *
 * Devuelve la clase del fallo (las de {@see ReplyClassifierTransport::ERRORS}),
 * el objeto JSON de la salida sin interpretar (lo valida el clasificador) y
 * el `usage`. Nunca el cuerpo del proveedor ni un mensaje de error.
 */
final class OpenAiReplyClassifierTransport implements ReplyClassifierTransport
{
    public const URL = 'https://api.openai.com/v1/responses';

    public const CONNECT_TIMEOUT_SECONDS = 3;

    /** Un 429 de cuota (el mismo criterio que el laboratorio). */
    private const QUOTA_CODES = ['insufficient_quota'];

    /** cURL: tiempo agotado; y la petición que no llegó a salir (DNS, conexión, TLS). */
    private const CURL_TIMEOUT = 28;

    private const CURL_NEVER_SENT = [6, 7, 35];

    /**
     * Las claves de OpenAI que ya usa otra parte del sistema (M4 de security),
     * lista cerrada: IRON IA y el cerebro legado (`services.openai`), el
     * copiloto del CRM (`iron_crm`, hoy la misma `OPENAI_API_KEY`) y el
     * laboratorio del Studio. Con cualquiera de ellas el clasificador
     * compartiría su proyecto y su presupuesto. Una prueba de la guarda
     * comprueba que son TODAS las de `config/`.
     */
    public const FOREIGN_KEYS = ['services.openai.api_key', 'iron_crm.api_key', 'ultron_studio.lab.openai.api_key'];

    /** Solo compara huellas: no devuelve, no guarda y no registra ninguna clave. */
    public function configured(): bool
    {
        $clave = trim((string) config('outreach.classifier.api_key'));
        if ($clave === '') {
            return false;
        }
        $huella = hash('sha256', $clave);
        foreach (self::FOREIGN_KEYS as $ajena) {
            $otra = config($ajena);
            $otra = is_string($otra) ? trim($otra) : '';
            if ($otra !== '' && hash_equals(hash('sha256', $otra), $huella)) {
                return false;
            }
        }

        return true;
    }

    public function classify(string $model, string $instructions, string $text, array $schema, int $maxOutputTokens, int $timeoutSeconds): array
    {
        if (! $this->configured() || $model === '' || $text === '') {
            return self::result('not_configured');
        }
        $cuerpo = [
            'model' => $model,
            'store' => false,
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => $text]],
            'max_output_tokens' => max(16, min(256, $maxOutputTokens)),
            'text' => ['format' => ['type' => 'json_schema', 'name' => ReplyClassifierPrompt::SCHEMA_NAME, 'strict' => true, 'schema' => $schema]],
        ];

        $inicio = hrtime(true);
        try {
            $respuesta = Http::withToken(trim((string) config('outreach.classifier.api_key')))
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(max(2, min(10, $timeoutSeconds)))
                ->post(self::URL, $cuerpo);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException $e) {
            return self::result(self::connectionClass($e), latencyMs: self::since($inicio));
        } catch (Throwable) {
            // No se sabe si llegó y se cobró: cuenta como tiempo agotado (el clasificador conserva la reserva).
            return self::result('timeout', latencyMs: self::since($inicio));
        }

        return self::read($respuesta->status(), $respuesta->json()) + ['latencyMs' => self::since($inicio)];
    }

    /**
     * Una respuesta HTTP → su clase, la salida (el JSON del modelo, sin
     * interpretar) y el `usage`. PURA.
     *
     * @return array{error: string|null, output: array<mixed>|null, usage: array{inputTokens: int, outputTokens: int}|null}
     */
    public static function read(int $http, mixed $body): array
    {
        $fallo = static fn (string $clase, ?array $usage = null): array => ['error' => $clase, 'output' => null, 'usage' => $usage];
        if ($http < 200 || $http >= 300) {
            return $fallo(match (true) {
                $http === 401, $http === 403 => 'auth',
                $http === 429 => self::isQuota($body) ? 'quota' : 'rate_limited',
                $http === 408, $http >= 500 => 'server',
                default => 'bad_request',
            });
        }
        if (! is_array($body)) {
            return $fallo('invalid_output');
        }
        $uso = self::usage($body['usage'] ?? null);
        if (($body['status'] ?? null) === 'incomplete') {
            return $fallo('incomplete', $uso);
        }
        if (($body['status'] ?? null) !== 'completed') {
            return $fallo('invalid_output', $uso);
        }
        $textos = [];
        foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $parte) {
                if (is_array($parte) && ($parte['type'] ?? null) === 'refusal') {
                    return $fallo('refusal', $uso);
                }
                if (is_array($parte) && ($parte['type'] ?? null) === 'output_text' && is_string($parte['text'] ?? null)) {
                    $textos[] = $parte['text'];
                }
            }
        }
        $salida = $textos === [] ? null : json_decode(implode('', $textos), true, 4);
        if (! is_array($salida)) {
            return $fallo('invalid_output', $uso);
        }

        return ['error' => null, 'output' => $salida, 'usage' => $uso];
    }

    /** @return array{inputTokens: int, outputTokens: int}|null */
    private static function usage(mixed $usage): ?array
    {
        if (! is_array($usage) || ! is_int($usage['input_tokens'] ?? null) || ! is_int($usage['output_tokens'] ?? null)) {
            return null;
        }

        return ['inputTokens' => max(0, $usage['input_tokens']), 'outputTokens' => max(0, $usage['output_tokens'])];
    }

    private static function isQuota(mixed $body): bool
    {
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        return in_array($error['code'] ?? null, self::QUOTA_CODES, true) || in_array($error['type'] ?? null, self::QUOTA_CODES, true);
    }

    /** Tiempo agotado (pudo cobrarse) o sin conexión (no salió), por el código de cURL; lo que no se sabe, tiempo agotado. */
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
            return 'timeout';
        }

        return in_array($errno, self::CURL_NEVER_SENT, true) || ($errno === null && $origen instanceof ConnectException) ? 'connection' : 'timeout';
    }

    private static function since(int $inicio): int
    {
        return intdiv(max(0, hrtime(true) - $inicio), 1_000_000);
    }

    /** @return array{error: string, output: null, usage: null, latencyMs: int} */
    private static function result(string $error, int $latencyMs = 0): array
    {
        return ['error' => $error, 'output' => null, 'usage' => null, 'latencyMs' => $latencyMs];
    }
}
