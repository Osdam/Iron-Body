<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceAgentTarget;
use InvalidArgumentException;

/**
 * De la especificación NEUTRA a la carga de ElevenLabs (contrato H8, D8-6
 * «Salidas» y D8-7). PURO: sin configuración, base, reloj ni red; la misma
 * entrada da siempre la misma carga y las mismas huellas.
 *
 * LO QUE FIJA, y por qué (referencia oficial consultada el 03-11-2026: los
 * valores por defecto de ElevenLabs son abiertos, así que todo lo que protege
 * va explícito):
 *  - `call_limits`: la concurrencia y el diario del {@see VoiceAgentTarget} y
 *    la ráfaga APAGADA (por defecto: sin límite de concurrencia, 100 000 al día
 *    y ráfaga encendida);
 *  - `privacy`: `record_voice = false` (por defecto true), `delete_audio`, y la
 *    retención de la especificación (por defecto ilimitada) [P-7, P-8];
 *  - las sobrescrituras CERRADAS (D8-11) y el webhook de iniciación encendido,
 *    con su URL fija y el secreto por referencia;
 *  - los eventos post-llamada SIN audio cuando hay webhook (D8-16);
 *  - `auth.enable_auth = true`: nadie empieza una conversación web sin una
 *    URL firmada por Laravel (el laboratorio, D8-23);
 *  - μ-law 8000 en los dos sentidos, el idioma, el modelo de voz y el LLM
 *    fijados en el código ({@see TTS_MODEL} y {@see LLM}, versionados con
 *    {@see VERSION}): un cambio es un commit revisado y otro agente.
 *
 * LAS HERRAMIENTAS de servidor son recursos aparte (`POST /v1/convai/tools`;
 * el campo `tools` en línea está obsoleto): la carga lleva su configuración por
 * nombre y el desplegador las crea antes que el agente, al que pasa sus ids. Su
 * cuerpo no lo rellena el modelo: los ids de la llamada salen de variables
 * (`system__conversation_id`, `system__call_sid` y `call_ref`) [C-A3, por
 * confirmar con la cuenta real].
 *
 * LA PROYECCIÓN ({@see projection()}) es lo que se compara para la deriva: la
 * MISMA función se aplica a la carga compilada y a lo que devuelve el GET del
 * agente y de sus herramientas, y solo lee lo que controla el compilador, así
 * que los valores por defecto que añada ElevenLabs no cuentan, pero un cambio
 * en un campo controlado, una sobrescritura abierta, una herramienta de más o
 * un webhook cambiado sí. El nombre y las etiquetas (que dependen del
 * propósito) no entran: el laboratorio y la publicación comparten proyección.
 * Ningún secreto entra en ella: de una cabecera solo cuenta si es una
 * referencia del gestor de secretos (con su id) o un literal (sin su valor).
 */
final class ElevenLabsAgentMapper
{
    public const VERSION = 'elevenlabs-agent-mapper/1';

    /** [por confirmar con el propietario y PERF8: coste, latencia y calidad en español]. Del enum de la referencia oficial. */
    public const LLM = 'gemini-2.5-flash';

    /** [por confirmar en el laboratorio hablado]. Multilingüe y de baja latencia; del enum de la referencia oficial. */
    public const TTS_MODEL = 'eleven_flash_v2_5';

    public const AUDIO_FORMAT = ElevenLabsVoiceProvider::ULAW_8000;

    public const TEMPERATURE = 0;

    /** El mínimo que admite ElevenLabs (5–300 s); el presupuesto de las herramientas es p95 < 500 ms. */
    public const TOOL_RESPONSE_TIMEOUT_SECONDS = 5;

    /** Las rutas de Laravel que llama ElevenLabs (D8-16). La base es la de {@see VoiceAgentTarget}. */
    public const TOOLS_PATH = '/api/voice/tools/';

    public const INITIATION_PATH = '/api/webhooks/voice/elevenlabs/initiation';

    /** La cabecera de las herramientas: el secreto del gestor vale «Bearer …» (D8-16). */
    public const TOOL_AUTH_HEADER = 'Authorization';

    /** La cabecera fija del webhook de iniciación (D8-16); la lee la telefonía con `hash_equals`. */
    public const INITIATION_AUTH_HEADER = 'X-Voice-Initiation-Secret';

    /** Los eventos post-llamada del agente, en orden de bytes: sin `audio` (D8-16). */
    public const POST_CALL_EVENTS = ['call_initiation_failure', 'transcript'];

    /** El cuerpo de cada herramienta: ids de la llamada, nunca del modelo [C-A3]. */
    public const TOOL_BODY_VARIABLES = [
        'call_ref' => VoiceAgentSpec::VAR_CALL_REF,
        'call_sid' => 'system__call_sid',
        'conversation_id' => 'system__conversation_id',
    ];

    public const TRANSFER_TYPE = 'conference';

    public const NAME_PREFIX = 'Iron Body · voz';

    public const TAG = 'ironbody-voice';

    /**
     * La carga completa: el cuerpo del agente, las herramientas y la proyección.
     *
     * @throws InvalidArgumentException si falta una referencia de secreto o un número de un destino
     */
    public static function payload(VoiceAgentSpec $spec, VoiceAgentTarget $target): VoiceAgentPayload
    {
        $herramientas = self::toolConfigs($spec, $target);
        $agente = self::agentBody($spec, $target);

        return new VoiceAgentPayload(
            provider: VoiceAgentPayload::PROVIDER_ELEVENLABS,
            mapperVersion: self::VERSION,
            specHash: $spec->specHash,
            purpose: $target->purpose,
            agentBody: $agente,
            tools: $herramientas,
            projection: self::projection($agente, $herramientas),
        );
    }

    /**
     * El cuerpo de `POST /v1/convai/agents/create`, sin `tool_ids` (los pone
     * el desplegador al crear las herramientas).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function agentBody(VoiceAgentSpec $spec, VoiceAgentTarget $target): array
    {
        if ($target->initiationAuthSecretRef === null) {
            throw new InvalidArgumentException('Falta la referencia del secreto del webhook de iniciación.');
        }

        $plataforma = [
            'call_limits' => [
                'agent_concurrency_limit' => $target->concurrencyLimit,
                'daily_limit' => $target->dailyCallLimit,
                'bursting_enabled' => false,
            ],
            'privacy' => [
                'record_voice' => false,
                'retention_days' => $spec->retentionDays(),
                'delete_audio' => true,
                'delete_transcript_and_pii' => false,
                'apply_to_existing_conversations' => false,
                'zero_retention_mode' => false,
            ],
            'overrides' => [
                'conversation_config_override' => [
                    'agent' => ['first_message' => false, 'language' => false, 'prompt' => ['prompt' => false]],
                    'tts' => ['voice_id' => false],
                ],
                'custom_llm_extra_body' => false,
                'enable_conversation_initiation_client_data_from_webhook' => true,
            ],
            'workspace_overrides' => [
                'conversation_initiation_client_data_webhook' => [
                    'url' => $target->webhookBaseUrl.self::INITIATION_PATH,
                    'request_headers' => [self::INITIATION_AUTH_HEADER => ['secret_id' => $target->initiationAuthSecretRef]],
                ],
            ],
            'auth' => ['enable_auth' => true, 'allowlist' => []],
        ];
        if ($target->postCallWebhookRef !== null) {
            $plataforma['workspace_overrides']['webhooks'] = [
                'post_call_webhook_id' => $target->postCallWebhookRef,
                'events' => self::POST_CALL_EVENTS,
            ];
        }

        $corto = substr($spec->specHash, 0, 12);

        return [
            'name' => self::NAME_PREFIX.' · '.$target->purpose.' · '.$corto,
            'tags' => [self::TAG, self::TAG.'-'.$target->purpose, 'spec-'.$corto],
            'conversation_config' => [
                'agent' => [
                    'first_message' => $spec->firstMessage(),
                    'language' => $spec->language(),
                    'dynamic_variables' => ['dynamic_variable_placeholders' => $spec->dynamicVariableDefaults()],
                    'prompt' => [
                        'prompt' => $spec->systemPrompt(),
                        'llm' => self::LLM,
                        'temperature' => self::TEMPERATURE,
                        'built_in_tools' => self::builtInTools($spec, $target),
                    ],
                ],
                'tts' => ['voice_id' => $spec->voiceId(), 'model_id' => self::TTS_MODEL, 'agent_output_audio_format' => self::AUDIO_FORMAT],
                'asr' => ['user_input_audio_format' => self::AUDIO_FORMAT],
                'conversation' => ['max_duration_seconds' => $spec->maxDurationSeconds()],
            ],
            'platform_settings' => $plataforma,
        ];
    }

    /**
     * La `tool_config` de cada herramienta de servidor, por nombre (el cuerpo
     * de `POST /v1/convai/tools` es `{tool_config: …}`).
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function toolConfigs(VoiceAgentSpec $spec, VoiceAgentTarget $target): array
    {
        $herramientas = $spec->tools();
        if ($herramientas !== [] && $target->toolAuthSecretRef === null) {
            throw new InvalidArgumentException('Falta la referencia del secreto de las herramientas.');
        }

        $propiedades = [];
        foreach (self::TOOL_BODY_VARIABLES as $campo => $variable) {
            $propiedades[$campo] = ['type' => 'string', 'dynamic_variable' => $variable];
        }

        $out = [];
        foreach ($herramientas as $h) {
            $out[$h['name']] = [
                'type' => 'webhook',
                'name' => $h['name'],
                'description' => $h['description'],
                'response_timeout_secs' => self::TOOL_RESPONSE_TIMEOUT_SECONDS,
                'api_schema' => [
                    'url' => $target->webhookBaseUrl.self::TOOLS_PATH.$h['name'],
                    'method' => 'POST',
                    'content_type' => 'application/json',
                    'request_headers' => [self::TOOL_AUTH_HEADER => ['secret_id' => $target->toolAuthSecretRef]],
                    'request_body_schema' => [
                        'type' => 'object',
                        'properties' => $propiedades,
                        'required' => ['conversation_id'],
                    ],
                ],
            ];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * La proyección de deriva de un agente y sus herramientas: la misma para la
     * carga compilada y para lo observado (GET del agente; `tool_config` de
     * cada herramienta, por nombre). Solo valores escalares y listas: los
     * decimales se normalizan (un entero escrito `0.0` es `0`; otro decimal, su
     * texto) para que la huella canónica los admita.
     *
     * @param  array<string, mixed>  $agent
     * @param  array<string, mixed>  $tools  nombre → tool_config
     * @return array<string, mixed>
     */
    public static function projection(array $agent, array $tools): array
    {
        $cc = self::arr($agent['conversation_config'] ?? null);
        $ps = self::arr($agent['platform_settings'] ?? null);
        $prompt = self::arr(self::at($cc, 'agent.prompt'));

        $herramientas = [];
        foreach ($tools as $nombre => $config) {
            $config = self::arr($config);
            $api = self::arr($config['api_schema'] ?? null);
            $cuerpo = self::arr($api['request_body_schema'] ?? null);
            $propiedades = [];
            foreach (self::arr($cuerpo['properties'] ?? null) as $campo => $definicion) {
                $definicion = self::arr($definicion);
                $propiedades[(string) $campo] = [
                    'type' => self::scalar($definicion['type'] ?? null),
                    'dynamicVariable' => self::scalar($definicion['dynamic_variable'] ?? null),
                    'llmFilled' => array_key_exists('description', $definicion) && ! array_key_exists('dynamic_variable', $definicion),
                ];
            }
            ksort($propiedades, SORT_STRING);
            $herramientas[(string) $nombre] = [
                'type' => self::scalar($config['type'] ?? null),
                'name' => self::scalar($config['name'] ?? null),
                'description' => self::scalar($config['description'] ?? null),
                'responseTimeoutSeconds' => self::number($config['response_timeout_secs'] ?? null),
                'url' => self::scalar($api['url'] ?? null),
                'method' => self::scalar($api['method'] ?? null),
                'headers' => self::headers($api['request_headers'] ?? null),
                'bodyProperties' => $propiedades,
                'bodyRequired' => self::sortedStrings($cuerpo['required'] ?? null),
            ];
        }
        ksort($herramientas, SORT_STRING);

        $webhookPostLlamada = self::at($ps, 'workspace_overrides.webhooks');
        $idWebhook = is_array($webhookPostLlamada) ? self::scalar($webhookPostLlamada['post_call_webhook_id'] ?? null) : null;

        return [
            'agent' => [
                'firstMessage' => self::scalar(self::at($cc, 'agent.first_message')),
                'language' => self::scalar(self::at($cc, 'agent.language')),
                'dynamicVariablePlaceholders' => self::normalize(self::at($cc, 'agent.dynamic_variables.dynamic_variable_placeholders')),
                'prompt' => self::scalar($prompt['prompt'] ?? null),
                'llm' => self::scalar($prompt['llm'] ?? null),
                'temperature' => self::number($prompt['temperature'] ?? null),
                'builtInTools' => self::builtInProjection($prompt['built_in_tools'] ?? null),
                'knowledgeBaseCount' => count(self::arr($prompt['knowledge_base'] ?? null)),
                'mcpServerCount' => count(self::arr($prompt['mcp_server_ids'] ?? null)) + count(self::arr($prompt['native_mcp_server_ids'] ?? null)),
                'customLlm' => ($prompt['custom_llm'] ?? null) !== null,
            ],
            'languagePresetCount' => count(self::arr($cc['language_presets'] ?? null)),
            'workflowNodeCount' => count(self::arr(self::at($agent, 'workflow.nodes'))),
            'tts' => [
                'voiceId' => self::scalar(self::at($cc, 'tts.voice_id')),
                'modelId' => self::scalar(self::at($cc, 'tts.model_id')),
                'outputFormat' => self::scalar(self::at($cc, 'tts.agent_output_audio_format')),
            ],
            'asr' => ['inputFormat' => self::scalar(self::at($cc, 'asr.user_input_audio_format'))],
            'maxDurationSeconds' => self::number(self::at($cc, 'conversation.max_duration_seconds')),
            'callLimits' => [
                'concurrency' => self::number(self::at($ps, 'call_limits.agent_concurrency_limit')),
                'daily' => self::number(self::at($ps, 'call_limits.daily_limit')),
                'bursting' => self::scalar(self::at($ps, 'call_limits.bursting_enabled')),
            ],
            'privacy' => [
                'recordVoice' => self::scalar(self::at($ps, 'privacy.record_voice')),
                'retentionDays' => self::number(self::at($ps, 'privacy.retention_days')),
                'deleteAudio' => self::scalar(self::at($ps, 'privacy.delete_audio')),
                'zeroRetentionMode' => self::scalar(self::at($ps, 'privacy.zero_retention_mode')),
            ],
            'overrides' => [
                'open' => self::truePaths(self::at($ps, 'overrides.conversation_config_override'), ''),
                'customLlmExtraBody' => self::scalar(self::at($ps, 'overrides.custom_llm_extra_body')),
                'initiationFromWebhook' => self::scalar(self::at($ps, 'overrides.enable_conversation_initiation_client_data_from_webhook')),
            ],
            'initiationWebhook' => [
                'url' => self::scalar(self::at($ps, 'workspace_overrides.conversation_initiation_client_data_webhook.url')),
                'headers' => self::headers(self::at($ps, 'workspace_overrides.conversation_initiation_client_data_webhook.request_headers')),
            ],
            'postCallWebhook' => $idWebhook === null ? null : [
                'id' => $idWebhook,
                'events' => self::sortedStrings($webhookPostLlamada['events'] ?? null),
            ],
            'auth' => [
                'enabled' => self::scalar(self::at($ps, 'auth.enable_auth')),
                'allowlistCount' => count(self::arr(self::at($ps, 'auth.allowlist'))),
            ],
            'tools' => $herramientas,
        ];
    }

    /**
     * Las rutas (con puntos) de dos proyecciones que difieren, sin valores:
     * para decir DÓNDE hay deriva sin enseñar el prompt ni los destinos.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<string> en orden de bytes
     */
    public static function differences(array $a, array $b, string $prefijo = ''): array
    {
        $out = [];
        $claves = array_unique([...array_map('strval', array_keys($a)), ...array_map('strval', array_keys($b))]);
        sort($claves, SORT_STRING);
        foreach ($claves as $clave) {
            $ruta = $prefijo === '' ? $clave : $prefijo.'.'.$clave;
            $x = $a[$clave] ?? null;
            $y = $b[$clave] ?? null;
            if (is_array($x) && is_array($y) && ! array_is_list($x) && ! array_is_list($y)) {
                array_push($out, ...self::differences($x, $y, $ruta));
            } elseif ($x !== $y || ! array_key_exists($clave, $a) || ! array_key_exists($clave, $b)) {
                $out[] = $ruta;
            }
        }

        return $out;
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * `end_call` siempre; `transfer_to_number` con los destinos fijos del perfil
     * (en conferencia y con su número EN CLARO, del target) si y solo si la
     * especificación lo lleva.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException si un destino no tiene número o sobra uno
     */
    private static function builtInTools(VoiceAgentSpec $spec, VoiceAgentTarget $target): array
    {
        $descripciones = array_column($spec->systemTools(), 'description', 'name');
        $numeros = $target->transferNumbers();
        $destinos = $spec->transferDestinations();

        $refs = array_column($destinos, 'ref');
        sort($refs, SORT_STRING);
        if ($refs !== array_keys($numeros)) {
            throw new InvalidArgumentException('Los números de transferencia no casan con los destinos de la especificación.');
        }

        $out = [];
        foreach ($descripciones as $nombre => $descripcion) {
            $params = ['system_tool_type' => $nombre];
            if ($nombre === VoiceAgentSpec::SYSTEM_TOOL_TRANSFER) {
                $params['transfers'] = array_map(static fn (array $d): array => [
                    'transfer_destination' => ['type' => 'phone', 'phone_number' => $numeros[$d['ref']]],
                    'condition' => $d['condition'],
                    'transfer_type' => self::TRANSFER_TYPE,
                ], $destinos);
                $params['enable_client_message'] = true;
            }
            $out[$nombre] = ['type' => 'system', 'name' => $nombre, 'description' => $descripcion, 'params' => $params];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return array<string, array<string, mixed>> las herramientas de sistema activas (no nulas), por clave */
    private static function builtInProjection(mixed $builtIn): array
    {
        $out = [];
        foreach (self::arr($builtIn) as $clave => $herramienta) {
            if (! is_array($herramienta)) {
                continue;   // ElevenLabs devuelve las de sistema que no se usan como null: no están activas
            }
            $params = self::arr($herramienta['params'] ?? null);
            $traspasos = [];
            foreach (self::arr($params['transfers'] ?? null) as $t) {
                $t = self::arr($t);
                $traspasos[] = [
                    'destinationType' => self::scalar(self::at($t, 'transfer_destination.type')),
                    'phoneNumber' => self::scalar(self::at($t, 'transfer_destination.phone_number')),
                    'condition' => self::scalar($t['condition'] ?? null),
                    'transferType' => self::scalar($t['transfer_type'] ?? null),
                ];
            }
            $out[(string) $clave] = [
                'name' => self::scalar($herramienta['name'] ?? null),
                'description' => self::scalar($herramienta['description'] ?? null),
                'systemToolType' => self::scalar($params['system_tool_type'] ?? null),
                'transfers' => $traspasos,
            ];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Las cabeceras SIN valores secretos: una referencia del gestor de secretos
     * cuenta con su id; un literal, solo como literal.
     *
     * @return array<string, string>
     */
    private static function headers(mixed $cabeceras): array
    {
        $out = [];
        foreach (self::arr($cabeceras) as $nombre => $valor) {
            $out[strtolower((string) $nombre)] = match (true) {
                is_array($valor) && is_string($valor['secret_id'] ?? null) => 'secret:'.$valor['secret_id'],
                is_array($valor) && is_string($valor['variable_name'] ?? null) => 'variable:'.$valor['variable_name'],
                is_array($valor) && is_string($valor['env_var_label'] ?? null) => 'env:'.$valor['env_var_label'],
                is_string($valor) => 'literal',
                default => 'other',
            };
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return list<string> las rutas cuyo valor es true (sobrescrituras abiertas), en orden de bytes */
    private static function truePaths(mixed $valor, string $prefijo): array
    {
        if ($valor === true) {
            return [$prefijo];
        }
        $out = [];
        foreach (self::arr($valor) as $clave => $hijo) {
            array_push($out, ...self::truePaths($hijo, $prefijo === '' ? (string) $clave : $prefijo.'.'.$clave));
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /** @return list<string>|null */
    private static function sortedStrings(mixed $lista): ?array
    {
        if (! is_array($lista)) {
            return null;
        }
        $out = array_values(array_filter($lista, 'is_string'));
        sort($out, SORT_STRING);

        return $out;
    }

    /** Lee una ruta con puntos de un array; null si falta. */
    private static function at(array $origen, string $ruta): mixed
    {
        $actual = $origen;
        foreach (explode('.', $ruta) as $parte) {
            if (! is_array($actual) || ! array_key_exists($parte, $actual)) {
                return null;
            }
            $actual = $actual[$parte];
        }

        return $actual;
    }

    /** @return array<array-key, mixed> */
    private static function arr(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }

    /** Un escalar tal cual (texto, booleano, entero) o null; un decimal, normalizado; lo demás, null. */
    private static function scalar(mixed $valor): string|int|bool|null
    {
        if (is_float($valor)) {
            return self::number($valor);
        }

        return is_string($valor) || is_int($valor) || is_bool($valor) ? $valor : null;
    }

    /** Un número: entero tal cual; un decimal entero como entero; otro decimal como texto con 6 decimales. */
    private static function number(mixed $valor): int|string|null
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_float($valor) && is_finite($valor)) {
            return floor($valor) === $valor && abs($valor) < PHP_INT_MAX ? (int) $valor : sprintf('%.6F', $valor);
        }

        return null;
    }

    /** Normaliza un valor anidado (los decimales, como {@see number()}). */
    private static function normalize(mixed $valor): mixed
    {
        if (is_array($valor)) {
            $out = [];
            foreach ($valor as $clave => $hijo) {
                $out[$clave] = self::normalize($hijo);
            }

            return $out;
        }

        return self::scalar($valor);
    }
}
