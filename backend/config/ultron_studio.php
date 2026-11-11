<?php

/*
|--------------------------------------------------------------------------
| ULTRON STUDIO (borradores, versiones, reglas y aprobaciones del agente)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md (y H5 §A13).
|
| EL MOTOR DE ULTRON NO LEE ESTA CONFIGURACIÓN. Desde el Hito 5 lee UNA tabla
| del Studio, la cadena `ultron_studio_publications`, desde UNA clase
| (`UltronPublishedConfig`) y solo con SU llave (`MARKETING_ULTRON_STUDIO_CONFIG`
| distinta de `off`, en config/marketing.php); ninguna otra clase de
| app/Services/Marketing, app/Jobs ni de las rutas internas consulta esto ni
| las tablas `ultron_studio_*`. UltronEngineIsolationTest lo vigila por tokens.
*/

/*
 * Un entero del entorno; vacío o que no sea un entero → el defecto. Así una
 * línea `X=` en el .env no deja un tope a cero sin que nadie lo decida.
 */
$entero = static function (string $clave, int $defecto): int {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^-?\d+$/', $valor) === 1 ? (int) $valor : $defecto;
};

/*
 * La ventana de publicación (contrato H5 §A13): `HH:MM-HH:MM`, en hora de
 * Bogotá, con el inicio antes del fin. Sin la variable, la del contrato;
 * VACÍA a propósito (`X=`), sin ventana; cualquier otro valor (mal escrito,
 * `false`, una ventana que cruza la medianoche o vacía por dentro), la del
 * contrato: un error de escritura no abre la puerta sin que nadie lo decida.
 */
$ventana = static function (): string {
    $defecto = '07:00-19:00';
    $valor = env('ULTRON_STUDIO_PUBLISH_WINDOW');
    if (! is_string($valor)) {
        return $defecto;
    }
    $valor = trim($valor);
    if ($valor === '') {
        return '';
    }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $valor, $m) === 1
        && (int) $m[1] * 60 + (int) $m[2] < (int) $m[3] * 60 + (int) $m[4]) {
        return $valor;
    }

    return $defecto;
};

/*
 * Los prefijos de país admitidos para los números canarios (contrato H6 §I12):
 * una lista separada por comas de `^[1-9]\d{0,2}$`. Lo que no tiene esa forma
 * se descarta y, si no queda ninguno, `57`: un error de escritura no abre el
 * registro a cualquier país sin que nadie lo decida.
 */
$paises = static function (): array {
    $lista = [];
    foreach (explode(',', (string) env('ULTRON_STUDIO_CANARY_COUNTRY_CODES', '57')) as $prefijo) {
        $prefijo = trim($prefijo);
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $prefijo) === 1 && ! in_array($prefijo, $lista, true)) {
            $lista[] = $prefijo;
        }
    }

    return $lista === [] ? ['57'] : $lista;
};

/*
 * Los dominios admitidos en los botones de URL de las plantillas de Meta
 * (PLANTILLAS_META_PLAN §5.1 y §5.11; Q1 de L9-96: solo estáticos y solo
 * hacia el dominio propio): una lista separada por comas de nombres de host
 * EXACTOS (sin esquema, puerto ni ruta; sin comodines: `www.` se escribe
 * aparte). Lo que no tiene forma de host se descarta. Vacía o sin la
 * variable: no se admiten botones de URL.
 */
$hostsDeUrl = static function (): array {
    $lista = [];
    foreach (explode(',', (string) env('ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS', '')) as $host) {
        $host = strtolower(trim($host));
        if (preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host) === 1 && ! in_array($host, $lista, true)) {
            $lista[] = $host;
        }
    }

    return $lista;
};

return [

    /*
    | Llave del Studio para PUBLICAR y PROMOVER (contrato H5 §A5). APAGADA: con
    | ella apagada, las dos rutas responden 409 `publishing_disabled`. Revertir y
    | volver a Legacy NO dependen de ella (deshacer no puede depender de la
    | llave que permite cambiar). La otra llave es la del motor
    | (`MARKETING_ULTRON_STUDIO_CONFIG`): en `off`, publicar y promover responden
    | 409 `engine_not_reading_versions`, porque declarar algo que el motor no
    | lee no tendría efecto. Solo `true` (o 1/on/yes) la enciende: `false`,
    | `off`, `no`, vacío o cualquier otro texto la dejan apagada (revisión de
    | seguridad de H5, M-1: `(bool) env()` daba `off` por encendida).
    */
    'publishing_enabled' => filter_var(env('ULTRON_STUDIO_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Gobierno y versionado (Hito 5)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md, §A5, §A6 y
    | §A13. Los valores se recortan aquí, al leer el entorno, para que la caché
    | de configuración ya los lleve; StudioPublishing los vuelve a acotar.
    */
    'publishing' => [

        // `required` (defecto): para publicar hace falta un veredicto
        // `approved` del laboratorio sobre esta misma huella y la versión de
        // prompts vigente. `waived` lo decide el propietario y queda escrito en
        // cada publicación. Cualquier otro valor, `required`.
        'lab_gate' => strtolower(trim((string) env('ULTRON_STUDIO_PUBLISH_LAB_GATE', 'required'))) === 'waived' ? 'waived' : 'required',

        // La evidencia mínima del canario para promover: horas desde la
        // publicación (1–168) y turnos sellados (1–100). Fuera del rango, se
        // recorta; vacío o no entero, el defecto.
        'canary_min_hours' => min(168, max(1, $entero('ULTRON_STUDIO_CANARY_MIN_HOURS', 24))),
        'canary_min_turns' => min(100, max(1, $entero('ULTRON_STUDIO_CANARY_MIN_TURNS', 3))),

        // Publicar y promover solo dentro de esta ventana (hora de Bogotá,
        // [inicio, fin)); revertir y Legacy, siempre. '' = sin ventana.
        'window' => $ventana(),

    ],

    /*
    |--------------------------------------------------------------------------
    | Laboratorio de IA (Hito 4)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md, §A1.
    |
    | SIMULACIÓN DEL STUDIO, NO ES ULTRON: un actor de laboratorio interpreta la
    | versión sobre escenarios sintéticos y un juez opina; el motor no lee nada
    | de esto y el laboratorio no usa la clave, el modelo ni la configuración
    | del motor ni de IRON IA.
    |
    | CUATRO CERROJOS ANTES DE GASTAR, independientes: la bandera apagada, el
    | driver `fake`, el presupuesto diario 0 y la clave ausente o compartida. Lo
    | interpreta StudioLabConfig, que vuelve a aplicar los topes duros (aquí se
    | aplican al leer el entorno para que la caché de configuración ya los
    | lleve) y NUNCA devuelve ni registra la clave.
    */
    'lab' => [

        // Apagada: todas las rutas `lab/*` responden 409 `lab_disabled`, salvo `GET lab/status`.
        // Solo `true` (o 1/on/yes) la enciende; `off`, `no` o basura la dejan apagada (M-1 de H5).
        'enabled' => filter_var(env('ULTRON_STUDIO_LAB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // `fake` (sin red, coste 0) u `openai`. Cualquier otro valor deja el
        // laboratorio sin configurar (`invalid_driver`): nunca se cae en
        // silencio de uno a otro.
        'driver' => strtolower(trim((string) env('ULTRON_STUDIO_LAB_DRIVER', 'fake'))),

        'openai' => [
            // La clave PROPIA del laboratorio (un proyecto de OpenAI con su
            // presupuesto). Solo la lee el cliente, en el momento de llamar.
            'api_key' => env('ULTRON_STUDIO_LAB_OPENAI_API_KEY'),

            // La huella (sha256) de la clave que el laboratorio NO puede usar:
            // la de OpenAI que ya usan IRON IA y el cerebro legado. Se calcula
            // aquí para que ningún servicio del laboratorio tenga que leer la
            // configuración de otros (una prueba por tokens lo vigila). Solo
            // sirve para comparar: igual a la del laboratorio → `shared_key`.
            'forbidden_key_sha256' => ($otra = trim((string) env('OPENAI_API_KEY', ''))) !== '' ? hash('sha256', $otra) : null,

            // Los elige el propietario con la página oficial de precios.
            'model' => env('ULTRON_STUDIO_LAB_OPENAI_MODEL'),

            // Vacías: no se envían. Que los modelos de razonamiento rechacen
            // `temperature` es una suposición: se comprueba con el modelo elegido.
            'temperature' => env('ULTRON_STUDIO_LAB_OPENAI_TEMPERATURE'),
            'reasoning_effort' => env('ULTRON_STUDIO_LAB_OPENAI_REASONING_EFFORT'),

            // SE IGNORA EN `production`, donde la URL es siempre
            // https://api.openai.com. Fuera de producción apunta al simulador
            // local del E2E.
            'base_url' => env('ULTRON_STUDIO_LAB_OPENAI_BASE_URL'),
        ],

        // US$ por millón de tokens, decimal con hasta 6 cifras (`0.40`). Sin
        // precio no hay estimación (`missing_price`).
        'price_input_usd_per_mtok' => env('ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK'),
        'price_output_usd_per_mtok' => env('ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK'),

        // Con 0 no se permite ninguna ejecución de pago (`zero_budget`). El día
        // del presupuesto es el de America/Bogota.
        'daily_budget_usd' => env('ULTRON_STUDIO_LAB_DAILY_BUDGET_USD', '0'),
        'run_budget_usd' => env('ULTRON_STUDIO_LAB_RUN_BUDGET_USD', '0.50'),

        // Cuenta también las ejecuciones `fake`.
        'max_runs_per_day' => max(0, $entero('ULTRON_STUDIO_LAB_MAX_RUNS_PER_DAY', 20)),

        // Topes duros: 20 casos, 800 y 1600 tokens de salida, 60 s (el job tiene 150).
        'max_cases_per_run' => min(20, max(1, $entero('ULTRON_STUDIO_LAB_MAX_CASES_PER_RUN', 12))),
        'actor_max_output_tokens' => min(800, max(16, $entero('ULTRON_STUDIO_LAB_ACTOR_MAX_OUTPUT_TOKENS', 400))),
        'judge_max_output_tokens' => min(1600, max(16, $entero('ULTRON_STUDIO_LAB_JUDGE_MAX_OUTPUT_TOKENS', 900))),
        'timeout_seconds' => min(60, max(10, $entero('ULTRON_STUDIO_LAB_TIMEOUT_SECONDS', 60))),

    ],

    /*
    |--------------------------------------------------------------------------
    | Integraciones comerciales (Hito 6)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md, §I12.
    |
    | REGISTRO DEL STUDIO, NO DEL MOTOR: asociaciones de campaña, números
    | canarios y configuración autorizada. El motor no lee nada de esto, y
    | nada de aquí cambia lo que ULTRON dice o hace. CERO RED POR DEFECTO: la
    | única petición saliente posible desde una ruta es la sonda GET de Twilio,
    | y solo con su bandera.
    */
    'integrations' => [

        // Apagada: toda escritura del hito (rutas 47–54) responde 409
        // `integrations_writes_disabled`. Reconocer la configuración (ruta 55)
        // NO depende de ella (decisión 11). Solo `true` (o 1/on/yes) la enciende.
        'writes_enabled' => filter_var(env('ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'twilio_probe' => [
            // Apagada: la sonda (ruta 56) responde 409 `probe_disabled`.
            'enabled' => filter_var(env('ULTRON_STUDIO_TWILIO_PROBE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

            // La API key RESTRINGIDA de la sonda (`SK…` y su secreto). Manda
            // solo con las dos; si no, la sonda usa la credencial del OTP.
            // Solo la lee StudioTwilioProbe, en el momento de construir el
            // cliente, y nunca sale en una respuesta, el libro ni el log.
            'key_sid' => env('ULTRON_STUDIO_TWILIO_PROBE_KEY_SID'),
            'key_secret' => env('ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET'),

            // SE IGNORA EN `production` (el cliente usa siempre los hosts
            // oficiales). Fuera de producción apunta al simulador del E2E.
            'base' => env('ULTRON_STUDIO_TWILIO_PROBE_BASE'),

            // Fijo en el código: entre dos sondas, cinco minutos.
            'cooldown_seconds' => 300,
        ],

        'canary' => [
            // Activos por canal: 1–10 (recorte); vacío o no entero, 3.
            'max_active' => min(10, max(1, $entero('ULTRON_STUDIO_CANARY_MAX_ACTIVE', 3))),
            // Vivos (no revocados) por canal: fijo.
            'max_live' => 10,
            'country_codes' => $paises(),
        ],

        'campaign_links' => [
            // Asociaciones activas en total: 1–200 (recorte); vacío o no entero, 50.
            'max_active' => min(200, max(1, $entero('ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE', 50))),
        ],

        // Calculado AL CACHEAR (fuera de la configuración, `env()` devuelve
        // null con la caché puesta), como `forbidden_key_sha256` del Hito 4:
        // de qué variable sale cada credencial de Twilio y si los dos nombres
        // de la misma credencial coinciden. Solo nombres y booleanos, nunca
        // valores; `null` si solo existe uno de los dos nombres.
        'twilio_aliases' => [
            'sid_source' => filled(env('TWILIO_SID')) ? 'TWILIO_SID' : (filled(env('TWILIO_ACCOUNT_SID')) ? 'TWILIO_ACCOUNT_SID' : null),
            'token_source' => filled(env('TWILIO_TOKEN')) ? 'TWILIO_TOKEN' : (filled(env('TWILIO_AUTH_TOKEN')) ? 'TWILIO_AUTH_TOKEN' : null),
            'sid_consistent' => filled(env('TWILIO_SID')) && filled(env('TWILIO_ACCOUNT_SID')) ? hash_equals((string) env('TWILIO_SID'), (string) env('TWILIO_ACCOUNT_SID')) : null,
            'token_consistent' => filled(env('TWILIO_TOKEN')) && filled(env('TWILIO_AUTH_TOKEN')) ? hash_equals((string) env('TWILIO_TOKEN'), (string) env('TWILIO_AUTH_TOKEN')) : null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gobierno de la voz (Hito 8, paquete S8)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md, D8-7, D8-19,
    | D8-23 y E2-4.
    |
    | PUBLICAR LA VOZ ES DEL STUDIO, LLAMAR ES DE LA TELEFONÍA: aquí solo lo que
    | decide el Studio al publicar y promover un agente de voz. Las banderas y
    | los techos de las llamadas y del laboratorio hablado viven en
    | config/voice.php. Todo APAGADO por defecto (protección 1): sin la bandera,
    | publicar y promover responden 409 `voice_publishing_disabled` antes de
    | compilar ni de salir a la red (E2-4). Revertir y apagar la voz no
    | dependen de ella (deshacer no puede depender de la llave que permite
    | cambiar). Solo `true` (o 1/on/yes) la enciende. La ventana de publicación
    | es la del Hito 5 (`publishing.window`, D8-19 y P-25).
    */
    'voice' => [

        'publishing_enabled' => filter_var(env('ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // P-19: `required` (defecto): publicar exige un veredicto `approved`
        // del laboratorio hablado sobre la MISMA compilación. `waived` lo
        // decide el propietario y queda escrito en la entrada. Otro valor, `required`.
        'lab_gate' => strtolower(trim((string) env('ULTRON_STUDIO_VOICE_LAB_GATE', 'required'))) === 'waived' ? 'waived' : 'required',

        // P-16: la evidencia mínima del canario de voz para promover: horas
        // desde la publicación en canario (1–168) y llamadas selladas con ella
        // (1–100). Fuera del rango se recorta; vacío o no entero, el defecto.
        'canary_min_hours' => min(168, max(1, $entero('ULTRON_STUDIO_VOICE_CANARY_MIN_HOURS', 24))),
        'canary_min_calls' => min(100, max(1, $entero('ULTRON_STUDIO_VOICE_CANARY_MIN_CALLS', 3))),

    ],

    /*
    |--------------------------------------------------------------------------
    | Gobierno de las estrategias (Hito 9, paquete S9)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md, D9-11, §7 y
    | §9.1. Es REGISTRO del Studio: nada de aquí ejecuta, planifica ni envía; la
    | ejecución (`config/outreach.php`) es de B9-run y solo lee la cadena
    | firmada `ultron_studio_strategy_publications`.
    |
    | TODO NACE APAGADO (protección 1). Solo `true` (o 1/on/yes) enciende:
    |  - `enabled`: sin ella, TODAS las rutas `strategies/**` responden 409
    |    `strategies_disabled` (también las lecturas);
    |  - `publishing_enabled`: publicar, promover y reanudar responden 409
    |    `strategies_publishing_disabled`. Pausar y revertir NO dependen de
    |    ella (deshacer no puede depender de la llave que permite cambiar).
    | La ventana es propia (`ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW`), con la
    | misma forma que la del Hito 5: sin la variable, 07:00-19:00; vacía, sin
    | ventana; mal escrita, la del contrato.
    */
    'strategies' => [

        'enabled' => filter_var(env('ULTRON_STUDIO_STRATEGIES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'publishing_enabled' => filter_var(env('ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'window' => (static function (): string {
            $defecto = '07:00-19:00';
            $valor = env('ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW');
            if (! is_string($valor)) {
                return $defecto;
            }
            $valor = trim($valor);
            if ($valor === '') {
                return '';
            }
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $valor, $m) === 1
                && (int) $m[1] * 60 + (int) $m[2] < (int) $m[3] * 60 + (int) $m[4]) {
                return $valor;
            }

            return $defecto;
        })(),

        // La evidencia mínima del canario para promover (D9-11, P9-19): horas
        // desde la publicación en canario (1–168) y contactos que salieron
        // (1–100). Fuera del rango, se recorta; vacío o no entero, el defecto.
        'canary_min_hours' => min(168, max(1, $entero('ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS', 24))),
        'canary_min_touches' => min(100, max(1, $entero('ULTRON_STUDIO_STRATEGIES_CANARY_MIN_TOUCHES', 3))),

        // `required` (defecto): aprobar exige una simulación SIN BLOQUEANTES
        // sobre la misma huella (D9-12). `waived` solo lo decide el propietario
        // y queda escrito en la publicación. Cualquier otro valor, `required`.
        'simulation_gate' => strtolower(trim((string) env('ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE', 'required'))) === 'waived' ? 'waived' : 'required',

    ],

    /*
    |--------------------------------------------------------------------------
    | Plantillas de WhatsApp de Meta desde el CRM
    |--------------------------------------------------------------------------
    | Plan: docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md §5.11; contrato
    | H9, L9-96 (D9-6 ampliado, protección 12, D-5, §9.1).
    |
    | Enviar a Meta una versión APROBADA a cuatro ojos de una plantilla de
    | WhatsApp del Studio y registrar sola lo que Meta responde en
    | `ultron_studio_template_meta_checks` (lo que lee el canal de W9). El
    | webhook, el outbox y la puerta de Marketing no se tocan.
    |
    | TODO NACE APAGADO (protección 1). Solo `true` (o 1/on/yes) enciende:
    |  - `enabled`: la maestra. Apagada, las rutas `strategies/templates/meta/**`
    |    y `…/{uuid}/meta/**` responden 409 `meta_templates_disabled` (salvo
    |    `GET …/meta/status`, que responde 200) y la sincronización sale con 0;
    |  - `submit_enabled`: lo ÚNICO que escribe en Meta (crea plantillas en la
    |    cuenta de WhatsApp del gimnasio); además exige `META_ENABLED=true`;
    |  - `sync_enabled`: programa `ultron-studio:meta-templates-sync` cada 5
    |    minutos (bloque propio de routes/console.php).
    | `driver`: `fake` (por defecto: sin red) o `graph`. En `production` es
    | SIEMPRE `graph` (el doble no se construye) y fuera de `local`/`testing`
    | también. Fuera de producción, `graph` solo habla con `graph_base` (el
    | simulador del E2E) y se niega a graph.facebook.com; esa base tiene que
    | ser `https://` o `http://` a 127.0.0.1 o localhost, y lleva siempre el
    | portador fijo `simulator`, nunca el token real (PM-1).
    | `graph_version`: la de ESTE módulo (Q4: `v25.0`), sin depender de
    | `META_GRAPH_VERSION`; mal escrita, la de por defecto.
    | `url_hosts` (Q1) y `phone_button` (Q2: el número es el del gimnasio de la
    | configuración, `legal.privacy_phone`, nunca uno tecleado): solo afectan a
    | las versiones NUEVAS (el contenido se valida al crearlo).
    */
    'meta_templates' => [

        'enabled' => filter_var(env('ULTRON_STUDIO_META_TEMPLATES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'submit_enabled' => filter_var(env('ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'sync_enabled' => filter_var(env('ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        'driver' => strtolower(trim((string) env('ULTRON_STUDIO_META_TEMPLATES_DRIVER', 'fake'))),

        'graph_version' => preg_match('/^v[1-9][0-9]?\.[0-9]$/D', $versionGraph = trim((string) env('ULTRON_STUDIO_META_TEMPLATES_GRAPH_VERSION', ''))) === 1 ? $versionGraph : 'v25.0',

        // SE IGNORA EN `production` (el cliente usa siempre https://graph.facebook.com).
        'graph_base' => env('ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE'),

        'url_hosts' => $hostsDeUrl(),

        'phone_button' => filter_var(env('ULTRON_STUDIO_META_TEMPLATES_PHONE_BUTTON', false), FILTER_VALIDATE_BOOLEAN),

    ],

    /*
    |--------------------------------------------------------------------------
    | Monitor (Hito 7)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H7_CONTRACT.md, OPS7 (a).
    |
    | VIGILA, NO ACTÚA: `ultron-studio:monitor` agrupa comprobaciones que ya
    | existían (`publication-check`, `integrations-doctor --expect=…`, la deriva
    | de la configuración autorizada y la salud de las colas), sale con 1 si
    | algo no cuadra y abre o suma al incidente de IRON GUARD de su clase. No
    | frena, no revierte, no llama a ningún proveedor y no notifica fuera.
    |
    | APAGADO: con la bandera apagada, routes/console.php no programa nada del
    | Studio (ni el monitor ni la instantánea diaria de integraciones). El
    | comando sigue disponible a mano. Solo `true` (o 1/on/yes) la enciende.
    */
    'monitor' => [

        'enabled' => filter_var(env('ULTRON_STUDIO_MONITOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // Lo que tiene que afirmar `integrations-doctor` (el formato de su
        // `--expect`). Por defecto, las protecciones del Hito 6: Twilio solo
        // para OTP, sin voz de Twilio y sin ElevenLabs. Meta no va por
        // defecto: `degraded` es un dato (una pasada vieja), no una deriva.
        // Vacía o sin la variable, la de por defecto (una línea `X=` no apaga
        // la vigilancia sin que nadie lo decida); mal escrita, el monitor
        // falla en voz alta (`expectation_invalid`), nunca se cambia sola.
        'expect' => ($esperado = trim((string) env('ULTRON_STUDIO_MONITOR_EXPECT', ''))) !== ''
            ? $esperado
            : 'twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED',

    ],

];
