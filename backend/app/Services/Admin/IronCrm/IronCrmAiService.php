<?php

namespace App\Services\Admin\IronCrm;

use App\Models\Admin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IRON — copiloto administrativo del CRM de Iron Body Neiva (OpenAI).
 *
 * Orquesta la conversación: arma el prompt del sistema + contexto real del CRM,
 * llama a OpenAI con "function calling" y, cuando el modelo pide una
 * herramienta, la resuelve con {@see IronCrmToolService} (SOLO LECTURA) y
 * devuelve el resultado al modelo. Repite hasta que el modelo responde en texto
 * o se agota el presupuesto de iteraciones.
 *
 * Seguridad:
 *  - La API key vive SOLO aquí (config/iron_crm → env). El frontend nunca la ve.
 *  - El modelo NO ejecuta SQL: solo puede llamar herramientas fijas y acotadas.
 *  - Ningún error crudo/stack se expone al frontend (siempre FRIENDLY_ERROR).
 */
class IronCrmAiService
{
    public const FRIENDLY_ERROR =
        'IRON no está disponible en este momento. Intenta nuevamente en unos minutos.';

    private const SYSTEM_PROMPT = <<<'TXT'
Eres IRON, el copiloto administrativo del CRM de Iron Body Neiva. Ayudas al equipo administrativo a consultar información real del CRM sobre miembros, membresías, pagos, facturación, entrenadores, clases, reservas, planes, marketing, soporte, contratos, reportes y operación interna. Respondes en español, con tono profesional, directo y útil. No inventas datos. Cuando una respuesta depende de información del CRM, debes basarte en las herramientas internas o el contexto entregado por el backend. Si no hay datos suficientes, dilo claramente. No reveles información que el usuario autenticado no esté autorizado a ver. No ejecutes acciones destructivas. No crees, modifiques ni elimines registros. Tus respuestas son administrativas y operativas; no constituyen asesoría médica, legal ni financiera.

Reglas de trabajo:
- Usa las herramientas disponibles para obtener datos reales antes de afirmar cifras, estados o vencimientos. No estimes ni supongas números.
- Si una herramienta responde "available": false, informa con honestidad que ese módulo no está disponible en esta instancia.
- Si hay varias coincidencias de un miembro, pide al usuario que precise (documento, correo o teléfono).
- Formatea montos en pesos colombianos (COP) y fechas en formato claro (dd/mm/aaaa).
- Sé conciso: usa listas o tablas cortas cuando ayuden a la lectura.
- Estás en modo SOLO LECTURA. Si te piden crear, editar, cobrar, renovar, enviar mensajes o borrar algo, explica que en esta fase solo puedes consultar y resumir información.
TXT;

    public function __construct(
        private readonly IronCrmToolService $tools,
        private readonly IronCrmContextService $context,
    ) {}

    /**
     * Procesa un mensaje del copiloto. Recibe el mensaje del usuario, el
     * historial reciente (rol/contenido) y una imagen opcional (data URL).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{ok: bool, reply: string, tools_used: array<int, string>, model: ?string}
     */
    public function chat(string $message, array $history, ?Admin $admin, ?string $imageDataUrl = null): array
    {
        $cfg = config('iron_crm');

        if (empty($cfg['enabled'])) {
            return $this->fail('IRON está deshabilitado por configuración.');
        }
        if (empty($cfg['api_key'])) {
            Log::error('iron-crm sin OPENAI_API_KEY configurada');

            return $this->fail(self::FRIENDLY_ERROR);
        }

        $messages = $this->buildMessages($message, $history, $admin, $imageDataUrl);
        $toolDefs = $this->tools->toolDefinitions();
        $toolsUsed = [];
        $maxIterations = max(1, (int) ($cfg['max_tool_iterations'] ?? 4));

        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $response = $this->callOpenAi($messages, $toolDefs, $cfg);
                if ($response === null) {
                    return $this->fail(self::FRIENDLY_ERROR, $toolsUsed);
                }

                $choice = data_get($response, 'choices.0.message', []);
                $toolCalls = $choice['tool_calls'] ?? [];

                if (empty($toolCalls)) {
                    $reply = trim((string) ($choice['content'] ?? ''));
                    if ($reply === '') {
                        return $this->fail(self::FRIENDLY_ERROR, $toolsUsed);
                    }

                    return [
                        'ok' => true,
                        'reply' => $reply,
                        'tools_used' => array_values(array_unique($toolsUsed)),
                        'model' => data_get($response, 'model', $cfg['model'] ?? null),
                    ];
                }

                // El modelo pidió herramientas: se ejecutan y se re-inyecta el
                // resultado. Se debe re-adjuntar el mensaje del asistente con las
                // tool_calls antes de las respuestas de las tools.
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $choice['content'] ?? null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $call) {
                    $name = data_get($call, 'function.name', '');
                    $rawArgs = data_get($call, 'function.arguments', '{}');
                    $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);
                    $toolsUsed[] = $name;

                    $result = $this->tools->run($name, is_array($args) ? $args : []);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => data_get($call, 'id', ''),
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                }
            }

            // Se agotaron las iteraciones sin respuesta textual: última llamada
            // SIN herramientas para forzar un cierre en texto.
            $final = $this->callOpenAi($messages, null, $cfg);
            $reply = trim((string) data_get($final, 'choices.0.message.content', ''));

            return $reply !== ''
                ? ['ok' => true, 'reply' => $reply, 'tools_used' => array_values(array_unique($toolsUsed)), 'model' => data_get($final, 'model', $cfg['model'] ?? null)]
                : $this->fail(self::FRIENDLY_ERROR, $toolsUsed);
        } catch (Throwable $e) {
            Log::error('iron-crm chat exception', ['error_class' => get_class($e)]);

            return $this->fail(self::FRIENDLY_ERROR, $toolsUsed);
        }
    }

    /**
     * Construye el arreglo de mensajes para OpenAI: system + contexto + historial
     * saneado + mensaje actual (con imagen opcional).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(string $message, array $history, ?Admin $admin, ?string $imageDataUrl): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'system', 'content' => $this->context->build($admin)],
        ];

        $maxHistory = max(0, (int) config('iron_crm.max_history_messages', 12));
        $recent = array_slice($history, -$maxHistory);
        foreach ($recent as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        if ($imageDataUrl !== null && ! empty(config('iron_crm.image.enabled'))) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $message],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                ],
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $message];
        }

        return $messages;
    }

    /**
     * Llama a OpenAI (chat completions). Devuelve el JSON decodificado o null si
     * falla. Nunca registra el cuerpo crudo (puede traer PII) ni la API key.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $toolDefs
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>|null
     */
    private function callOpenAi(array $messages, ?array $toolDefs, array $cfg): ?array
    {
        $started = microtime(true);

        $payload = [
            'model' => $cfg['model'] ?? 'gpt-4.1',
            'messages' => $messages,
            'temperature' => (float) ($cfg['temperature'] ?? 0.2),
            'max_tokens' => (int) ($cfg['max_tokens'] ?? 900),
        ];
        if (! empty($toolDefs)) {
            $payload['tools'] = $toolDefs;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['timeout'] ?? 45))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($cfg['base_url'], '/').'/v1/chat/completions', $payload);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                Log::error('iron-crm openai http error', [
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::error('iron-crm openai exception', [
                'error_class' => get_class($e),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, string>  $toolsUsed
     * @return array{ok: bool, reply: string, tools_used: array<int, string>, model: null}
     */
    private function fail(string $reply, array $toolsUsed = []): array
    {
        return [
            'ok' => false,
            'reply' => $reply,
            'tools_used' => array_values(array_unique($toolsUsed)),
            'model' => null,
        ];
    }
}
