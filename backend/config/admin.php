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
];
