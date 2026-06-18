<?php

/*
|--------------------------------------------------------------------------
| Iron Body Proactive Coach — capa de comportamiento (Fase 2)
|--------------------------------------------------------------------------
| Eventos proactivos premium que acompañan al usuario FUERA de la app.
| Esta capa NO reemplaza al sistema base (nutrition.missing, workout.missed,
| etc.); lo extiende. Todos los detectores nuevos quedan INERTES hasta que
| `enabled = true`, para activación gradual sin romper producción.
|
| Rollback: poner PROACTIVE_COACH_ENABLED=false (o quitar la env) desactiva
| TODOS los detectores nuevos del scheduler sin tocar código ni el flujo base.
*/

return [

    // Interruptor maestro de los detectores nuevos en el scheduler. Si false,
    // el scheduler NO corre los detectores de Fase 2 (los comandos siguen
    // ejecutables a mano con --dry-run para pruebas controladas).
    'enabled' => filter_var(env('PROACTIVE_COACH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Zona horaria de negocio para "hoy"/"esta semana".
    'timezone' => env('PROACTIVE_COACH_TZ', 'America/Bogota'),

    /*
    | Presupuesto anti-spam por miembro/día (capa adicional al gate final de
    | AppNotificationService, que ya limita 12h/tipo, 1/tipo/día y 3 totales/día).
    | Aquí se decide ANTES de emitir, para no saturar:
    |   - max_strong_per_day: notificaciones "fuertes" (urgencia positiva).
    |   - max_total_per_day:  total de proactivas/día (fuertes + suaves).
    | Se cuentan los automation_events proactivos creados hoy para el miembro.
    */
    'budget' => [
        'max_strong_per_day' => (int) env('PROACTIVE_COACH_MAX_STRONG', 1),
        'max_total_per_day'  => (int) env('PROACTIVE_COACH_MAX_TOTAL', 2),
    ],

    // Ventana horaria permitida (hora local de negocio). Fuera de ella los
    // detectores NO emiten (evita madrugada / horarios sensibles). El scheduler
    // ya corre en horas concretas; esto es una defensa extra por si se corre a mano.
    'quiet_hours' => [
        'start_hour' => (int) env('PROACTIVE_COACH_QUIET_START', 21), // 21:00
        'end_hour'   => (int) env('PROACTIVE_COACH_QUIET_END', 8),    // 08:00
    ],

    /*
    | Umbrales (días) de los detectores. Centralizados para ajustar sin tocar
    | código. Todos son conservadores por defecto.
    */
    'thresholds' => [
        'chat_invite_idle_days'        => (int) env('PROACTIVE_CHAT_INVITE_IDLE_DAYS', 14),
        'nutrition_invite_idle_days'   => (int) env('PROACTIVE_NUTRITION_INVITE_IDLE_DAYS', 10),
        'progress_invite_idle_days'    => (int) env('PROACTIVE_PROGRESS_INVITE_IDLE_DAYS', 21),
        'reactivation_idle_days'       => (int) env('PROACTIVE_REACTIVATION_IDLE_DAYS', 7),
        'reactivation_max_idle_days'   => (int) env('PROACTIVE_REACTIVATION_MAX_IDLE_DAYS', 45),
    ],

    /*
    | module.discovery — PREPARADO PERO INACTIVO.
    | Requiere la tabla `app_module_usages` poblada por instrumentación en
    | Flutter (Fase 3). Mientras `discovery_enabled = false` el detector se salta
    | siempre (no inventa uso). NO activar hasta tener datos reales.
    */
    'discovery_enabled' => filter_var(env('PROACTIVE_COACH_DISCOVERY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
