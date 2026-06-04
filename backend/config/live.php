<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transmisiones en vivo (Story Live) — Bloque 5
    |--------------------------------------------------------------------------
    | Proveedor de video configurable. Por defecto LiveKit (SDK Flutter sólido,
    | tokens server-side). APAGADO por defecto: sin credenciales, la función se
    | comporta como "no disponible" (nunca crashea). Ver docs/STORY_LIVE.md.
    */

    'provider' => env('LIVE_PROVIDER', 'livekit'),

    'enabled' => filter_var(env('LIVE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'livekit' => [
        // URL del servidor LiveKit (wss://tu-proyecto.livekit.cloud).
        'url'        => env('LIVEKIT_URL'),
        'api_key'    => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        // Vigencia del token de acceso a la sala (segundos).
        'token_ttl'  => (int) env('LIVEKIT_TOKEN_TTL', 3600),
    ],
];
