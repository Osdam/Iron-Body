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

        // Contrato H8, D8-1 y D8-5: el id del número dedicado IMPORTADO en
        // ElevenLabs (integración nativa, la opción A). No es un secreto; no
        // lo exige `missing()` (los cuatro estados del H6 no cambian).
        'agent_phone_number_id' => env('ELEVENLABS_AGENT_PHONE_NUMBER_ID'),

        // Secreto de las server tools (H8, D8-16: Bearer con hash_equals); no
        // lo exige `missing()`.
        'tool_secret' => env('ELEVENLABS_TOOL_SECRET'),

        // Secreto (H8, D8-5 y D8-16): la cabecera fija del webhook de
        // iniciación de las entrantes. DISTINTO del de las herramientas. No lo
        // exige `missing()`.
        'initiation_secret' => env('ELEVENLABS_INITIATION_SECRET'),

        // H8, D8-16: los IDS (no los valores) del secreto de las herramientas y
        // del de iniciación tal como están guardados en el gestor de secretos
        // de ElevenLabs, y el id del webhook post-llamada del workspace. Van en
        // la carga compilada del agente; ninguno es un secreto.
        'tool_secret_ref' => env('ELEVENLABS_TOOL_SECRET_REF'),
        'initiation_secret_ref' => env('ELEVENLABS_INITIATION_SECRET_REF'),
        'post_call_webhook_ref' => env('ELEVENLABS_POST_CALL_WEBHOOK_REF'),

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

        // H8, D8-4 y D8-5: la SUBCUENTA «Voz» (AC…), nunca la cuenta madre ni
        // la credencial del OTP (`otp.twilio.*`, que la voz no lee).
        'account_sid' => env('TWILIO_VOICE_ACCOUNT_SID'),

        // La API key RESTRINGIDA de Laravel (SK… y su secreto): llamadas
        // (leer, listar y actualizar), números y uso (leer). Secreto: solo lo
        // lee `App\Services\Twilio\TwilioVoiceCarrier`, al usarlo.
        'api_key_sid' => env('TWILIO_VOICE_API_KEY_SID'),
        'api_key_secret' => env('TWILIO_VOICE_API_KEY_SECRET'),

        // Secreto: el Auth Token de la SUBCUENTA, solo para VALIDAR
        // `X-Twilio-Signature`; no firma peticiones. Solo lo lee
        // `App\Services\Twilio\TwilioRequestSignature`.
        'signing_token' => env('TWILIO_VOICE_SIGNING_TOKEN'),

        // SE IGNORA EN `production`, donde el host es siempre api.twilio.com.
        // Fuera de producción apunta al simulador local del banco.
        'base_url' => env('TWILIO_VOICE_BASE_URL'),

    ],

    // H8, D8-4 y D8-16: la base canónica pública de Laravel (https, esquema y
    // host SIN ruta), con la que se calculan las firmas de Twilio (base + la
    // URI tal como llega, nunca fullUrl()) y las URL que llama ElevenLabs.
    'webhook_base_url' => env('VOICE_WEBHOOK_BASE_URL'),

    // H8, D8-11: entrantes. Apagadas, la iniciación responde caller_authorized = no.
    'inbound' => [
        'enabled' => filter_var(env('VOICE_INBOUND_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // `canary` es el único alcance del H8; cualquier otro valor cuenta como `canary`.
        'scope' => 'canary',

        // Entrantes al día [P-5]; entero recortado a 0–20. Con 0 no se atiende ninguna.
        'daily_cap' => min(20, max(0, $entero('VOICE_INBOUND_DAILY_CAP', 0))),
    ],

    // H8, D8-12: salientes, la única puerta. Apagadas, `outbound_disabled`.
    'outbound' => [
        'enabled' => filter_var(env('VOICE_OUTBOUND_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // `canary` (fase 4a) o `contacts` (fase 4b); cualquier otro valor, el
        // más estrecho: `canary`.
        'scope' => strtolower(trim((string) env('VOICE_OUTBOUND_SCOPE', 'canary'))) === 'contacts' ? 'contacts' : 'canary',

        // [P-10] los segundos de timbre de una saliente; entero recortado a 10–60.
        'ringing_timeout_seconds' => min(60, max(10, $entero('VOICE_OUTBOUND_RINGING_SECONDS', 30))),

        // [P-13] horario PROPIO de salientes (`HH:MM-HH:MM`, hora de Bogotá):
        // siempre la intersección con el legal, nunca lo amplía. Vacíos los
        // dos = solo el legal; uno vacío = ese tipo de día cerrado; mal
        // escrito = fuera de horario (falla cerrado).
        'own_hours' => [
            'weekdays' => trim((string) env('VOICE_OUTBOUND_HOURS_WEEKDAYS', '')),
            'saturday' => trim((string) env('VOICE_OUTBOUND_HOURS_SATURDAY', '')),
        ],

        // [P-11] intentos por contacto: 1 al día (Ley 2300, fijo en el código)
        // y como mucho N a la semana (1–2). La semana: `rolling` (los 7 días
        // móviles, la lectura más estricta, por defecto) o `calendar` (desde
        // el lunes 00:00 de Bogotá).
        'frequency' => [
            'per_week' => min(2, max(1, $entero('VOICE_FREQUENCY_PER_WEEK', 2))),
            'week' => strtolower(trim((string) env('VOICE_FREQUENCY_WEEK', 'rolling'))) === 'calendar' ? 'calendar' : 'rolling',
        ],
    ],

    // H8, Enmienda 2 (E2-4): publicar la voz (S8) y el laboratorio hablado
    // (LAB8) se cierran por su PROPIA bandera, además de la credencial (que en
    // producción ya está desde E1-12). Apagadas, ninguna de las dos sale a la red.
    'publishing' => [
        'enabled' => filter_var(env('VOICE_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'lab' => [
        'enabled' => filter_var(env('VOICE_LAB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // H8, D8-21 y E2-6: las tareas `voice:*` del monitor (OPS8 las programa en
    // su propio bloque bajo esta bandera). Apagada, nada se programa.
    'monitor' => [
        'enabled' => filter_var(env('VOICE_MONITOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // H8, D8-14 [P-12]: el horario del PERSONAL que atiende las transferencias
    // (`HH:MM-HH:MM`, Bogotá). Sin configurar, `solicitar_humano` dice que no
    // hay nadie (nunca se ofrece transferir).
    'transfer' => [
        'staff_hours' => [
            'weekdays' => trim((string) env('VOICE_TRANSFER_HOURS_WEEKDAYS', '')),
            'saturday' => trim((string) env('VOICE_TRANSFER_HOURS_SATURDAY', '')),
        ],
    ],

    // H8, D8-10 (R-01): los HECHOS que dice `consultar_horarios`. Texto plano
    // corto, sin teléfonos ni URL. Vacíos, la herramienta dice que no los
    // tiene (nunca se inventan).
    'facts' => [
        'site' => trim((string) env('VOICE_FACTS_SITE', '')),
        'hours' => trim((string) env('VOICE_FACTS_HOURS', '')),
    ],

    // H8, D8-18 [P-14]: días que vale una consulta al RNE; entero 0–365. Con 0
    // (el defecto mientras P-14 no se decida) ninguna vale: el alcance
    // `contacts` da siempre `rne_check_missing`.
    'contacts' => [
        'rne_validity_days' => min(365, max(0, $entero('VOICE_RNE_VALIDITY_DAYS', 0))),
    ],

    // H8, D8-15: los precios OFICIALES con su fecha, en micro-US$ por minuto,
    // para la reserva de coste. Cambiarlos es un commit revisado.
    'prices' => [
        'as_of' => '2026-11-03',
        'elevenlabs' => 80000,
        'twilio_outbound_mobile_co' => 37700,
        'twilio_outbound_landline_co' => 70000,
        'twilio_inbound_local_co' => 94500,
        'twilio_media_streams' => 4400,
    ],

    // H8, D8-15: límites. Son TECHOS: ninguno enciende nada.
    'limits' => [
        // Llamadas a la vez (dialing + active); entero recortado a 1–4.
        'max_concurrent_calls' => min(4, max(1, $entero('VOICE_MAX_CONCURRENT_CALLS', 1))),

        // Duración máxima por llamada; entero recortado a 60–600 [C-A5]. El
        // compilador se niega a compilar un perfil que pida más.
        'per_call_max_seconds' => min(600, max(60, $entero('VOICE_PER_CALL_MAX_SECONDS', 300))),

        // US$ al día de Bogotá, texto decimal (lo interpreta quien reserva el
        // coste, como el laboratorio del H4). Con 0 no hay ninguna saliente.
        'daily_budget_usd' => env('VOICE_DAILY_BUDGET_USD', '0'),
    ],

];
