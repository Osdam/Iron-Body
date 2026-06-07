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
    | Firebase — service account (Auth custom token, Storage server-side, FCM)
    |--------------------------------------------------------------------------
    | El service-account.json vive SOLO en el backend y firma: (1) custom tokens
    | de Firebase Auth (FirebaseCustomTokenService), (2) borrado físico de
    | objetos en Storage (FirebaseStorageService). NO se configura un disk de
    | Laravel para Firebase: los binarios los sube la app directo a Storage; el
    | backend solo guarda metadata y, si hace falta, borra vía service account.
    |
    | `credentials` reutiliza el mismo JSON del FCM. `storage_bucket` es el del
    | proyecto (mismo que usan los reels).
    */
    'firebase' => [
        'credentials' => env(
            'FIREBASE_CREDENTIALS',
            env('FCM_CREDENTIALS', 'storage/app/firebase/service-account.json')
        ),
        'storage_bucket' => env(
            'FIREBASE_STORAGE_BUCKET',
            'iron-body-85fc3.firebasestorage.app'
        ),
        'project_id' => env('FIREBASE_PROJECT_ID', env('FCM_PROJECT_ID', 'iron-body-85fc3')),
    ],

    /*
    |--------------------------------------------------------------------------
    | IRON IA — asistente con OpenAI
    |--------------------------------------------------------------------------
    | Arquitectura: Flutter → Laravel → OpenAI. La API key vive SOLO aquí
    | (backend). Flutter jamás la ve ni llama a OpenAI: consume únicamente los
    | endpoints internos /api/iron-ai de este backend.
    */
    'openai' => [
        'api_key'              => env('OPENAI_API_KEY'),
        'model'                => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'base_url'             => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com'), '/'),
        // IRON IA — interruptor general y parámetros de generación.
        'enabled'              => filter_var(env('IRON_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_context_messages' => (int) env('IRON_AI_MAX_CONTEXT_MESSAGES', 12),
        'temperature'          => (float) env('IRON_AI_TEMPERATURE', 0.4),
        'max_tokens'           => (int) env('IRON_AI_MAX_TOKENS', 600),
        'timeout'              => (int) env('IRON_AI_TIMEOUT', 30),

        // IRON IA multimodal — modelos configurables (NO se queman en código).
        //  - transcription_model: chat por voz (audio → texto). Whisper o
        //    cualquier modelo de transcripción compatible (gpt-4o-transcribe…).
        //  - vision_model: análisis de imágenes. Por defecto reusa el modelo de
        //    chat (gpt-4.1-mini ya soporta visión); se puede separar por env.
        'transcription_model'  => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'vision_model'         => env('OPENAI_VISION_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
        // Timeout específico para subidas multimedia (más holgado que el chat).
        'media_timeout'        => (int) env('IRON_AI_MEDIA_TIMEOUT', 60),

        // IRON IA — conversación de voz EN VIVO (OpenAI Realtime, GA).
        // Flutter → Laravel (token efímero) → OpenAI Realtime vía WebRTC. La
        // API key real NUNCA sale del backend; Flutter usa el client_secret
        // efímero (ek_...) que devuelve este backend.
        'realtime_enabled'     => filter_var(env('IRON_AI_REALTIME_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'realtime_model'       => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
        'realtime_voice'       => env('OPENAI_REALTIME_VOICE', 'alloy'),
        // Endpoint GA para acuñar el token efímero (client_secrets).
        'realtime_secret_url'  => env('OPENAI_REALTIME_SECRET_URL', rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com'), '/') . '/v1/realtime/client_secrets'),
        // Endpoint GA de intercambio SDP (WebRTC) que usará Flutter con el ek_.
        'realtime_webrtc_url'  => env('OPENAI_REALTIME_WEBRTC_URL', rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com'), '/') . '/v1/realtime/calls'),

        // IRON IA como coach nutricional (recomendaciones desde contexto real).
        'nutrition_enabled'    => filter_var(env('IRON_AI_NUTRITION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // IRON IA Coach contextual global (plan del día desde contexto + memoria).
        'coach_enabled'        => filter_var(env('IRON_AI_COACH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
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
        // Ruta APIFY del cobro Nequi (push a la app Nequi). El SDK oficial NO
        // expone un recurso Nequi; lo llamamos directamente por APIFY (mismo
        // transporte/auth que Daviplata). Si ePayco asigna otra ruta a esta
        // cuenta, se ajusta por env SIN tocar código (degradación segura: una
        // ruta inválida devuelve error de ePayco → estado controlado, jamás
        // una aprobación falsa).
        'nequi_path' => env('EPAYCO_NEQUI_PATH', '/payment/process/pmpush'),
        // Smart Checkout v2: las billeteras (Nequi/DaviPlata) ya no fuerzan un
        // endpoint directo (la cuenta puede no tenerlos habilitados). Se crea una
        // sesión (`/payment/session/create`) y el usuario completa el pago en el
        // checkout OFICIAL de ePayco, que muestra los métodos disponibles.
        'checkout_js' => env('EPAYCO_CHECKOUT_JS', 'https://checkout.epayco.co/checkout-v2.js'),
        // Vigencia (segundos) de la URL firmada del bridge de checkout.
        'checkout_bridge_ttl' => (int) env('EPAYCO_CHECKOUT_BRIDGE_TTL', 900),
        // Métodos a OCULTAR en el Smart Checkout de wallets (Nequi/DaviPlata/
        // Davivienda). ePayco solo soporta BLACKLIST (`methodsDisable`), no
        // allow-list. Tokens documentados: TDC (tarjetas), PSE, SP (SafetyPay),
        // CASH (efectivo/Baloto/Efecty/etc.). Por defecto se ocultan esos para
        // dejar visibles las billeteras/bancos objetivo. Ajustable por env (CSV)
        // sin tocar código, p. ej. si Davivienda solo aparece vía PSE en esta
        // cuenta, quitar PSE de la lista.
        'checkout_methods_disable' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EPAYCO_CHECKOUT_METHODS_DISABLE', 'TDC,PSE,SP,CASH'))
        ), fn ($v) => $v !== '')),
    ],

    // ── Nequi DIRECTO (Pagos con notificación Push de Nequi Negocios/Conecta) ──
    // Proveedor INDEPENDIENTE de ePayco. El comercio inicia el pago por API, el
    // cliente aprueba/cancela en su app Nequi y el backend confirma por webhook
    // o consulta de estado para activar la membresía. Deshabilitado por defecto:
    // sin credenciales reales el endpoint responde `unavailable` (jamás un cobro
    // ni aprobación falsa). NUNCA se loguean llaves ni tokens.
    'nequi' => [
        'enabled'          => filter_var(env('NEQUI_DIRECT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'env'              => env('NEQUI_ENV', 'sandbox'),
        'base_url'         => env('NEQUI_API_BASE'),
        'auth_url'         => env('NEQUI_AUTH_URL'),
        'client_id'        => env('NEQUI_CLIENT_ID'),
        'client_secret'    => env('NEQUI_CLIENT_SECRET'),
        'api_key'          => env('NEQUI_API_KEY'),
        'merchant_id'      => env('NEQUI_MERCHANT_ID'),
        'webhook_secret'   => env('NEQUI_WEBHOOK_SECRET'),
        'confirmation_url' => env('NEQUI_CONFIRMATION_URL'),
        'response_url'     => env('NEQUI_RESPONSE_URL'),
        'ttl_minutes'      => (int) env('NEQUI_PAYMENT_TTL_MINUTES', 15),
    ],

    // Proveedor activo para el método Nequi en la app:
    //   direct                → Nequi push directo (services.nequi).
    //   epayco_smart_checkout → fallback técnico por el Smart Checkout de ePayco.
    //   disabled (default)    → Nequi no disponible (usar PSE/tarjeta/DaviPlata).
    // El Smart Checkout deja de ser el flujo PRINCIPAL de Nequi.
    'payments' => [
        'nequi_provider' => env('PAYMENT_NEQUI_PROVIDER', 'disabled'),
    ],

];
