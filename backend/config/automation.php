<?php

return [

    /*
     * Topes de las dos puertas internas, cada una con su propio cubo.
     *
     * Van separadas porque compartirlo costó tres mensajes de WhatsApp sin
     * respuesta: la avalancha diaria de notificaciones vació el cubo y el
     * asesor se encontró un 429 al pedir su contexto. Subirlos no arregla
     * aquello —la avalancha se espacia donde nace—; separarlos sí impide que
     * el gasto de una puerta se cobre en el presupuesto de la otra.
     */
    /*
     * Cadencia de salida hacia n8n. Va por debajo del tope de la puerta a
     * propósito: el tope es el techo y esto es la velocidad de crucero.
     */
    'dispatch_per_minute' => (int) env('AUTOMATION_DISPATCH_PER_MINUTE', 240),

    // Techo de espera: nadie paga la avalancha de otro más de esto.
    'dispatch_max_delay_seconds' => (int) env('AUTOMATION_DISPATCH_MAX_DELAY_SECONDS', 600),

    'rate_limits' => [
        // Notificaciones a socios. Ráfagas legítimas, pero acotadas.
        'automation' => (int) env('AUTOMATION_RATE_LIMIT_PER_MINUTE', 600),

        // El asesor. Bajo volumen por naturaleza: un turno son dos llamadas.
        'marketing_ai' => (int) env('MARKETING_AI_RATE_LIMIT_PER_MINUTE', 120),
    ],


    /*
    |--------------------------------------------------------------------------
    | Automatización Laravel → n8n
    |--------------------------------------------------------------------------
    | Laravel emite eventos mínimos y seguros a n8n para automatizaciones
    | externas (recordatorios, resúmenes, tareas CRM). n8n NUNCA accede a
    | PostgreSQL ni recibe datos sensibles: solo el webhook con payload saneado.
    */

    // Interruptor general. Si false, los eventos quedan 'skipped' (no se envían).
    'enabled' => filter_var(env('N8N_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // URL del webhook de n8n que recibe los eventos.
    'webhook_url' => env('N8N_WEBHOOK_URL'),

    // Secreto compartido: bearer + clave HMAC de la firma del payload.
    'webhook_secret' => env('N8N_WEBHOOK_SECRET'),

    // Timeout corto del POST (segundos).
    'timeout' => (int) env('N8N_TIMEOUT', 10),

    // Secreto para endpoints internos que dispara n8n (HMAC + bearer). n8n los
    // llama firmados; nunca son públicos sin firma.
    'internal_secret' => env('AUTOMATION_INTERNAL_SECRET'),

    /*
    | Claves que JAMÁS deben salir hacia n8n (defensa en profundidad: el
    | sanitizador del servicio las elimina recursivamente del payload).
    */
    'forbidden_keys' => [
        'password', 'access_hash', 'token', 'session_token', 'secret',
        'document', 'document_number', 'document_image', 'identity_document',
        'biometric', 'face_hash', 'face_reference', 'facial',
        'signature', 'contract', 'legal_consent',
        'card', 'card_number', 'cvv', 'cvc', 'pan', 'wompi',
        'api_key', 'private_key', 'authorization',
    ],

    /*
    | Eventos preparados/documentados (no todos conectados aún).
    */
    'event_types' => [
        'system.test',
        'member.registered',
        'member.registration_abandoned',
        'member.minor_detected',
        'contract.signed',
        'payment.approved',
        'payment.rejected',
        'membership.expiring',
        'nutrition.missing',
        'workout.missed',
        'streak.completed',
        'evaluation.created',
        'progress.updated',
        'iron_ai.weekly_summary_ready',
    ],
];
