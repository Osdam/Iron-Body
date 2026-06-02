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
     * Guía de VISIÓN para la conversación realtime multimodal (voz + cámara).
     *
     * Se anexa SOLO cuando la sesión se acuña en modo visión ($vision = true),
     * es decir cuando Flutter abre la experiencia de cámara en tiempo real. El
     * modelo recibe frames de la cámara como `input_image` por el data channel
     * (directo a OpenAI; nunca pasan por el backend). Aquí solo definimos cómo
     * debe COMPORTARSE al observar.
     */
    private const VISION_PROMPT = <<<'TXT'

Además de la voz, ahora PUEDES VER lo que muestra la cámara del usuario en tiempo real (te llegan imágenes de su cámara). Eres un entrenador inteligente visual de Iron Body. Reglas de visión:
- Si ves equipo de gimnasio (mancuernas, barras, discos, máquinas, kettlebells, bandas), identifícalo y explica brevemente para qué sirve y qué músculos trabaja, en español.
- Si ves a la persona haciendo o preparando un ejercicio, da retroalimentación general y segura sobre postura, técnica, alineación y rango de movimiento.
- Si la persona se pone frente a un espejo o pregunta "¿cómo me veo?", responde SIEMPRE desde una perspectiva fitness: postura, alineación, energía, consistencia y objetivos de entrenamiento. Sé respetuoso y motivador.
- NUNCA hagas comentarios sobre atractivo físico, peso, forma del cuerpo, ni juicios corporales. NUNCA te burles ni avergüences a la persona.
- NO diagnostiques lesiones ni enfermedades. NO des consejos médicos definitivos. Si observas dolor, riesgo o algo que parezca una lesión, recomienda con amabilidad consultar a un profesional de la salud.
- Describe solo lo que realmente ves. Si la imagen no es clara, está oscura o no estás seguro de lo que observas, dilo con naturalidad y pide que acerque o mejore la toma. No inventes objetos ni detalles que no aparezcan.
- Mantén el foco en fitness, entrenamiento, técnica y uso de Iron Body. Responde de forma breve y natural, como en una llamada.
TXT;

    /**
     * Acuña una sesión realtime efímera con las instrucciones de IRON y el
     * contexto real del usuario (según el nivel permitido por su plan).
     *
     * @param  bool  $vision  Sesión multimodal con cámara (voz + visión). Cuando
     *                        es true se anexa la guía de visión a las instrucciones.
     *
     * @return array{client_secret: string, expires_at: ?int, model: string, voice: string, webrtc_url: string}|null
     */
    public function createSession(?Member $member, ?User $user, array $capabilities, bool $vision = false): ?array
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
        $instructions = $this->buildInstructions($member, $user, $capabilities, $vision);

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

    /** Instrucciones de IRON para realtime: prompt oficial + contexto + estilo hablado (+ visión). */
    private function buildInstructions(?Member $member, ?User $user, array $capabilities, bool $vision = false): string
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

        // Modo multimodal con cámara: añade la guía de visión segura.
        if ($vision) {
            $instructions .= self::VISION_PROMPT;
        }

        return $instructions;
    }
}
