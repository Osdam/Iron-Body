<?php

/*
|--------------------------------------------------------------------------
| IRON BODY — Motor comercial (oportunidades, NBA/NBO, segmentos)
|--------------------------------------------------------------------------
| El principio que sostiene el módulo: ninguna venta termina la relación
| comercial. Cuando alguien paga, el sistema calcula el siguiente objetivo en
| lugar de olvidarse.
|
| TODO nace apagado. Con los flags en false el motor puede calcular y registrar
| oportunidades —lo cual es inofensivo y sirve para validar las decisiones antes
| de confiar en ellas— pero no ejecuta nada hacia el cliente.
*/

return [

    // Interruptor general. Con false el motor no evalúa ni crea oportunidades.
    'enabled' => filter_var(env('COMMERCIAL_NBA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Autonomía: que el agente EJECUTE la acción decidida sin que un humano la
    // apruebe. Independiente del anterior a propósito: durante semanas se puede
    // querer que el motor decida y que una persona revise lo que habría hecho.
    'autonomy_enabled' => filter_var(env('COMMERCIAL_AUTONOMY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Interruptores por familia de acción, para poder encender por partes.
    'features' => [
        'followups'   => filter_var(env('COMMERCIAL_FOLLOWUPS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'payments'    => filter_var(env('COMMERCIAL_PAYMENTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'memberships' => filter_var(env('COMMERCIAL_MEMBERSHIPS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'invoicing'   => filter_var(env('COMMERCIAL_INVOICING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'app_linking' => filter_var(env('COMMERCIAL_APP_LINKING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'upsell'      => filter_var(env('COMMERCIAL_UPSELL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'reactivation' => filter_var(env('COMMERCIAL_REACTIVATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'referrals'   => filter_var(env('COMMERCIAL_REFERRALS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Umbrales de las reglas. Están aquí y no dispersos por el código porque son
    | decisiones de NEGOCIO que el dueño del gimnasio puede querer ajustar sin
    | que nadie toque una línea de PHP.
    */
    'thresholds' => [
        // Visitas por semana a partir de las cuales alguien "usa" el gimnasio y
        // tiene sentido hablarle de un plan más largo.
        'engaged_weekly_rate' => (float) env('COMMERCIAL_ENGAGED_RATE', 2.5),
        // A partir de aquí es un cliente ejemplar: candidato a referidos.
        'advocate_weekly_rate' => (float) env('COMMERCIAL_ADVOCATE_RATE', 3.0),
        // Días sin venir que encienden la alarma de abandono.
        'at_risk_days' => (int) env('COMMERCIAL_AT_RISK_DAYS', 14),
        'inactive_days' => (int) env('COMMERCIAL_INACTIVE_DAYS', 30),
        // Ventana de renovación antes del vencimiento.
        'renewal_window_days' => (int) env('COMMERCIAL_RENEWAL_WINDOW_DAYS', 10),
        // Días mínimos como miembro antes de proponer cualquier mejora: sin
        // esto se le vendería un anual a alguien que aún no ha pisado el gimnasio.
        'min_days_before_upsell' => (int) env('COMMERCIAL_MIN_DAYS_UPSELL', 21),
        // Horas de cortesía antes de recordar un enlace de pago sin usar.
        'payment_link_grace_hours' => (int) env('COMMERCIAL_PAYMENT_GRACE_HOURS', 6),
        // Pasado este plazo, un mensaje de reactivación es spam.
        'reactivation_max_days' => (int) env('COMMERCIAL_REACTIVATION_MAX_DAYS', 365),
    ],

    /*
    | Límites de contacto. Existen para que el motor no se convierta en una
    | máquina de molestar: es lo que separa un seguimiento de un acoso.
    */
    'contact_limits' => [
        // Máximo de mensajes proactivos por persona y semana, sumando todas las
        // oportunidades abiertas.
        'max_proactive_per_week' => (int) env('COMMERCIAL_MAX_PROACTIVE_WEEK', 2),
        // Horas mínimas entre dos mensajes proactivos.
        'min_hours_between' => (int) env('COMMERCIAL_MIN_HOURS_BETWEEN', 48),
        // Franja horaria local (Neiva) en la que se permite escribir.
        'quiet_hours_start' => (int) env('COMMERCIAL_QUIET_START', 21),
        'quiet_hours_end' => (int) env('COMMERCIAL_QUIET_END', 8),
        'timezone' => env('COMMERCIAL_TIMEZONE', 'America/Bogota'),
    ],

    // Cuántas oportunidades evalúa una corrida del motor (anti-avalancha).
    'evaluation_batch' => (int) env('COMMERCIAL_EVALUATION_BATCH', 100),
];
