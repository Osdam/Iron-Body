<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON IA — análisis de imágenes (visión).
 *
 * Arquitectura: Flutter → Laravel → OpenAI. La key vive SOLO en el backend.
 * Usa chat completions con un modelo de visión configurable
 * (config('services.openai.vision_model'); por defecto reusa el modelo de chat,
 * que ya soporta imágenes). La imagen se envía inline como data URL base64 para
 * NO exponer rutas privadas ni depender de que OpenAI alcance nuestro servidor.
 *
 * Nunca lanza: cualquier error → null (el controlador devuelve un mensaje
 * amable). El prompt de seguridad lo arma IronAiService (no diagnósticos
 * médicos, recomendar profesional ante dolor/lesión, etc.).
 */
class IronAiVisionService
{
    /**
     * Completa una conversación de visión.
     *
     * @param  array<int, array<string, mixed>>  $messages  payload chat-completions
     * @return array{content: string, model: ?string, input_tokens: ?int, output_tokens: ?int}|null
     */
    public function complete(array $messages, ?int $maxTokens = null): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        if (empty($cfg['enabled'])) {
            Log::warning('iron-ai visión deshabilitada por configuración');
            return null;
        }
        if (empty($cfg['api_key'])) {
            Log::error('iron-ai sin OPENAI_API_KEY para visión');
            return null;
        }

        $model = $cfg['vision_model'] ?? ($cfg['model'] ?? 'gpt-4.1-mini');
        $max = $maxTokens !== null && $maxTokens > 0
            ? $maxTokens
            : (int) ($cfg['max_tokens'] ?? 700);

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['media_timeout'] ?? 60))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($cfg['base_url'], '/') . '/v1/chat/completions', [
                    'model'       => $model,
                    'messages'    => $messages,
                    'temperature' => (float) ($cfg['temperature'] ?? 0.4),
                    'max_tokens'  => $max,
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('iron-ai visión http error', [
                    'status'      => $response->status(),
                    'latency_ms'  => $latencyMs,
                    'error_class' => 'OpenAIVisionHttpError',
                ]);
                return null;
            }

            $json = $response->json();
            $content = data_get($json, 'choices.0.message.content');
            $content = is_string($content) ? trim($content) : '';

            if ($content === '') {
                Log::error('iron-ai visión respuesta vacía', ['latency_ms' => $latencyMs]);
                return null;
            }

            Log::info('iron-ai visión ok', [
                'endpoint'   => 'chat/completions(vision)',
                'latency_ms' => $latencyMs,
                'model'      => $model,
            ]);

            return [
                'content'       => $content,
                'model'         => data_get($json, 'model', $model),
                'input_tokens'  => data_get($json, 'usage.prompt_tokens'),
                'output_tokens' => data_get($json, 'usage.completion_tokens'),
            ];
        } catch (Throwable $e) {
            Log::error('iron-ai visión exception', [
                'endpoint'    => 'chat/completions(vision)',
                'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }
}
