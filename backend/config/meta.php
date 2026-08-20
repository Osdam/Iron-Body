<?php

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
         * cuenta (WABA, números, plantillas), `whatsapp_business_messaging`
         * envía y recibe, y `business_management` es el que está en revisión.
         * Meta puede conceder menos de lo pedido: lo concedido de verdad se
         * guarda en la fila de la integración, no se da por hecho.
         */
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'META_EMBEDDED_SIGNUP_SCOPES',
                'whatsapp_business_management,whatsapp_business_messaging,business_management',
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
    ],
];
