<?php

namespace App\Services\Nutrition\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente OpenAI para Nutrición. Reutiliza la key/base_url de
 * `config('services.openai')` (igual que IRON IA) — la key vive SOLO en el
 * backend. Devuelve SIEMPRE un resultado controlado con `error_code`; nunca
 * lanza. Soporta modo JSON estricto (response_format json_object).
 *
 * No rompe IA Live: es una abstracción independiente para Nutrición.
 */
class NutritionAiClient
{
    /**
     * @param  array<int,array<string,mixed>>  $messages  payload chat-completions
     * @return array{status:string,error_code:?string,content:?string,json:?array,model:?string,usage:?array,finish_reason:?string,latency_ms:?int}
     */
    /**
     * El ÚNICO valor de `status` que significa que el modelo respondió.
     *
     * Es constante y no un literal suelto porque quien consume esta respuesta
     * tiene que compararlo, y una cadena escrita a mano en el otro extremo es
     * exactamente cómo se rompió la generación del plan integral: el servicio
     * comparaba contra 'ok', el cliente devolvía 'success', y TODA generación
     * —incluidas las que OpenAI contestaba perfectamente— se registraba como
     * fallo `upstream`.
     */
    public const STATUS_SUCCESS = 'success';

    public function complete(string $model, array $messages, bool $json = true, ?int $maxTokens = null): array
    {
        $openai = config('services.openai');
        if (empty($openai['enabled']) || empty($openai['api_key'])) {
            return $this->fail('ai_unavailable');
        }

        $timeout = (int) config('nutrition.ai.timeout_seconds', 30);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2, // baja → menos alucinación, más estructura
            'max_tokens' => $maxTokens && $maxTokens > 0 ? $maxTokens : 900,
        ];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $started = microtime(true);
        try {
            $resp = Http::withToken($openai['api_key'])
                ->timeout($timeout)
                ->acceptJson()->asJson()
                ->post(rtrim($openai['base_url'], '/').'/v1/chat/completions', $payload);

            $latency = (int) round((microtime(true) - $started) * 1000);

            return $this->interpret($resp, $model, $latency);
        } catch (ConnectionException $e) {
            Log::warning('nutrition:ai:timeout', ['model' => $model]);

            return $this->fail('timeout', 'timeout');
        } catch (Throwable $e) {
            Log::error('nutrition:ai:exception', ['error_class' => get_class($e)]);

            return $this->fail('failed', 'exception');
        }
    }

    /**
     * Varias llamadas A LA VEZ (`Http::pool`), con un plazo común.
     *
     * Lo usa el plan integral por niveles (docs/iron-ia/POTENCIAS-IRON-IA.md
     * §4): cada sección es una petición independiente con su propio esquema, y
     * el plazo es el que le queda a la generación entera, no uno por llamada.
     * Por eso `$timeoutSeconds` es float: con 17 s de plazo y 4 ya gastados,
     * cada petición tiene 13, no 30.
     *
     * Cada petición: `messages`, `max_tokens`, `schema` (`name` + `schema`,
     * Structured Outputs estricto) y opcionalmente `temperature`.
     *
     * Igual que {@see complete()}: NUNCA lanza; cada clave vuelve con su
     * resultado controlado, `finish_reason` y `latency_ms` incluidos.
     *
     * @param  array<string, array{messages: list<array<string,mixed>>, max_tokens: int, schema: array{name: string, schema: array<string,mixed>}, temperature?: float}>  $requests
     * @return array<string, array{status:string,error_code:?string,content:?string,json:?array,model:?string,usage:?array,finish_reason:?string,latency_ms:?int}>
     */
    public function completeMany(string $model, array $requests, float $timeoutSeconds): array
    {
        if ($requests === []) {
            return [];
        }

        $openai = config('services.openai');
        if (empty($openai['enabled']) || empty($openai['api_key'])) {
            return array_map(fn () => $this->fail('ai_unavailable'), $requests);
        }

        // Nunca infinito ni cero: Guzzle toma 0 como «sin límite».
        $timeout = max(0.5, $timeoutSeconds);
        $url = rtrim($openai['base_url'], '/').'/v1/chat/completions';
        $started = microtime(true);

        try {
            $respuestas = Http::pool(function (Pool $pool) use ($requests, $openai, $timeout, $url, $model) {
                foreach ($requests as $clave => $r) {
                    $pool->as((string) $clave)
                        ->withToken($openai['api_key'])
                        ->timeout($timeout)
                        ->connectTimeout(min(5.0, $timeout))
                        ->acceptJson()->asJson()
                        ->post($url, $this->sectionPayload($model, $r));
                }
            });
        } catch (Throwable $e) {
            // El pool en sí no debería lanzar (cada fallo vuelve como valor),
            // pero si lo hace no puede tumbar la petición del entrenador.
            Log::error('nutrition:ai:pool_exception', ['error_class' => get_class($e)]);

            return array_map(fn () => $this->fail('failed', 'exception'), $requests);
        }

        $total = (int) round((microtime(true) - $started) * 1000);
        $out = [];

        foreach ($requests as $clave => $_) {
            $r = $respuestas[(string) $clave] ?? null;

            if ($r instanceof Response) {
                $segundos = $r->transferStats?->getTransferTime();
                $latencia = $segundos !== null ? (int) round($segundos * 1000) : $total;
                $out[$clave] = $this->interpret($r, $model, $latencia);

                continue;
            }

            if ($r instanceof ConnectionException) {
                Log::warning('nutrition:ai:timeout', ['model' => $model, 'section' => (string) $clave]);
                $out[$clave] = array_merge($this->fail('timeout', 'timeout'), ['latency_ms' => $total]);

                continue;
            }

            Log::error('nutrition:ai:exception', [
                'section' => (string) $clave,
                'error_class' => is_object($r) ? get_class($r) : gettype($r),
            ]);
            $out[$clave] = array_merge($this->fail('failed', 'exception'), ['latency_ms' => $total]);
        }

        return $out;
    }

    /**
     * Cuerpo de una sección. Structured Outputs ESTRICTO: el modelo no puede
     * devolver otra forma, y lo que sí puede pasar —que se corte por longitud o
     * que se niegue— se lee de `finish_reason` y de `refusal`.
     *
     * @param  array{messages: list<array<string,mixed>>, max_tokens: int, schema: array{name: string, schema: array<string,mixed>}, temperature?: float}  $r
     * @return array<string,mixed>
     */
    private function sectionPayload(string $model, array $r): array
    {
        return [
            'model' => $model,
            'messages' => $r['messages'],
            'temperature' => $r['temperature'] ?? 0.2,
            'max_tokens' => max(1, (int) $r['max_tokens']),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $r['schema']['name'],
                    'strict' => true,
                    'schema' => $r['schema']['schema'],
                ],
            ],
        ];
    }

    /**
     * Lee una respuesta de Chat Completions.
     *
     * `finish_reason` viaja SIEMPRE en el resultado: un `length` significa que
     * el texto se cortó por el tope de tokens, y quien consume tiene que
     * saberlo antes de fiarse del JSON (antes se descartaba y una respuesta
     * truncada se confundía con una mal formada).
     *
     * @return array{status:string,error_code:?string,content:?string,json:?array,model:?string,usage:?array,finish_reason:?string,latency_ms:?int}
     */
    private function interpret(Response $resp, string $model, int $latency): array
    {
        if ($resp->status() === 429) {
            Log::warning('nutrition:ai:rate_limited', ['model' => $model, 'latency_ms' => $latency]);

            return array_merge($this->fail('rate_limited', 'rate_limited'), ['latency_ms' => $latency]);
        }
        if (in_array($resp->status(), [401, 403], true)) {
            Log::error('nutrition:ai:unauthorized', ['status' => $resp->status()]);

            return array_merge($this->fail('failed', 'ai_unauthorized'), ['latency_ms' => $latency]);
        }
        if ($resp->failed()) {
            Log::error('nutrition:ai:http_error', ['status' => $resp->status(), 'latency_ms' => $latency]);
            // Si el proveedor informa de tokens aunque falle, también se pagan.
            $usage = data_get($resp->json(), 'usage');

            return array_merge($this->fail('failed', 'http_error'), ['latency_ms' => $latency, 'usage' => is_array($usage) ? $usage : null]);
        }

        $body = $resp->json();
        $finish = data_get($body, 'choices.0.finish_reason');
        $finish = is_string($finish) ? $finish : null;
        $usage = data_get($body, 'usage');
        $usage = is_array($usage) ? $usage : null;

        $content = data_get($body, 'choices.0.message.content');
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            // Con Structured Outputs, una negativa llega como `refusal` y sin
            // contenido. Se distingue de una respuesta vacía para el registro.
            $code = is_string(data_get($body, 'choices.0.message.refusal')) ? 'refusal' : 'empty_response';

            return array_merge($this->fail('failed', $code), [
                'usage' => $usage, 'finish_reason' => $finish, 'latency_ms' => $latency,
            ]);
        }

        Log::info('nutrition:ai:ok', ['model' => $model, 'latency_ms' => $latency, 'finish_reason' => $finish]);

        return [
            'status' => self::STATUS_SUCCESS,
            'error_code' => null,
            'content' => $content,
            'json' => $this->decodeJson($content),
            'model' => data_get($body, 'model', $model),
            'usage' => $usage,
            'finish_reason' => $finish,
            'latency_ms' => $latency,
        ];
    }

    /** Construye el bloque de imagen (data URL base64) para visión. */
    public function imageContent(string $base64DataUrl): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => $base64DataUrl]];
    }

    private function decodeJson(string $content): ?array
    {
        // Algunos modelos envuelven en ```json … ```; lo limpiamos.
        $clean = preg_replace('/^```(?:json)?|```$/m', '', trim($content));
        $data = json_decode(trim((string) $clean), true);

        return is_array($data) ? $data : null;
    }

    private function fail(string $status, ?string $code = null): array
    {
        return [
            'status' => $status, 'error_code' => $code ?? $status,
            'content' => null, 'json' => null, 'model' => null, 'usage' => null,
            'finish_reason' => null, 'latency_ms' => null,
        ];
    }
}
