<?php

/*
|--------------------------------------------------------------------------
| Personal, jornada laboral, biometría y dispositivos autorizados
|--------------------------------------------------------------------------
| Contrato: docs/asistencia-laboral/CONTRATO.md. El backend es la autoridad:
| la app y el CRM solo presentan lo que aquí se decide.
*/

return [

    /*
    | Cargos. `source` dice de dónde sale: `trainer` = rol de entrenador
    | (trainer_roles, lo administra la ficha del entrenador); `staff` = perfil de
    | personal (staff_profiles). `workday` = ese cargo registra jornada laboral.
    | El entrenador funcional solo dicta clases: no se le exige jornada por eso.
    */
    'positions' => [
        'trainer_floor' => ['label' => 'Entrenador de planta', 'source' => 'trainer', 'workday' => true],
        'trainer_functional' => ['label' => 'Entrenador funcional', 'source' => 'trainer', 'workday' => false],
        'receptionist' => ['label' => 'Recepción', 'source' => 'staff', 'workday' => true],
        'administrative' => ['label' => 'Administrativo', 'source' => 'staff', 'workday' => true],
        'maintenance' => ['label' => 'Aseo y mantenimiento', 'source' => 'staff', 'workday' => true],
    ],

    /*
    | Vínculo verificado de la ficha laboral con la ficha de socio (código SMS
    | al teléfono del socio, docs/asistencia-laboral/CONTRATO.md §7.2).
    | APAGADO por defecto. Sus dos precondiciones (H1, DISENO.md §10b) ya están
    | en el código: el código del vínculo NO va por Twilio Verify —que valida
    | teléfono + código y no el reto— sino ligado a su reto por SMS normal
    | (con OTP_DRIVER=twilio exige TWILIO_FROM; sin él, 503 y sin vínculo), y
    | todo cambio de `members.phone` deja rastro para la cuarentena. Encenderlo
    | es decisión del propietario, tras comprobar en producción que el SMS
    | normal llega al teléfono del socio presente.
    */
    'member_link_enabled' => (bool) env('WORKFORCE_MEMBER_LINK_ENABLED', false),

    'workday' => [
        // Una jornada abierta más tiempo que esto se considera una salida
        // olvidada: al marcar la siguiente entrada pasa a incidencia (sin
        // inventar hora) y se abre la nueva. Decisión del negocio 2026-10-10.
        'stale_after_hours' => (int) env('WORKFORCE_WORKDAY_STALE_HOURS', 16),
        // Tramo máximo de una consulta del CRM.
        'max_range_days' => 93,
    ],

    'face' => [
        // MobileFaceNet entrega vectores de norma 1. Un vector fuera de este
        // rango no es un rostro: casi nulo, queda a distancia ≈ 1 de cualquier
        // referencia y pasaría el umbral (ver FaceEmbeddingNormTest).
        'norm_min' => 0.9,
        'norm_max' => 1.1,
        // Distancia máxima entre las tres capturas del enrolamiento.
        'enrollment_consistency' => (float) env('WORKFORCE_FACE_ENROLLMENT_CONSISTENCY', 0.9),
        // Intentos fallidos por persona antes de bloquear la verificación.
        'max_failed_attempts' => 5,
        'lockout_minutes' => 15,
        // Versión de la plantilla: modelo + normalizador de la app.
        'template_version' => 'mobilefacenet-192/face_norm_v1',
        // Días que se guarda con qué plantilla chocó un registro rechazado por
        // «rostro de otra persona» (face_duplicate_matches). Es un diagnóstico,
        // no historial: la traza del rechazo sigue en la bitácora.
        'duplicate_match_retention_days' => 30,
        // Separación mínima entre la plantilla propia y la de otra persona más
        // cercana para que el rostro baste solo (contrato §10.1). Calibrado el
        // 2026-10-26 sobre la galería de producción (simulación con las
        // distancias genuinas reales): con 0,20 ningún intento genuino queda
        // ambiguo; con 0,30, el 1,6 %.
        'ambiguity_margin' => (float) env('WORKFORCE_FACE_AMBIGUITY_MARGIN', 0.20),
        // Con una excepción de parecido (§10.5), la plantilla de la otra persona
        // solo se ignora a esta distancia o más: más cerca es la misma cara (las
        // verificaciones genuinas del entrenador afectado promedian 0,64).
        'lookalike_exception_min_distance' => (float) env('WORKFORCE_FACE_LOOKALIKE_EXCEPTION_MIN_DISTANCE', 0.70),
    ],

    // PIN personal: segundo factor del rostro cuando el modelo no basta (§10).
    'pin' => [
        // Fallos dentro de la ventana antes de bloquearlo. Acertar no borra los
        // fallos: la ventana solo caduca con el tiempo.
        'max_attempts' => 5,
        'failure_window_hours' => 24,
        // Bloqueos sucesivos, en minutos: escalan y vuelven al primero tras
        // `lock_decay_days` sin bloqueo. Un administrador lo libera antes
        // autorizando un PIN nuevo.
        'lockout_minutes' => [15, 60, 240, 1440],
        'lock_decay_days' => 7,
    ],

    'tokens' => [
        'employee_ref_ttl' => 600,     // referencia opaca del kiosko
        'verification_ttl' => 120,     // identidad verificada → una operación
        'enrollment_ttl' => 600,       // código aceptado → completar enrolamiento
    ],

    'codes' => [
        // Códigos que genera un administrador en el CRM (enrolamiento y respaldo).
        'ttl_minutes' => 30,
        'max_attempts' => 5,
        // Código de emparejamiento de un dispositivo nuevo.
        'pairing_ttl_minutes' => 15,
    ],

    'devices' => [
        'challenge_ttl' => 120,
        /*
        | Política de dispositivo en iniciar/finalizar clase (rutas que ya usa la
        | app publicada, que no firma): `monitor` registra el dispositivo
        | declarado y deja pasar; `enforce` exige firma. Las rutas nuevas
        | (kiosko) la exigen siempre.
        */
        'classes_policy' => env('WORKFORCE_DEVICE_POLICY_CLASSES', 'monitor'),
    ],

    'classes' => [
        // Iniciar o finalizar una clase con un código de administrador en vez
        // del rostro. Apagado (regla del propietario, 2026-10-20): una clase
        // solo se opera con verificación facial real en la tablet; si el
        // rostro no pasa, administración revisa el registro facial. El
        // historial de clases ya operadas con código se conserva tal cual.
        'fallback_code' => filter_var(env('WORKFORCE_CLASS_FALLBACK_CODE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'kiosk' => [
        // Sesión del portal del entrenador abierta en una tablet compartida.
        'trainer_session_hours' => 12,
        'trainer_session_idle_minutes' => 15,
        // Abrir el portal del entrenador en la tablet con el rostro (equipo
        // firmado + rostro comparado aquí). Es la ÚNICA entrada al portal en la
        // tablet desde la app 2.0.7 y el camino para operar clases. Interruptor
        // propio para cortarlo ante un incidente: hasta 2026-10-20 lo cortaba
        // TRAINER_FACE_LOGIN_ENABLED, que también abre el login facial PÚBLICO
        // (roster sin firma) y debe seguir apagado.
        'trainer_portal_face' => filter_var(env('WORKFORCE_KIOSK_TRAINER_PORTAL_FACE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'legacy' => [
        // La app publicada inicia/finaliza clase con `face_verified: true` sin
        // comparar nada. Se acepta (y queda como `legacy_claim`) hasta que la
        // app nueva esté en todas las tablets.
        'face_claim' => filter_var(env('WORKFORCE_LEGACY_FACE_CLAIM', true), FILTER_VALIDATE_BOOLEAN),
        // Último día (Bogotá, AAAA-MM-DD) en que se acepta el rostro declarado.
        // Pasado ese día se rechaza aunque `face_claim` siga en true: la
        // transición no puede quedar abierta para siempre. Además, quien ya
        // tiene un rostro que verifica nunca puede declararlo (ClassIdentityVerifier).
        'face_claim_until' => env('WORKFORCE_LEGACY_FACE_CLAIM_UNTIL'),
        // Autoenrolamiento desde el portal del entrenador. `deny` (defecto):
        // registrar un rostro exige el código de un administrador en el
        // kiosko. `first_only` permitiría el primer registro desde el portal,
        // pero con el acceso por documento sin segundo factor cualquiera que
        // sepa una cédula registraría SU cara en la ficha de otro (revisión de
        // seguridad 2026-10-11). En la app publicada esa plantilla solo la usa
        // el login facial, apagado en producción.
        'self_enrollment' => env('WORKFORCE_LEGACY_SELF_ENROLLMENT', 'deny'),
    ],

    'consent' => [
        'version' => '2026-10-v1',
        'title' => 'Autorización para el tratamiento de tu dato biométrico',
        'text' => 'Iron Body usará una representación numérica de tu rostro (no la foto) únicamente para '
            .'verificar tu identidad al registrar tu jornada laboral y al operar clases. La foto no se guarda: '
            .'en el equipo solo se calcula esa representación y se envía cifrada. El dato biométrico es un dato '
            .'sensible (Ley 1581 de 2012): tu autorización es voluntaria y puedes revocarla en cualquier momento '
            .'ante la administración, que borrará la plantilla; en ese caso tu jornada se registrará con un código '
            .'autorizado por un administrador. Se conserva únicamente la traza de quién, cuándo y desde qué '
            .'dispositivo se registró o revocó.',
    ],
];
