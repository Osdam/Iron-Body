<?php

/*
|--------------------------------------------------------------------------
| IRON BODY — Observabilidad del canal de WhatsApp e IRON GUARD
|--------------------------------------------------------------------------
| El VPS tiene 4 vCPU y 16 GB, pero ya sostiene Postgres, PHP-FPM, nginx, n8n y
| (pronto) Hermes. Meter Prometheus + Grafana + Loki + Alertmanager para un solo
| gimnasio sería pagar RAM y mantenimiento por un tablero que nadie mira. Se
| eligió la vía ligera: logs JSON por línea + tablas de incidentes en la misma
| PostgreSQL + un panel dentro del CRM. Si algún día el volumen lo justifica,
| los logs JSON ya están en el formato que Loki ingiere sin cambios.
|
| Todo lo automático nace APAGADO.
*/

return [

    // Interruptor general de IRON GUARD. Con false no se detectan ni se crean
    // incidentes: solo quedan los logs estructurados (que son inofensivos).
    'enabled' => filter_var(env('IRON_GUARD_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Commit desplegado. Si se deja vacío se lee de .git/HEAD (en producción el
    // deploy puede fijarlo explícitamente para no depender del working tree).
    'release' => env('IRON_GUARD_RELEASE'),

    'incidents' => [
        // Ventana en la que dos errores iguales se consideran EL MISMO incidente
        // en vez de dos. Evita 400 incidentes por un worker caído una hora.
        'grouping_window_minutes' => (int) env('IRON_GUARD_GROUPING_MINUTES', 60),
        // Ocurrencias dentro de la ventana antes de escalar la severidad.
        'escalate_after_occurrences' => (int) env('IRON_GUARD_ESCALATE_AFTER', 10),
        // Retención de incidentes ya cerrados.
        'retention_days' => (int) env('IRON_GUARD_RETENTION_DAYS', 90),
        // Máximo de incidentes creados por corrida del detector (anti-avalancha).
        'max_per_run' => (int) env('IRON_GUARD_MAX_PER_RUN', 50),
    ],

    // Análisis de causa raíz con IA. NO se manda cada log a un modelo: primero
    // detección determinista y agrupamiento, y solo después —si el incidente
    // supera el umbral y hay presupuesto— se pide una hipótesis.
    'ai_analysis' => [
        'enabled' => filter_var(env('IRON_GUARD_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Severidad mínima para gastar tokens.
        'min_severity' => env('IRON_GUARD_AI_MIN_SEVERITY', 'high'),
        // Techo diario de análisis. Un bucle de errores no puede vaciar la cuenta.
        'daily_budget' => (int) env('IRON_GUARD_AI_DAILY_BUDGET', 20),
        'max_evidence_lines' => (int) env('IRON_GUARD_AI_MAX_EVIDENCE', 40),
        'timeout' => (int) env('IRON_GUARD_AI_TIMEOUT', 45),
    ],

    /*
    | Vigilancia de los carriles de cola.
    |
    | `unattended_after_seconds` es cuánto se tolera que un trabajo espere sin
    | que nadie de ese carril termine nada antes de considerar que no hay worker.
    | Dos minutos es holgado a propósito: un despliegue reinicia los procesos y
    | no queremos un incidente en cada despliegue.
    */
    'queues' => [
        'unattended_after_seconds' => (int) env('QUEUE_UNATTENDED_AFTER_SECONDS', 120),
    ],

    /*
    | Respaldos. La aplicación no los hace —eso es systemd— pero sí los VIGILA:
    | un respaldo que lleva una semana fallando en silencio es indistinguible de
    | no tener respaldos, y esa es justo la situación que había antes de F.13.
    |
    | Los scripts dejan su resultado en estos ficheros, sin secretos dentro.
    */
    'backups' => [
        // Solo el servidor que TIENE los timers puede opinar sobre sus respaldos.
        // En desarrollo y en la suite no hay respaldos que vigilar, y un detector
        // que alarma ahí enseña a ignorar la alarma. Se enciende explícitamente
        // en producción y no por omisión.
        'monitoring_enabled' => filter_var(env('BACKUP_MONITORING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'status_file' => env('BACKUP_STATUS_FILE', '/var/lib/ironbody/backup-status.json'),
        'restore_status_file' => env('RESTORE_STATUS_FILE', '/var/lib/ironbody/restore-test-status.json'),
        // Diario a las 03:15: a las 30 h ya se saltó una ejecución entera.
        'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 30),
        // La verificación es semanal; 10 días da margen a un lunes festivo.
        'restore_stale_after_days' => (int) env('RESTORE_STALE_AFTER_DAYS', 10),
    ],

    // Remediación automática. Doble llave: el flag general Y la allowlist.
    // Nada que no esté nombrado aquí puede ejecutarse solo, jamás.
    'remediation' => [
        'enabled' => filter_var(env('IRON_GUARD_AUTO_REMEDIATION', false), FILTER_VALIDATE_BOOLEAN),
        // Acciones reversibles e idempotentes. Reiniciar Postgres, migrar,
        // borrar datos, desplegar o tocar Meta NO están y no deben estarlo.
        'allowlist' => [
            'retry_failed_job',        // reencolar un job idempotente concreto
            'replay_webhook_event',    // reprocesar un evento crudo ya guardado
            'retry_media_download',    // reintentar la descarga de un adjunto
            'clear_config_cache',      // php artisan config:clear (reversible)
        ],
        // Veces que una misma acción puede auto-ejecutarse por incidente antes
        // de exigir a un humano. Evita el bucle "reintenta lo que nunca va a ir".
        'max_attempts_per_incident' => (int) env('IRON_GUARD_MAX_REMEDIATION_ATTEMPTS', 3),
    ],

    // Retención del registro crudo de webhooks: contiene texto de prospectos.
    'raw_events' => [
        'retention_days' => (int) env('META_RAW_EVENT_RETENTION_DAYS', 30),
        // Minutos sin procesarse tras los cuales un evento se considera atascado.
        'stuck_after_minutes' => (int) env('META_EVENT_STUCK_MINUTES', 10),
    ],
];
