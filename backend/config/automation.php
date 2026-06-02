<?php

return [

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
        'card', 'card_number', 'cvv', 'cvc', 'pan', 'epayco',
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
