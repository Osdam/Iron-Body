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
];
