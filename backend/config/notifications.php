<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana horaria dura
    |--------------------------------------------------------------------------
    | Fuera de esta franja NO sale ninguna notificación discrecional, pase lo
    | que pase: da igual que el servidor corra en UTC, que n8n se dispare por
    | error o que el socio tenga las horas de silencio apagadas.
    |
    | Es la última barrera, no la primera. Las preferencias del socio y sus
    | horas de silencio siguen mandando DENTRO de la ventana; esto solo pone un
    | techo que nadie puede levantar por configuración.
    |
    | Semántica del límite: se permite desde `start` inclusive hasta `end`
    | EXCLUSIVE. Con 7 y 22, las 21:59 pasan y las 22:00 en punto ya no.
    |
    | Lo único exento es la seguridad de la cuenta: si alguien entra en tu
    | cuenta a las tres de la mañana, enterarte a las siete no sirve de nada.
    */

    'window' => [
        'timezone' => env('NOTIFICATIONS_TIMEZONE', 'America/Bogota'),
        'start_hour' => (int) env('NOTIFICATIONS_WINDOW_START', 7),
        'end_hour' => (int) env('NOTIFICATIONS_WINDOW_END', 22),
    ],

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

        // Quién decide la HORA de envío: 'laravel' (el cron del servidor) o
        // 'n8n' (que llama a /api/internal/automation/wellness-run).
        //
        // Debe haber un solo dueño del horario. Con 'n8n', Laravel deja de
        // agendarlo para que no haya dos relojes compitiendo — aunque si por
        // error corrieran los dos, la llave de idempotencia diaria impide que
        // nadie reciba dos avisos.
        //
        // Se elige 'laravel' por defecto a propósito: es el camino que no
        // depende de que un contenedor externo esté vivo.
        'orchestrator' => env('NOTIFICATIONS_WELLNESS_ORCHESTRATOR', 'laravel'),

        // Horas a las que n8n dispara cada franja, en hora de Bogotá. La lista
        // la manda `App\Support\Notifications\NotificationSlot`; esto es su
        // reflejo para quien lea la configuración y para documentar el cron.
        //
        // La última va a las 21:45 y no a las 22:00 porque el cierre duro es a
        // las 22:00: disparar en ese minuto dejaría el envío a merced de
        // cualquier retraso de red.
        'runs_at' => ['07:00', '11:00', '15:00', '19:00', '21:45'],

        // Minutos que deben pasar como mínimo entre dos avisos de bienestar al
        // mismo socio. Es la red para reintentos, ejecuciones solapadas o un
        // cron duplicado: casos en los que la llave de idempotencia no ayuda
        // porque las franjas implicadas son distintas.
        //
        // El valor tiene que quedar POR DEBAJO del hueco legítimo más estrecho,
        // que es el de 19:00 a 21:45 — 165 minutos. Con 150 la franja de cierre
        // se bloqueaba a sí misma en cuanto la de las siete se retrasaba un
        // cuarto de hora; 120 deja margen para ese retraso y sigue atrapando los
        // disparos dobles, que ocurren con segundos o minutos de diferencia.
        'min_interval_minutes' => (int) env('NOTIFICATIONS_WELLNESS_MIN_INTERVAL', 120),
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
