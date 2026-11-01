<?php

/*
|--------------------------------------------------------------------------
| Voz: ElevenLabs y Twilio Voice (preparación, SIN ACTIVAR)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md, §I6.2.
|
| TODO APAGADO. Con estos defectos el estado es `ELEVENLABS_NOT_CONFIGURED`,
| ninguna clase sale a la red y ninguna llamada es posible: además de las
| banderas, las llamadas están cerradas en el código
| (`App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`) y en
| este hito no existe ningún código capaz de iniciarlas.
|
| La interpreta `App\Services\Voice\ElevenLabs\ElevenLabsConfig`, que vuelve a
| aplicar las reglas de forma (aquí se aplican al leer el entorno para que la
| caché de configuración ya las lleve). LOS SECRETOS solo los lee
| `ElevenLabsVoiceProvider`, en el momento de usarlos; una prueba estática
| (`VoiceIsolationTest`) comprueba que ningún otro fichero los toma.
|
| El motor no lee esta configuración.
*/

/*
 * Un entero del entorno; vacío o que no sea un entero → el defecto. Así una
 * línea `X=` en el .env no deja un tope a cero sin que nadie lo decida.
 */
$entero = static function (string $clave, int $defecto): int {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^-?\d+$/', $valor) === 1 ? (int) $valor : $defecto;
};

return [

    'elevenlabs' => [

        // Solo `true` (o 1/on/yes) la enciende; `false`, `off`, `no`, vacío o
        // cualquier otro texto la dejan apagada. Encenderla no llama a nadie:
        // pasa de DISABLED a UNVERIFIED y permite `voice:doctor --verify`.
        'enabled' => filter_var(env('ELEVENLABS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // Secreto. Una clave ACOTADA (por endpoint, crédito e IP si la consola
        // lo permite); la pone el propietario en el servidor, nunca por chat.
        'api_key' => env('ELEVENLABS_API_KEY'),

        // Tiene que casar con ElevenLabsConfig::AGENT_ID_PATTERN; si no, cuenta como que falta.
        'agent_id' => env('ELEVENLABS_AGENT_ID'),

        // Secreto (firma del webhook post-llamada; no hay ruta en este hito).
        'webhook_secret' => env('ELEVENLABS_WEBHOOK_SECRET'),

        // `default` (api.elevenlabs.io) o `eu` (api.eu.residency.elevenlabs.io):
        // lista cerrada de ElevenLabsConfig::REGIONS. En minúsculas y sin
        // espacios alrededor; cualquier otro valor (vacío incluido) deja la
        // región en las piezas que faltan.
        'region' => strtolower(trim((string) env('ELEVENLABS_REGION', 'default'))),

        // Solo la integración nativa (descartada); no se exige.
        'agent_phone_number_id' => env('ELEVENLABS_AGENT_PHONE_NUMBER_ID'),

        // Secreto de las server tools del futuro; no se exige.
        'tool_secret' => env('ELEVENLABS_TOOL_SECRET'),

        // SE IGNORA EN `production`, donde la URL es siempre la de la región.
        // Fuera de producción apunta al simulador local del E2E (:8096).
        'base_url' => env('ELEVENLABS_BASE_URL'),

        // Segundos; entero recortado a 3–30.
        'timeout_seconds' => min(30, max(3, $entero('ELEVENLABS_TIMEOUT_SECONDS', 10))),

    ],

    'twilio' => [

        // Como ELEVENLABS_ENABLED. Nada la usa para ejecutar: solo la leen el
        // panel de integraciones y la política de llamadas.
        'enabled' => filter_var(env('TWILIO_VOICE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // E.164: número de voz o caller ID verificado. Nunca el número
        // productivo de WhatsApp.
        'from' => env('TWILIO_VOICE_FROM'),

        // Llamadas al día; entero recortado a 0–20. Con 0 la política dice `daily_cap_zero`.
        'daily_call_cap' => min(20, max(0, $entero('TWILIO_VOICE_DAILY_CALL_CAP', 0))),

    ],

];
