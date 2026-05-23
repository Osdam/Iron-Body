<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON IA — conversación de voz EN VIVO (OpenAI Realtime, GA).
 *
 * Arquitectura: Flutter → Laravel → OpenAI Realtime (WebRTC).
 *  - La API key real vive SOLO aquí. Este servicio acuña un token EFÍMERO
 *    (client_secret `ek_...`, ~1 min) vía POST /v1/realtime/client_secrets.
 *  - Flutter usa SOLO ese token efímero para abrir el WebRTC directo a OpenAI;
 *    nunca ve la API key real.
 *
 * Nunca lanza: cualquier fallo → null (el controlador responde de forma amable
 * y NO presenta la función como disponible).
 */
class IronAiRealtimeService
{
    public function __construct(private readonly IronAiService $ironAi)
    {
    }

    /**
     * Acuña una sesión realtime efímera con las instrucciones de IRON y el
     * contexto real del usuario (según el nivel permitido por su plan).
     *
     * @return array{client_secret: string, expires_at: ?int, model: string, voice: string, webrtc_url: string}|null
     */
    public function createSession(?Member $member, ?User $user, array $capabilities): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        if (empty($cfg['enabled']) || empty($cfg['realtime_enabled'])) {
            Log::warning('iron-ai realtime deshabilitado por configuración');
            return null;
        }
        if (empty($cfg['api_key'])) {
            Log::error('iron-ai sin OPENAI_API_KEY para realtime');
            return null;
        }

        $model = $cfg['realtime_model'] ?? 'gpt-realtime';
        $voice = $cfg['realtime_voice'] ?? 'alloy';
        $instructions = $this->buildInstructions($member, $user, $capabilities);

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['media_timeout'] ?? 60))
                ->acceptJson()
                ->asJson()
                ->post($cfg['realtime_secret_url'], [
                    'session' => [
                        'type'         => 'realtime',
                        'model'        => $model,
                        'instructions' => $instructions,
                        'audio'        => [
                            'input'  => [
                                'transcription'  => ['model' => $cfg['transcription_model'] ?? 'whisper-1'],
                                'turn_detection' => ['type' => 'server_vad'],
                            ],
                            'output' => ['voice' => $voice],
                        ],
                    ],
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('iron-ai realtime http error', [
                    'status'      => $response->status(),
                    'latency_ms'  => $latencyMs,
                    'error_class' => 'OpenAIRealtimeHttpError',
                ]);
                return null;
            }

            $json = $response->json();
            // GA: el token efímero viene en "value"; "session" trae la config.
            $secret = $json['value'] ?? data_get($json, 'client_secret.value');
            if (! is_string($secret) || $secret === '') {
                Log::error('iron-ai realtime sin client_secret', ['latency_ms' => $latencyMs]);
                return null;
            }

            Log::info('iron-ai realtime session ok', [
                'user_id'    => $user?->id,
                'member_id'  => $member?->id,
                'latency_ms' => $latencyMs,
                'model'      => $model,
            ]);

            return [
                'client_secret' => $secret,
                'expires_at'    => $json['expires_at'] ?? data_get($json, 'client_secret.expires_at'),
                'model'         => data_get($json, 'session.model', $model),
                'voice'         => data_get($json, 'session.audio.output.voice', $voice),
                'webrtc_url'    => $cfg['realtime_webrtc_url'],
            ];
        } catch (Throwable $e) {
            Log::error('iron-ai realtime exception', [
                'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }

    /** Instrucciones de IRON para realtime: prompt oficial + contexto + estilo hablado. */
    private function buildInstructions(?Member $member, ?User $user, array $capabilities): string
    {
        $level = $capabilities['context_level'] ?? 'full';
        $instructions = $this->ironAi->systemPrompt();

        $context = $this->ironAi->buildUserContext($member, $user, $level);
        if ($context !== '') {
            $instructions .= "\n\nCONTEXTO DEL USUARIO (datos reales; no inventes lo que no esté aquí):\n" . $context;
        }

        // Estilo conversación en vivo (voz): natural, breve, sin formato markdown.
        $instructions .= "\n\nEstás en una CONVERSACIÓN DE VOZ EN VIVO. Habla en español, "
            . "con frases naturales y breves, tono cercano y motivador. No uses listas "
            . "ni markdown ni emojis; responde como en una llamada. Si el usuario hace "
            . "una pausa, espera. Mantén el foco en fitness, entrenamiento y Iron Body.";

        return $instructions;
    }
}
