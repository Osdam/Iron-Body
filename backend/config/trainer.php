<?php

use App\Models\TrainerRole;

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags del portal profesional
    |--------------------------------------------------------------------------
    | El backend es la autoridad de las banderas (Flutter solo oculta UI). Con
    | todas en false la app se comporta EXACTAMENTE como hoy: el miembro normal
    | no ve nada del sistema de perfiles. Para un piloto, además de la bandera
    | global, se puede habilitar por identidad incluyendo su id en
    | `pilot_identities` (lista separada por comas en el .env).
    */
    'flags' => [
        'trainer_portal_enabled' => filter_var(env('TRAINER_PORTAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'trainer_auth_enabled' => filter_var(env('TRAINER_AUTH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'professional_assessments_enabled' => filter_var(env('TRAINER_PROFESSIONAL_ASSESSMENTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'trainer_classes_enabled' => filter_var(env('TRAINER_CLASSES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'workspace_switching_enabled' => filter_var(env('TRAINER_WORKSPACE_SWITCHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Identidades habilitadas para el piloto aunque la bandera global esté off.
    'pilot_identities' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRAINER_PILOT_IDENTITIES', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Dispositivo de confianza (acceso profesional sin repetir OTP)
    |--------------------------------------------------------------------------
    | Tras el PRIMER acceso por OTP en un equipo, ese dispositivo queda marcado
    | como de confianza (`trainer_device_sessions.trusted_at`). Mientras siga
    | dentro de la ventana y la sesión no haya sido revocada, los accesos
    | siguientes (incluso después de cerrar sesión) entran SIN OTP y sin gastar
    | SMS. El `device_id` es un UUID por instalación (no adivinable); la confianza
    | se rompe al desactivar al entrenador (revokeAll) o al expirar la ventana.
    | Con `enabled=false` se exige OTP siempre.
    */
    'trusted_device' => [
        'enabled'  => filter_var(env('TRAINER_TRUSTED_DEVICE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ttl_days' => (int) env('TRAINER_TRUSTED_DEVICE_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de permisos por rol (autoridad central)
    |--------------------------------------------------------------------------
    | Fuente única de la verdad para qué puede hacer cada rol profesional. Un
    | entrenador con varios roles obtiene la UNIÓN de sus permisos. Nunca se debe
    | comprobar el rol de forma dispersa (role == 'trainer'): siempre vía
    | Trainer::hasPermission() / el middleware `trainer.can`.
    */
    'permissions' => [
        TrainerRole::FLOOR => [
            'trainer.portal.access',
            'members.view_assigned',
            'members.search',
            'members.view',
            'assessments.create',
            'assessments.update_draft',
            'assessments.submit',
            'assessments.amend',
            'assessments.view',
            'routines.assign',
            'trainer.workspace.switch',
        ],
        TrainerRole::FUNCTIONAL => [
            'trainer.portal.access',
            'assessments.create',
            'assessments.update_draft',
            'assessments.submit',
            'assessments.amend',
            'assessments.view',
            'classes.view',
            'classes.manage',
            'attendance.create',
            'attendance.update',
            'trainer.workspace.switch',
        ],
    ],

];
