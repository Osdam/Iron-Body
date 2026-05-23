<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON IA — transcripción de audio (chat por voz).
 *
 * Arquitectura: Flutter → Laravel → OpenAI. La key vive SOLO en el backend.
 * Llama al endpoint de transcripción de OpenAI (whisper-1 u otro modelo
 * configurable en config('services.openai.transcription_model')).
 *
 * Nunca lanza: cualquier error → null (el controlador devuelve un mensaje
 * amable). No registra cuerpos crudos ni datos sensibles.
 */
class IronAiTranscriptionService
{
    /**
     * Transcribe un archivo de audio ubicado en $absolutePath.
     *
     * @return array{text: string, model: ?string}|null
     */
    public function transcribe(string $absolutePath, string $originalName = 'audio.m4a'): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        if (empty($cfg['enabled'])) {
            Log::warning('iron-ai transcripción deshabilitada por configuración');
            return null;
        }
        if (empty($cfg['api_key'])) {
            Log::error('iron-ai sin OPENAI_API_KEY para transcripción');
            return null;
        }
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            Log::error('iron-ai audio no legible para transcripción');
            return null;
        }

        $model = $cfg['transcription_model'] ?? 'whisper-1';

        try {
            $contents = @file_get_contents($absolutePath);
            if ($contents === false || $contents === '') {
                return null;
            }

            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['media_timeout'] ?? 60))
                ->attach('file', $contents, $this->safeName($originalName))
                ->post(rtrim($cfg['base_url'], '/') . '/v1/audio/transcriptions', [
                    'model'    => $model,
                    'language' => 'es',
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('iron-ai transcripción http error', [
                    'status'      => $response->status(),
                    'latency_ms'  => $latencyMs,
                    'error_class' => 'OpenAITranscriptionHttpError',
                ]);
                return null;
            }

            $json = $response->json();
            $text = is_array($json) ? ($json['text'] ?? null) : null;
            $text = is_string($text) ? trim($text) : '';

            if ($text === '') {
                Log::error('iron-ai transcripción vacía', ['latency_ms' => $latencyMs]);
                return null;
            }

            Log::info('iron-ai transcripción ok', [
                'endpoint'   => 'audio/transcriptions',
                'latency_ms' => $latencyMs,
                'model'      => $model,
            ]);

            return ['text' => $text, 'model' => $model];
        } catch (Throwable $e) {
            Log::error('iron-ai transcripción exception', [
                'endpoint'    => 'audio/transcriptions',
                'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }

    private function safeName(string $name): string
    {
        $base = basename($name);
        return $base !== '' ? $base : 'audio.m4a';
    }
}
