<?php

/*
|--------------------------------------------------------------------------
| Contenido generado por usuarios (UGC) y moderación de comunidad
|--------------------------------------------------------------------------
|
| Configuración central del sistema de reportes, bloqueos y sanciones de
| Stories. Todo se lee desde aquí — ningún umbral vive hardcodeado en un
| controlador. Los valores por defecto son los SEGUROS: la cuarentena
| automática viene apagada y ninguna sanción permanente puede aplicarse sin
| revisión humana.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptores maestros
    |--------------------------------------------------------------------------
    |
    | Permiten desactivar una capacidad sin desplegar código. Si
    | `reports_enabled` se apaga, el endpoint responde 503 con un código
    | estable y la app oculta la opción — nunca finge que reportó.
    |
    */
    'reports_enabled' => (bool) env('UGC_REPORTS_ENABLED', true),
    'blocking_enabled' => (bool) env('UGC_BLOCKING_ENABLED', true),
    'moderation_enabled' => (bool) env('UGC_MODERATION_ENABLED', true),
    'appeals_enabled' => (bool) env('UGC_APPEALS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cuarentena automática (defensiva, apagada por defecto)
    |--------------------------------------------------------------------------
    |
    | Si se activa, una Story se oculta TEMPORALMENTE del feed cuando la
    | reportan N personas DISTINTAS. No elimina nada, no sanciona a nadie y
    | siempre exige revisión humana posterior. Varios reportes del mismo
    | usuario cuentan como UNO (el conteo es de reportantes únicos).
    |
    */
    'auto_quarantine_enabled' => (bool) env('UGC_AUTO_QUARANTINE_ENABLED', false),
    'auto_quarantine_unique_reporters' => (int) env('UGC_AUTO_QUARANTINE_UNIQUE_REPORTERS', 5),

    /*
    | Motivos que, por su gravedad, bajan el umbral de cuarentena automática a
    | este valor. Aun así NUNCA sancionan al autor de forma automática.
    */
    'auto_quarantine_critical_reporters' => (int) env('UGC_AUTO_QUARANTINE_CRITICAL_REPORTERS', 2),

    /*
    |--------------------------------------------------------------------------
    | Límites anti-abuso
    |--------------------------------------------------------------------------
    */
    'report_rate_limit_per_hour' => (int) env('UGC_REPORT_RATE_LIMIT_PER_HOUR', 10),
    'block_rate_limit_per_hour' => (int) env('UGC_BLOCK_RATE_LIMIT_PER_HOUR', 30),
    'appeal_rate_limit_per_day' => (int) env('UGC_APPEAL_RATE_LIMIT_PER_DAY', 5),

    /** Longitud máxima del texto libre del reportante. */
    'report_detail_max_length' => (int) env('UGC_REPORT_DETAIL_MAX_LENGTH', 500),

    /** Longitud máxima del texto de una apelación. */
    'appeal_text_max_length' => (int) env('UGC_APPEAL_TEXT_MAX_LENGTH', 1000),

    /*
    |--------------------------------------------------------------------------
    | Retención de evidencia
    |--------------------------------------------------------------------------
    |
    | Días que se conserva el binario de una Story reportada DESPUÉS de que el
    | caso se cierre. Mientras haya un caso abierto el archivo no se borra,
    | sin importar este valor.
    |
    */
    'evidence_retention_days' => (int) env('UGC_EVIDENCE_RETENTION_DAYS', 90),

    /** Minutos de validez de la URL firmada con que el CRM ve la evidencia. */
    'evidence_signed_url_minutes' => (int) env('UGC_EVIDENCE_SIGNED_URL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Edad
    |--------------------------------------------------------------------------
    |
    | `Member::MIN_REGISTRATION_AGE` (11) es la edad mínima para USAR la app y
    | NO se modifica aquí. `posting_min_age` es un requisito INDEPENDIENTE para
    | PUBLICAR contenido.
    |
    | `posting_age_enforced` viene APAGADO a propósito: `members.birth_date` es
    | nullable y hoy hay miembros sin fecha fiable. Encenderlo sin ese dato
    | bloquearía a usuarios legítimos o, peor, asumiría que todos son adultos.
    | Cuando el dato esté completo, se activa con una variable — el código ya
    | está listo y probado.
    |
    */
    'posting_min_age' => (int) env('UGC_POSTING_MIN_AGE', 13),
    'posting_age_enforced' => (bool) env('UGC_POSTING_AGE_ENFORCED', false),

    /*
    | Qué hacer con un miembro SIN fecha de nacimiento cuando la verificación
    | está activa: 'allow' (no bloquear a quien no tiene el dato) o 'block'.
    | Por defecto 'allow' — no inventamos una edad ni marcamos a nadie adulto.
    */
    'posting_age_unknown_policy' => env('UGC_POSTING_AGE_UNKNOWN_POLICY', 'allow'),

    /*
    |--------------------------------------------------------------------------
    | Lineamientos de comunidad
    |--------------------------------------------------------------------------
    |
    | Versión vigente. Al subirla, se vuelve a pedir la aceptación ANTES de
    | publicar una Story. Nunca bloquea el resto de la app.
    |
    */
    'guidelines_version' => env('UGC_GUIDELINES_VERSION', '1.0'),
    'guidelines_required_to_post' => (bool) env('UGC_GUIDELINES_REQUIRED', true),
    'guidelines_url' => env('UGC_GUIDELINES_URL', 'https://ironbody.com.co/lineamientos-comunidad'),

];
