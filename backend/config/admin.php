<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Token de acceso administrativo (CRM / panel)
    |--------------------------------------------------------------------------
    |
    | Secreto compartido que blinda TODAS las rutas /api/admin/* y los pagos
    | legacy (/api/payments). El CRM debe enviarlo como `Authorization: Bearer
    | <token>`. Sin este header (o con un valor distinto) la petición recibe 401.
    |
    | Si está vacío, el middleware FALLA CERRADO (deniega todo /api/admin/*):
    | preferimos romper el panel antes que volver a exponer datos sensibles.
    |
    | Genera uno fuerte con: php artisan key:generate --show  (o `openssl rand`).
    |
    */
    'api_token' => env('ADMIN_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Sesiones del panel (login email + contraseña)
    |--------------------------------------------------------------------------
    |
    | Caducidad del token de sesión admin. `session_ttl_minutes` aplica al login
    | normal; `session_remember_days` cuando el usuario marca "recuérdame".
    |
    */
    'session_ttl_minutes' => (int) env('ADMIN_SESSION_TTL_MINUTES', 720), // 12 h
    'session_remember_days' => (int) env('ADMIN_SESSION_REMEMBER_DAYS', 30),
];
