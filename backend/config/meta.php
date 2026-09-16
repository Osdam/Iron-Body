<?php

/*
|------------------------------------------------------------------------------
| Identidad de la app que ejecuta Embedded Signup
|------------------------------------------------------------------------------
| Puede NO ser la misma app que el resto del canal. Aqui hay dos apps de Meta:
|
|   · META_APP_ID / META_APP_SECRET  → la app historica del canal. Es la dueña
|     del webhook y del token de Cloud API, y su secreto firma los POST
|     entrantes. Tocarla rompe la mensajeria.
|   · META_EMBEDDED_SIGNUP_APP_ID    → la app donde vive la configuracion de
|     Facebook Login for Business (el config_id) y la revision de permisos.
|
| Meta comprueba que el config_id PERTENEZCA al app_id con el que se abre el
| dialogo. Cruzarlos da "Funcion no disponible" y `FB.login` no devuelve codigo.
|
| El secreto NO se hereda a ciegas: solo cuando las dos apps son la MISMA se
| reutiliza META_APP_SECRET. Si el Embedded Signup corre en otra app, su secreto
| tiene que declararse aparte, porque canjear un codigo de la app A firmando con
| el secreto de la app B siempre falla, y falla al final del recorrido -despues
| de que una persona haya autorizado todo- que es el peor momento para enterarse.
*/
$embeddedSignupAppId = env('META_EMBEDDED_SIGNUP_APP_ID', env('META_APP_ID'));
$embeddedSignupAppSecret = env('META_EMBEDDED_SIGNUP_APP_SECRET');

/**
 * Lista separada por comas -> array de valores limpios.
 *
 * Se usa para las listas de activos protegidos, que se declaran como texto en
 * el `.env` y se comparan como identificadores exactos.
 *
 * @return array<int,string>
 */
$listaDeIds = static function (?string $crudo): array {
    return array_values(array_filter(array_map('trim', explode(',', (string) $crudo))));
};

/**
 * Igual, pero dejando SOLO los digitos: +57 314 345 5483 y 573143455483 son el
 * mismo telefono escrito de dos maneras, y Meta devuelve el primero.
 *
 * @return array<int,string>
 */
$listaDeTelefonos = static function (?string $crudo): array {
    return array_values(array_filter(array_map(
        static fn (string $n): string => preg_replace('/\D+/', '', $n) ?? '',
        explode(',', (string) $crudo),
    )));
};

/**
 * Variable de entorno, con respaldo REAL cuando esta declarada pero vacia.
 *
 * `env('X', $default)` solo aplica el default cuando la clave NO existe. Una
 * clave declarada vacia -`X=`, que es como estan en `.env.example`- devuelve
 * cadena vacia y el default no llega a usarse nunca.
 *
 * Para una lista de activos protegidos eso no es un matiz: convierte la barrera
 * en decorativa sin que nadie lo note, porque el fichero de entorno "parece"
 * correcto. Aqui una variable vacia significa "no me pronuncio", no "desactiva
 * la proteccion".
 */
$envConRespaldo = static function (string $clave, string $respaldo): string {
    $valor = (string) env($clave, '');

    return trim($valor) !== '' ? $valor : $respaldo;
};

/*
 * Telefonos protegidos, resueltos UNA vez porque los leen dos sitios: la lista
 * general y la barrera especifica del modo demostracion.
 */
$telefonosProtegidos = $listaDeTelefonos(
    $envConRespaldo('WHATSAPP_PROTECTED_NUMBERS', (string) env('WHATSAPP_DISPLAY_PHONE', '')),
);

if ((string) $embeddedSignupAppSecret === ''
    && (string) $embeddedSignupAppId === (string) env('META_APP_ID')) {
    $embeddedSignupAppSecret = env('META_APP_SECRET');
}

return [

    /*
    |--------------------------------------------------------------------------
    | Integración Meta (Facebook / Instagram / WhatsApp / Ads)
    |--------------------------------------------------------------------------
    | Capa comercial: métricas de pauta, mensajería y leads. Los tokens viven
    | SOLO en el backend (nunca en Angular/Flutter). Mientras `enabled` sea
    | false, los servicios NO hacen llamadas a Graph API: el sistema persiste y
    | sirve datos locales pero no contacta a Meta (scaffolding seguro).
    |
    | Los webhooks (GET/POST /api/webhooks/meta) requieren un dominio HTTPS
    | público y verificado. ngrok NO sirve para producción ni App Review.
    */

    'enabled' => filter_var(env('META_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'app_id'     => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),

    // Verificación del webhook (challenge) y firma de los POST.
    'verify_token'   => env('META_VERIFY_TOKEN'),
    'webhook_secret' => env('META_WEBHOOK_SECRET', env('META_APP_SECRET')),

    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    'graph_base'    => 'https://graph.facebook.com',

    // Token de larga duración / System User. Se renueva server-side.
    'access_token' => env('META_ACCESS_TOKEN'),

    'page_id'              => env('META_PAGE_ID'),
    'instagram_account_id' => env('META_INSTAGRAM_ACCOUNT_ID'),
    'ad_account_id'        => env('META_AD_ACCOUNT_ID'),
    'business_id'          => env('META_BUSINESS_ID'),

    'whatsapp_business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'whatsapp_phone_number_id'     => env('META_WHATSAPP_PHONE_NUMBER_ID'),
    // Número visible de WhatsApp (informativo; el envío usa el phone_number_id).
    'whatsapp_display_phone'       => env('WHATSAPP_DISPLAY_PHONE'),

    'timeout' => (int) env('META_API_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Embedded Signup — onboarding oficial desde el CRM
    |--------------------------------------------------------------------------
    | El flujo por el que el dueño del número autoriza a esta app SIN sacar el
    | número de la app WhatsApp Business (coexistencia). Arranca en el CRM,
    | continúa en Meta y vuelve con un código que el backend canjea por un token.
    |
    | `config_id` es una configuración de **Facebook Login for Business** que se
    | crea en el panel de Meta (App → Inicio de sesión con Facebook para
    | empresas → Configuraciones). NO se puede crear desde código y sin ella el
    | botón del CRM no puede abrir el diálogo: el backend lo dice explícitamente
    | en vez de abrir una ventana que fallaría con un error de Meta sin contexto.
    |
    | El App Secret NUNCA viaja al navegador. El CRM recibe solo `app_id` y
    | `config_id`, que son públicos por diseño; el canje del código lo hace el
    | backend contra Graph API.
    */
    'embedded_signup' => [
        /*
         * La app que abre el diálogo. Por defecto, la misma del canal — que es
         * el comportamiento anterior y no cambia nada donde ambas coinciden.
         */
        'app_id' => $embeddedSignupAppId,

        /*
         * Su App Secret. Solo se hereda del canal cuando es la misma app (ver
         * la cabecera del fichero). Nunca viaja al navegador: se usa únicamente
         * para canjear el código servidor contra servidor.
         */
        'app_secret' => $embeddedSignupAppSecret,

        // Configuración de Facebook Login for Business (config_id).
        'config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),

        /*
         * Versión del SDK de JavaScript que carga el CRM. Se deja configurable
         * porque el diálogo de Embedded Signup cambia entre versiones y una
         * subida de Graph no debería obligar a recompilar el frontend.
         */
        'sdk_version' => env('META_JS_SDK_VERSION', env('META_GRAPH_VERSION', 'v21.0')),

        /*
         * Permisos que se piden. `whatsapp_business_management` administra la
         * cuenta (WABA, números, plantillas y todas las analíticas), y
         * `whatsapp_business_messaging` envía y recibe. Con esos dos está
         * cubierto todo lo que hace esta aplicación.
         *
         * `business_management` estuvo aquí y se retiró. Meta lo clasifica como
         * OPCIONAL: «only needed if you need to programmatically access your
         * business portfolio (this is rarely needed, since you can access your
         * portfolio using Meta Business Suite)». Ninguna de las llamadas Graph
         * de este backend toca un nodo Business, así que pedirlo era pedir un
         * permiso que la aplicación no usa —y que por tanto no se puede
         * demostrar honestamente en una App Review—. Tampoco lo exigen Cloud
         * API, Embedded Signup ni Marketing Messages API; solo hace falta para
         * compartir línea de crédito como Solution Partner, que no es el caso.
         *
         * Meta puede conceder menos de lo pedido: lo concedido de verdad se
         * guarda en la fila de la integración, no se da por hecho.
         */
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'META_EMBEDDED_SIGNUP_SCOPES',
                'whatsapp_business_management,whatsapp_business_messaging',
            )),
        ))),

        /*
         * Coexistencia: el número sigue en la app WhatsApp Business y además
         * queda disponible por Cloud API. Es la ÚNICA vía que no le quita
         * WhatsApp Web al personal (ver docs/marketing-meta-whatsapp.md §8).
         */
        'feature_type' => env('META_EMBEDDED_SIGNUP_FEATURE', 'whatsapp_business_app_onboarding'),

        /*
         * Suscribir la app al WABA al terminar el onboarding. Sin esto los
         * webhooks no llegan aunque la conexión figure como correcta, y el
         * síntoma —«conectado pero no entra nada»— cuesta horas de diagnóstico.
         */
        'subscribe_app' => filter_var(env('META_EMBEDDED_SIGNUP_SUBSCRIBE', true), FILTER_VALIDATE_BOOLEAN),

        /*
         * ¿La conexión guardada en base de datos tiene precedencia sobre el
         * .env? Sí por defecto: es lo que hace que conectar desde la pantalla
         * sirva de algo. Se puede apagar para forzar el .env sin desconectar
         * nada, que es la vuelta atrás más rápida si una conexión sale mal.
         */
        'db_credentials_precedence' => filter_var(
            env('META_DB_CREDENTIALS_PRECEDENCE', true),
            FILTER_VALIDATE_BOOLEAN,
        ),

        // Minutos de validez del `state` que emite el CRM antes de abrir Meta.
        'state_ttl_minutes' => (int) env('META_EMBEDDED_SIGNUP_STATE_TTL', 30),

        /*
        |----------------------------------------------------------------------
        | Modo DEMOSTRACIÓN para la revisión de Meta
        |----------------------------------------------------------------------
        | Existe por un bloqueo circular: la coexistencia
        | (`whatsapp_business_app_onboarding`) exige Advanced Access sobre los
        | permisos de WhatsApp, y Advanced Access es justo lo que se está
        | pidiendo en la revisión. Meta corta con el error #4563039 antes de
        | llegar a ninguna parte.
        |
        | Este modo ejecuta el MISMO Embedded Signup sin ese parámetro, sobre una
        | WABA de prueba. No es una simulación: el login, la selección de activos,
        | la autorización, el código y el canje son reales; lo único que cambia
        | es que no se pide el emparejamiento con la app WhatsApp Business.
        |
        | Apagado por defecto. Encenderlo es una decisión explícita y temporal:
        | en cuanto Meta apruebe, se apaga y la sección desaparece del CRM.
        */
        'review' => [
            'enabled' => filter_var(
                env('META_EMBEDDED_SIGNUP_REVIEW_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN,
            ),

            /*
            |------------------------------------------------------------------
            | Activos que una DEMOSTRACION no puede tocar jamas
            |------------------------------------------------------------------
            | Estas listas rigen SOLO cuando purpose=review. En coexistencia
            | productiva el numero y la WABA del gimnasio son el objetivo
            | legitimo -esa es toda la razon de ser del modulo-, asi que
            | bloquearlos ahi romperia el proposito.
            |
            | Los valores por defecto NO estan vacios a proposito. Una lista
            | vacia convierte la barrera en decorativa, y el `.env` del servidor
            | no declara ninguna de estas variables: si el unico sitio donde
            | viviera el dato fuera el entorno, la proteccion no existiria hasta
            | que alguien se acordara de rellenarlo. El default es el valor real
            | y esta escrito aqui, a la vista, no escondido en un servicio.
            |
            | Y se leen con $envConRespaldo, no con env() a secas: una variable
            | DECLARADA VACIA no puede apagar una barrera de seguridad por
            | descuido. Para cambiar la lista hay que escribir otra lista.
            */

            /*
             * WABA productiva. Es la barrera de primer nivel y la unica que no
             * necesita preguntarle nada a la red: se comprueba ANTES de canjear
             * el codigo, asi que un intento contra ella no llega a gastar nada.
             */
            'protected_waba_ids' => $listaDeIds($envConRespaldo(
                'META_EMBEDDED_SIGNUP_REVIEW_PROTECTED_WABA_IDS',
                '1381789767207733',
            )),

            /*
             * Identificadores de numero protegidos, comparados tal cual.
             *
             * Vacio por defecto y a proposito: hoy NO conocemos el
             * phone_number_id real del numero productivo bajo la WABA nueva.
             * META_WHATSAPP_PHONE_NUMBER_ID contiene 1221649421024405, un ID
             * borrado el 2026-06-30 que Graph ya no resuelve, y por eso dejo de
             * usarse como fuente autoritativa: comparar contra el no protegia
             * nada y daba apariencia de proteccion.
             *
             * Mientras esta lista este vacia, quien protege es la barrera de
             * abajo, que resuelve el numero real contra Graph y falla CERRADO.
             */
            'protected_phone_number_ids' => $listaDeIds($envConRespaldo(
                'META_EMBEDDED_SIGNUP_REVIEW_PROTECTED_PHONE_NUMBER_IDS',
                '',
            )),

            /*
             * Telefonos protegidos, por digitos. Hereda la lista general salvo
             * que se declare una propia para el modo demostracion.
             */
            'protected_numbers' => $listaDeTelefonos($envConRespaldo(
                'META_EMBEDDED_SIGNUP_REVIEW_PROTECTED_NUMBERS',
                implode(',', $telefonosProtegidos),
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Números que NADIE puede conectar desde el CRM
    |--------------------------------------------------------------------------
    | El número del gimnasio vive en la app WhatsApp Business y ahí tiene que
    | seguir: registrarlo en Cloud API se lo quita al personal, que pierde
    | WhatsApp Web. Ya pasó el 2026-06-30 y hubo que deshacerlo.
    |
    | Si un onboarding devuelve uno de estos números, el backend se niega a
    | guardarlo y lo dice. Es una red de seguridad, no la principal: la elección
    | ocurre dentro del diálogo de Meta y esto actúa después. Pero convierte un
    | descuido en un mensaje de error en vez de en una conexión que nadie quería.
    */
    // Se comparan solo los dígitos: +57 314 345 5483 y 573143455483 son el
    // mismo teléfono escrito de dos maneras (ver $listaDeTelefonos arriba).
    'protected_numbers' => $telefonosProtegidos,
];
