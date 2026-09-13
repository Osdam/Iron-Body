<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificación en dos pasos (OTP por SMS)
    |--------------------------------------------------------------------------
    | Toda la lógica de OTP vive en el backend. El canal de envío es pluggable:
    | en desarrollo el driver `dev` no envía SMS reales (registra el código en el
    | log y, si `expose_code` está activo, lo devuelve en la respuesta para poder
    | probar). Para producción basta cambiar `OTP_DRIVER` a `twilio`/`labsmobile`
    | y rellenar sus credenciales — sin tocar una línea de código.
    */

    // dev | twilio | labsmobile
    'driver' => env('OTP_DRIVER', 'dev'),

    // Dígitos del código.
    'length' => (int) env('OTP_CODE_LENGTH', 6),

    // Vigencia del código en segundos.
    'ttl' => (int) env('OTP_TTL_SECONDS', 300),

    // Intentos de código equivocado antes de bloquear el reto.
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    // Reenvíos permitidos por reto y enfriamiento mínimo entre envíos (seg).
    'max_resends'      => (int) env('OTP_MAX_RESENDS', 3),
    'resend_cooldown'  => (int) env('OTP_RESEND_COOLDOWN', 60),

    // Devolver el código en la respuesta de la API (SOLO útil con driver dev).
    // En producción debe quedar en false aunque el driver sea real.
    'expose_code' => filter_var(env('OTP_EXPOSE_CODE', env('OTP_DRIVER', 'dev') === 'dev'), FILTER_VALIDATE_BOOLEAN),

    // Si el miembro no tiene teléfono registrado: ¿saltar el OTP y entrar igual?
    // En la base de demo hay miembros sin teléfono; por defecto se permite para
    // no bloquearlos. En producción ponlo en false para forzar el 2FA siempre.
    'skip_when_no_phone' => filter_var(env('OTP_SKIP_WHEN_NO_PHONE', true), FILTER_VALIDATE_BOOLEAN),

    // Detección de velocidad sospechosa: si en `window` segundos llegan retos
    // desde más de `max_devices` dispositivos distintos, se marca sospechoso.
    'suspicious' => [
        'window'      => (int) env('OTP_SUSPICIOUS_WINDOW', 600),
        'max_devices' => (int) env('OTP_SUSPICIOUS_MAX_DEVICES', 3),
    ],

    // Marca del remitente que aparece en el cuerpo del SMS.
    'brand' => env('OTP_BRAND', 'Iron Body'),

    /*
    |--------------------------------------------------------------------------
    | Control de concurrencia (cuenta única / dispositivo principal)
    |--------------------------------------------------------------------------
    | block_concurrent=true: si la cuenta ya está activa en OTRO dispositivo, el
    | nuevo intento se BLOQUEA ("La cuenta ya está en uso en otro dispositivo
    | principal") en lugar de robarle la sesión. Una sesión se considera "viva"
    | si tuvo actividad dentro de `session_grace` segundos; pasado ese tiempo se
    | permite el relevo (takeover) para no dejar al usuario bloqueado si cerró la
    | app sin cerrar sesión. Pon session_grace muy alto para bloqueo estricto.
    */
    'concurrency' => [
        'block_concurrent' => filter_var(env('OTP_BLOCK_CONCURRENT', true), FILTER_VALIDATE_BOOLEAN),
        'session_grace'    => (int) env('OTP_SESSION_GRACE', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vigencia de la sesión de dispositivo (TTL deslizante por inactividad)
    |--------------------------------------------------------------------------
    | Una sesión sigue viva mientras tenga actividad (`last_seen_at`) dentro de
    | los últimos `ttl_days` días. La app refresca esa marca en cada request, así
    | que un usuario activo NO se desloguea; sólo caducan sesiones realmente
    | inactivas. Acota la ventana en que un `session_token` filtrado sigue siendo
    | válido. 0 = sin expiración por inactividad.
    */
    'session' => [
        'ttl_days' => (int) env('OTP_SESSION_TTL_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verificación facial del titular (reconocimiento on-device)
    |--------------------------------------------------------------------------
    | Tras el OTP se exige un escaneo facial cuyo emparejamiento contra la foto
    | de referencia del registro ocurre EN EL DISPOSITIVO (TFLite). El backend
    | entrega la referencia sólo a una sesión recién verificada por OTP (ticket),
    | recibe el veredicto, lo audita y sólo entonces emite la sesión.
    |   - required: exige cara cuando el miembro tiene referencia facial.
    |   - ticket_ttl: ventana (seg) para completar la cara tras el OTP.
    |   - max_attempts: intentos faciales antes de invalidar el ticket.
    */
    'face' => [
        'enabled'      => filter_var(env('OTP_FACE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'required'     => filter_var(env('OTP_FACE_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
        'ticket_ttl'   => (int) env('OTP_FACE_TICKET_TTL', 600),
        'max_attempts' => (int) env('OTP_FACE_MAX_ATTEMPTS', 3),
        // Guardar el selfie de cada verificación para auditoría (privado).
        'store_selfie' => filter_var(env('OTP_FACE_STORE_SELFIE', false), FILTER_VALIDATE_BOOLEAN),

        /*
        | Re-enrolamiento biométrico cross-platform.
        | Cuando una referencia LEGACY (sin normalizer_version) falla con un
        | score "casi" (banda controlada), se ofrece actualizar el rostro tras
        | un segundo factor (OTP). NO baja el umbral de match ni acepta a otra
        | persona: si la distancia es enorme se trata como low_score normal.
        |   - reenroll.score_max: distancia máxima (euclídea) para considerarlo
        |     "incompatibilidad de plantilla" y ofrecer re-enrolamiento.
        |   - reenroll.token_ttl: vida del token de un solo uso (seg).
        */
        'reenroll' => [
            'enabled'    => filter_var(env('OTP_FACE_REENROLL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'score_max'  => (float) env('OTP_FACE_REENROLL_SCORE_MAX', 1.6),
            'token_ttl'  => (int) env('OTP_FACE_REENROLL_TOKEN_TTL', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vínculo dispositivo ↔ titular (anti-uso-compartido por equipo)
    |--------------------------------------------------------------------------
    | Si está activo, un dispositivo queda asociado al primer miembro que lo
    | verifica; otro documento en ese equipo recibe "cuenta asociada a otro
    | usuario" hasta que un admin lo libere.
    */
    'device_binding' => [
        'enabled' => filter_var(env('OTP_DEVICE_BINDING', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credenciales de proveedores (rellenar sólo el que se vaya a usar)
    |--------------------------------------------------------------------------
    */
    // Twilio. Acepta los nombres clásicos (TWILIO_SID/TOKEN) y los de la consola
    // (TWILIO_ACCOUNT_SID/AUTH_TOKEN). Dos modos:
    //   - Verify API (recomendado): define TWILIO_VERIFY_SERVICE_SID. Twilio
    //     genera/envía/valida el código (no necesita un número `from`).
    //   - Messages API: define TWILIO_FROM (número E.164) sin Verify SID.
    'twilio' => [
        'sid'               => env('TWILIO_SID', env('TWILIO_ACCOUNT_SID')),
        'token'             => env('TWILIO_TOKEN', env('TWILIO_AUTH_TOKEN')),
        'from'              => env('TWILIO_FROM'),
        'base'              => env('TWILIO_BASE', 'https://api.twilio.com'),
        'verify_service_sid'=> env('TWILIO_VERIFY_SERVICE_SID'),
        'verify_base'       => env('TWILIO_VERIFY_BASE', 'https://verify.twilio.com'),
        // unique_name del Service Rate Limit creado en Twilio. Vacío = no se
        // envía la llave (referenciar uno inexistente rompe el envío entero).
        'rate_limit_unique_name' => env('TWILIO_VERIFY_RATE_LIMIT_NAME', ''),
    ],

    // Prefijo de país por defecto para normalizar a E.164 (Colombia = 57).
    'default_country_code' => (string) env('OTP_DEFAULT_COUNTRY_CODE', '57'),

    'labsmobile' => [
        'username' => env('LABSMOBILE_USERNAME'),
        'token'    => env('LABSMOBILE_TOKEN'),
        'sender'   => env('LABSMOBILE_SENDER', 'IronBody'),
        'base'     => env('LABSMOBILE_BASE', 'https://api.labsmobile.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Emisor SMS heredado (Programmable Messaging) — CERRADO por defecto
    |--------------------------------------------------------------------------
    | Con Verify activo este camino no se alcanza nunca. Se deja explícitamente
    | fail-closed para que rellenar TWILIO_FROM en el futuro NO reviva por
    | accidente un segundo camino facturable. Un solo emisor: Twilio Verify.
    */
    'legacy_sms_sender_enabled' => filter_var(env('OTP_ALLOW_LEGACY_SMS_SENDER', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Interruptor de emisión (kill switch)
    |--------------------------------------------------------------------------
    | En false NINGUNA operación que envíe un SMS nuevo (start/resend) llega al
    | proveedor. NO afecta a la comprobación de códigos ya enviados ni a las
    | sesiones vivas: un usuario con un código en la mano sigue pudiendo entrar.
    */
    'send_enabled' => filter_var(env('TWILIO_SEND_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Política de coste — límites propios antes de tocar al proveedor
    |--------------------------------------------------------------------------
    | Calibrados contra el tráfico real auditado del 1 al 12 de septiembre de
    | 2026: el 89 % de los socios pide 2 códigos o menos en un día y el máximo
    | observado fueron 11. El teléfono es la llave FUERTE; la IP es señal
    | secundaria y va holgada a propósito, porque una sola IP (la recepción del
    | gimnasio) da servicio a 24 socios legítimos.
    */
    'policy' => [
        // Mínimo entre dos envíos al mismo sujeto y propósito. Sólo aplica si el
        // reto anterior NO se verificó: volver a entrar tras un login correcto
        // es legítimo y no se penaliza. Queda subsumido por el reuso mientras
        // el código siga vivo (ttl = 300 s > 60 s).
        'start_cooldown' => (int) env('OTP_START_COOLDOWN', 60),

        'phone' => [
            'window'       => (int) env('OTP_PHONE_WINDOW', 900),
            'window_limit' => (int) env('OTP_PHONE_WINDOW_LIMIT', 3),
            'daily_limit'  => (int) env('OTP_PHONE_DAILY_LIMIT', 8),
        ],
        'account' => [
            'window'       => (int) env('OTP_ACCOUNT_WINDOW', 900),
            'window_limit' => (int) env('OTP_ACCOUNT_WINDOW_LIMIT', 4),
            'daily_limit'  => (int) env('OTP_ACCOUNT_DAILY_LIMIT', 10),
        ],
        'ip' => [
            'window'       => (int) env('OTP_IP_WINDOW', 900),
            'window_limit' => (int) env('OTP_IP_WINDOW_LIMIT', 40),
            'daily_limit'  => (int) env('OTP_IP_DAILY_LIMIT', 200),
        ],

        // Candado atómico alrededor del inicio de verificación. El TTL supera el
        // timeout del cliente Twilio (15 s) para que un worker caído lo suelte.
        'lock_seconds' => (int) env('OTP_START_LOCK_SECONDS', 20),
        'lock_wait'    => (int) env('OTP_START_LOCK_WAIT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Techo financiero propio (circuit breaker)
    |--------------------------------------------------------------------------
    | Los precios son ESTIMACIONES para decidir en caliente, no contabilidad: la
    | cifra real siempre es la de los Usage Records de Twilio. Valores tomados de
    | la factura auditada: 0,0592 USD por SMS en Colombia y 0,05 USD por
    | verificación completada.
    |
    | El gasto real del peor día auditado fue 9,99 USD (3 de septiembre) ANTES de
    | las protecciones; ese mismo día con reuso y login adaptativo queda en unos
    | 5,6 USD. De ahí los umbrales: el blando avisa por encima de un pico
    | legítimo y el duro sólo salta ante una fuga real.
    */
    'cost' => [
        'sms_start'    => (float) env('TWILIO_COST_SMS_START', 0.0592),
        'verification' => (float) env('TWILIO_COST_VERIFICATION', 0.05),
        'daily_soft'   => (float) env('TWILIO_DAILY_SOFT_LIMIT_USD', 6.0),
        'daily_hard'   => (float) env('TWILIO_DAILY_HARD_LIMIT_USD', 12.0),
    ],

];
