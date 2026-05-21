<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'member_registration' => [
        'token' => env('MEMBER_REGISTRATION_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WorkoutX — referencias visuales de ejercicios (GIF)
    |--------------------------------------------------------------------------
    | Proveedor principal de GIFs/metadatos de ejercicios. La API key vive SOLO
    | aquí (backend). Flutter jamás la ve: consume únicamente los endpoints
    | internos /api/exercises de este backend.
    */
    'workoutx' => [
        'api_key'  => env('WORKOUTX_API_KEY'),
        'base_url' => rtrim(env('WORKOUTX_BASE_URL', 'https://api.workoutxapp.com'), '/'),
        // Prefijo de versión de la API WorkoutX (todas las rutas cuelgan de /v1).
        'api_prefix' => '/' . trim(env('WORKOUTX_API_PREFIX', 'v1'), '/'),
        'provider' => 'workoutx',
        // Cabecera de autenticación oficial de WorkoutX (la key empieza por wx_).
        'auth_header' => env('WORKOUTX_AUTH_HEADER', 'X-WorkoutX-Key'),
        // TTL de caché de respuestas (segundos). 12h por defecto.
        'cache_ttl' => (int) env('WORKOUTX_CACHE_TTL', 43200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Referencias visuales de ejercicios — conmutador de proveedor
    |--------------------------------------------------------------------------
    | `provider`: fitgif | workoutx | local. WorkoutX queda como fallback.
    | Las flags controlan si se sirven GIFs de cada fuente (la del plan Free de
    | WorkoutX trae marca de agua → por defecto apagada).
    */
    'exercises' => [
        'provider'             => env('EXERCISE_PROVIDER', 'fitgif'),
        'show_workoutx_gifs'   => filter_var(env('SHOW_WORKOUTX_GIFS', false), FILTER_VALIDATE_BOOLEAN),
        'show_fitgif_gifs'     => filter_var(env('SHOW_FITGIF_GIFS', true), FILTER_VALIDATE_BOOLEAN),
        // Fallback a Free Exercise DB. En la demo se apaga: si FitGif falla se
        // devuelve placeholder, NO fotos de Free Exercise DB.
        'show_free_exercise_db' => filter_var(env('SHOW_FREE_EXERCISE_DB', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | FitGif — proveedor principal de referencia visual (GIF animado)
    |--------------------------------------------------------------------------
    | API real: POST {base}/api/search  body {key, search, bodyPart,
    | includeData}. Devuelve GIFs animados (Supabase signed URL). Es de pago:
    | la API key vive SOLO aquí; Flutter nunca la ve ni llama a FitGif.
    | Los GIFs se sirven por proxy del backend (/api/exercises/fitgif/gif/{id})
    | porque las URLs firmadas pueden expirar.
    */
    'fitgif' => [
        'provider'     => 'fitgif',
        'base_url'     => rtrim(env('FITGIF_BASE_URL', 'https://fitgif.vercel.app'), '/'),
        'api_key'      => env('FITGIF_API_KEY'),
        'cache_ttl'    => (int) env('FITGIF_CACHE_TTL', 86400),
        'source_label' => 'FitGif',
        // Transcode MP4 (ffmpeg local). Ajustable sin tocar código.
        'video_speed'  => (float) env('FITGIF_VIDEO_SPEED', 1.5),
        'video_fps'    => (int) env('FITGIF_VIDEO_FPS', 60),
        'video_width'  => (int) env('FITGIF_VIDEO_WIDTH', 540),
        'video_crf'    => (int) env('FITGIF_VIDEO_CRF', 26),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free Exercise DB — fallback open data (imágenes estáticas, sin watermark)
    |--------------------------------------------------------------------------
    | Dataset "yuhonas/free-exercise-db" vía CDN jsDelivr. SIN API key. Se usa
    | solo como fallback si FitGif (y WorkoutX, si está habilitado) no responden.
    */
    'freeexercisedb' => [
        'provider'     => 'freeexercisedb',
        'base_url'     => rtrim(env('FREEEXERCISEDB_BASE_URL', 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main'), '/'),
        'cache_ttl'    => (int) env('FREEEXERCISEDB_CACHE_TTL', 86400),
        'source_label' => 'Free Exercise DB',
    ],

    /*
    |--------------------------------------------------------------------------
    | ePayco (Davivienda) — pasarela de pagos
    |--------------------------------------------------------------------------
    | Todas las llaves viven SOLO aquí (backend). La app Flutter nunca las ve:
    | solo recibe un `checkout_url` ya construido por el backend.
    | `p_cust_id_cliente` y `p_key` son necesarias para validar la firma del
    | webhook; si están vacías, la confirmación se valida consultando la API de
    | ePayco por `ref_payco` (fuente de verdad alterna).
    */
    'epayco' => [
        'test' => filter_var(env('EPAYCO_TEST', true), FILTER_VALIDATE_BOOLEAN),
        'public_key' => env('EPAYCO_PUBLIC_KEY'),
        'private_key' => env('EPAYCO_PRIVATE_KEY'),
        'p_cust_id_cliente' => env('EPAYCO_P_CUST_ID_CLIENTE'),
        'p_key' => env('EPAYCO_P_KEY'),
        // Si quedan vacías se generan a partir de APP_URL (rutas internas).
        'response_url' => env('EPAYCO_RESPONSE_URL'),
        'confirmation_url' => env('EPAYCO_CONFIRMATION_URL'),
        // Consulta de estado: ahora se usa el SDK oficial
        // (`charge->transaction` → host correcto `secure.payco.co`).
        // El host anterior `api.secure.epayco.co` NO existe (error DNS).
        'validation_url' => 'https://api.secure.payco.co',
        // API REST (apify) para pago in-app por token/transacción.
        'apify_base' => env('EPAYCO_APIFY_BASE', 'https://apify.epayco.co'),
    ],

];
