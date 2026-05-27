<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (push nativo, HTTP v1)
    |--------------------------------------------------------------------------
    | El backend envía push a los dispositivos del miembro vía la API HTTP v1 de
    | FCM, autenticándose con una cuenta de servicio (service account JSON). Las
    | credenciales viven SOLO aquí; la app nunca las ve.
    |
    | Para activarlo:
    |   1. En la consola de Firebase → Configuración del proyecto → Cuentas de
    |      servicio → "Generar nueva clave privada". Descarga el JSON.
    |   2. Guárdalo fuera del control de versiones, p. ej.
    |      storage/app/firebase/service-account.json
    |   3. En .env:
    |        FCM_ENABLED=true
    |        FCM_PROJECT_ID=tu-project-id
    |        FCM_CREDENTIALS=storage/app/firebase/service-account.json
    |
    | Si falta el archivo o FCM_ENABLED=false, el envío es un no-op (se registra
    | en log) y el resto del sistema (SSE in-app) sigue funcionando igual.
    */

    'enabled' => filter_var(env('FCM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // project_id de Firebase (también se puede leer del propio JSON).
    'project_id' => env('FCM_PROJECT_ID'),

    // Ruta al service account JSON (absoluta o relativa a base_path()).
    'credentials' => env('FCM_CREDENTIALS', 'storage/app/firebase/service-account.json'),

    // TTL del access token OAuth2 cacheado (segundos). Google emite ~3600.
    'token_ttl' => (int) env('FCM_TOKEN_TTL', 3300),

    // Sólo se empuja push para notificaciones de miembro marcadas como
    // importantes (should_popup). Evita saturar con eventos menores.
    'only_popup' => filter_var(env('FCM_ONLY_POPUP', true), FILTER_VALIDATE_BOOLEAN),
];
