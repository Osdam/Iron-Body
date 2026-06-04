<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suspensión por actividad sospechosa (Fase 10)
    |--------------------------------------------------------------------------
    | El sistema calcula un "puntaje de riesgo" a partir de los eventos de
    | seguridad recientes del miembro (OTP fallidos, reenvíos, dispositivos
    | nuevos, fallos faciales, actividad concurrente). Según el puntaje:
    |   - >= warn_threshold   → se registra evento + se avisa (miembro/CRM).
    |   - >= suspend_threshold → se SUSPENDE 3 días… SOLO si `autosuspend` = true.
    |
    | Por defecto `autosuspend` está APAGADO: el sistema solo avisa, para evitar
    | falsos positivos. Cuando se valide en dispositivo, poner SECURITY_AUTOSUSPEND
    | en true para activar el bloqueo automático. Los bloqueos MANUALES del CRM
    | aplican siempre, independientemente de este flag.
    */
    'autosuspend' => filter_var(env('SECURITY_AUTOSUSPEND', false), FILTER_VALIDATE_BOOLEAN),

    // Días de suspensión automática por defecto.
    'suspend_days' => (int) env('SECURITY_SUSPEND_DAYS', 3),

    // Ventana (segundos) en la que se acumulan las señales de riesgo.
    'window' => (int) env('SECURITY_RISK_WINDOW', 3600),

    // Umbrales de puntaje.
    'warn_threshold'    => (int) env('SECURITY_WARN_THRESHOLD', 40),
    'suspend_threshold' => (int) env('SECURITY_SUSPEND_THRESHOLD', 80),

    /*
    | Peso de cada señal sobre el puntaje de riesgo (conservadores para no
    | castigar errores normales: un par de OTP equivocados NO suspenden).
    */
    'weights' => [
        'login_failed'   => (int) env('SECURITY_W_LOGIN_FAILED', 8),
        'otp_blocked'    => (int) env('SECURITY_W_OTP_BLOCKED', 25),
        'new_device'     => (int) env('SECURITY_W_NEW_DEVICE', 15),
        'face_failed'    => (int) env('SECURITY_W_FACE_FAILED', 20),
        'concurrent'     => (int) env('SECURITY_W_CONCURRENT', 15),
        'device_mismatch'=> (int) env('SECURITY_W_DEVICE_MISMATCH', 30),
        'suspicious'     => (int) env('SECURITY_W_SUSPICIOUS', 25),
    ],

    // Evita avisar en bucle: mínimo (segundos) entre warnings de un miembro.
    'warn_cooldown' => (int) env('SECURITY_WARN_COOLDOWN', 1800),

    /*
    |--------------------------------------------------------------------------
    | Login adaptativo por riesgo (Bloque 3b)
    |--------------------------------------------------------------------------
    | APAGADO por defecto: con `adaptive_login=false` el login se comporta
    | EXACTAMENTE igual que hoy (OTP + cara siempre). Al activarlo, la fuerza del
    | login se adapta a la confianza del dispositivo y al puntaje de riesgo:
    |   - dispositivo NO confiable (sin vínculo)        → OTP + cara.
    |   - confiable + riesgo alto (>= warn_threshold)    → OTP + cara (step-up).
    |   - confiable + riesgo medio (>= local_threshold)  → solo OTP.
    |   - confiable + riesgo bajo (< local_threshold)    → desbloqueo local
    |       (Face ID/huella del dispositivo + ticket; sin SMS ni match facial).
    | Un dispositivo es "confiable" si ya está vinculado a ESTE miembro
    | (member_device_bindings), es decir, completó un login fuerte antes.
    */
    'adaptive_login' => filter_var(env('SECURITY_ADAPTIVE_LOGIN', false), FILTER_VALIDATE_BOOLEAN),

    // Bajo este puntaje (y con dispositivo confiable) se permite desbloqueo local.
    // Por defecto 1 ⇒ solo riesgo CERO entra con desbloqueo local; cualquier
    // señal de riesgo exige al menos OTP.
    'local_threshold' => (int) env('SECURITY_LOCAL_THRESHOLD', 1),

    // Vigencia (segundos) del ticket de desbloqueo local.
    'local_ticket_ttl' => (int) env('SECURITY_LOCAL_TICKET_TTL', 180),
];
