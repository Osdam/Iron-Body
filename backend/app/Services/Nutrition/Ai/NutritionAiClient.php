<?php

namespace App\Services\Nutrition\Ai;

use Illuminate\Http\Client\ConnectionException;
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
     * @return array{status:string,error_code:?string,content:?string,json:?array,model:?string,usage:?array}
     */
    public function complete(string $model, array $messages, bool $json = true, ?int $maxTokens = null): array
    {
        $openai = config('services.openai');
        if (empty($openai['enabled']) || empty($openai['api_key'])) {
            return $this->fail('ai_unavailable');
        }

        $timeout = (int) config('nutrition.ai.timeout_seconds', 30);
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2, // baja → menos alucinación, más estructura
            'max_tokens'  => $maxTokens && $maxTokens > 0 ? $maxTokens : 900,
        ];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $started = microtime(true);
        try {
            $resp = Http::withToken($openai['api_key'])
                ->timeout($timeout)
                ->acceptJson()->asJson()
                ->post(rtrim($openai['base_url'], '/') . '/v1/chat/completions', $payload);

            $latency = (int) round((microtime(true) - $started) * 1000);

            if ($resp->status() === 429) {
                Log::warning('nutrition:ai:rate_limited', ['model' => $model, 'latency_ms' => $latency]);
                return $this->fail('rate_limited', 'rate_limited');
            }
            if (in_array($resp->status(), [401, 403], true)) {
                Log::error('nutrition:ai:unauthorized', ['status' => $resp->status()]);
                return $this->fail('failed', 'ai_unauthorized');
            }
            if ($resp->failed()) {
                Log::error('nutrition:ai:http_error', ['status' => $resp->status(), 'latency_ms' => $latency]);
                return $this->fail('failed', 'http_error');
            }

            $content = data_get($resp->json(), 'choices.0.message.content');
            $content = is_string($content) ? trim($content) : '';
            if ($content === '') {
                return $this->fail('failed', 'empty_response');
            }

            Log::info('nutrition:ai:ok', ['model' => $model, 'latency_ms' => $latency]);
            return [
                'status'     => 'success',
                'error_code' => null,
                'content'    => $content,
                'json'       => $this->decodeJson($content),
                'model'      => data_get($resp->json(), 'model', $model),
                'usage'      => data_get($resp->json(), 'usage'),
            ];
        } catch (ConnectionException $e) {
            Log::warning('nutrition:ai:timeout', ['model' => $model]);
            return $this->fail('timeout', 'timeout');
        } catch (Throwable $e) {
            Log::error('nutrition:ai:exception', ['error_class' => get_class($e)]);
            return $this->fail('failed', 'exception');
        }
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
        ];
    }
}
