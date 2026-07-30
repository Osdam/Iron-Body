<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notificaciones de bienestar (motivación, hábitos, suplementos)
    |--------------------------------------------------------------------------
    | INERTE por defecto. Mientras `wellness.enabled` sea false no sale ni una
    | sola notificación de estas categorías a socios reales, aunque las
    | plantillas estén sembradas y las preferencias activas.
    |
    | Para activarlo, en .env:
    |     NOTIFICATIONS_WELLNESS_ENABLED=true
    |
    | Rollback: poner el flag en false. No hay que revertir migraciones ni
    | borrar datos; el planificador simplemente deja de agendarse.
    */

    'wellness' => [
        'enabled' => filter_var(env('NOTIFICATIONS_WELLNESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        // El servidor corre en UTC y el gimnasio está en UTC-5. Estas dos
        // pasadas caen a media mañana y media tarde en Neiva, lejos de las
        // horas de silencio por defecto (21:00–07:00 locales). Se hacen dos
        // porque cada socio tiene su propio horario; la idempotencia diaria
        // garantiza que aun así reciba una como mucho.
        'runs_at' => ['15:00', '22:00'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Campañas manuales del CRM
    |--------------------------------------------------------------------------
    | A partir de este número de destinatarios, el CRM exige una confirmación
    | reforzada (escribir el número exacto) antes de dejar enviar.
    */

    'campaigns' => [
        'large_audience_threshold' => (int) env('NOTIFICATIONS_LARGE_AUDIENCE', 50),
    ],
];
