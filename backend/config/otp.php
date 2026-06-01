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
    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from'  => env('TWILIO_FROM'),
        'base'  => env('TWILIO_BASE', 'https://api.twilio.com'),
    ],

    'labsmobile' => [
        'username' => env('LABSMOBILE_USERNAME'),
        'token'    => env('LABSMOBILE_TOKEN'),
        'sender'   => env('LABSMOBILE_SENDER', 'IronBody'),
        'base'     => env('LABSMOBILE_BASE', 'https://api.labsmobile.com'),
    ],

];
