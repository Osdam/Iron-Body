<?php

/*
|--------------------------------------------------------------------------
| IRON BODY — Agente Comercial IA (módulo Mercadeo / marketing_*)
|--------------------------------------------------------------------------
| Configuración de los cimientos del agente comercial (Fase 0/1). Todo es
| ADITIVO y SEGURO: con `agent_enabled=false` (default) los endpoints y el
| comando de seguimientos NO ejecutan acciones externas reales (no envían
| WhatsApp/IG/FB ni inician llamadas). Generar un link de pago NUNCA activa una
| membresía: la activación sigue siendo exclusiva del webhook Wompi aprobado /
| reconciliación / PaymentMembershipActivator.
*/

return [

    // Interruptor general del agente comercial. Si false, los disparadores
    // externos quedan inertes (modo seguro). Generar links de pago SÍ está
    // permitido aunque esté en false (solo crea la transacción + URL; no
    // contacta a Meta ni activa nada), salvo que se decida lo contrario abajo.
    'agent_enabled' => filter_var(env('MARKETING_AGENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Seguimientos automáticos (marketing:dispatch-followups).
    'followups' => [
        // Si false, el comando recorre y registra, pero NO envía mensajes ni
        // programa llamadas reales (preparado para fases siguientes).
        // Hoy, además, el envío real depende de agent_enabled + META_ENABLED.
        'dispatch_enabled'   => filter_var(env('MARKETING_FOLLOWUPS_DISPATCH', false), FILTER_VALIDATE_BOOLEAN),
        // Agendar el comando en el scheduler. INERTE por defecto: se activa por
        // env sin tocar código (igual que el patrón proactive_coach).
        'scheduler_enabled'  => filter_var(env('MARKETING_FOLLOWUPS_SCHEDULER', false), FILTER_VALIDATE_BOOLEAN),
        // Cada cuántos minutos corre (5–10 recomendado).
        'scheduler_minutes'  => (int) env('MARKETING_FOLLOWUPS_MINUTES', 10),
        // Máximo de seguimientos vencidos a procesar por corrida (anti-avalancha).
        'batch_limit'        => (int) env('MARKETING_FOLLOWUPS_BATCH', 100),
    ],

    // Link de pago por WhatsApp/Meta (Fase 1). El monto es SIEMPRE autoritativo
    // del backend (Plan::price); el cliente/n8n nunca define el precio.
    'payment_links' => [
        // Origen que se sella en metadata.source de la PaymentTransaction.
        'source' => 'marketing_agent',
    ],

    // Mensajes ENTRANTES de Meta/WhatsApp (Fase 4-A). Defaults SEGUROS:
    //  - analizar (cerebro) SÍ puede estar habilitado,
    //  - EJECUTAR herramientas reales queda en false (y además exige
    //    agent_enabled), y el envío real sigue bloqueado por META_ENABLED.
    'inbound' => [
        // Permite el procesamiento de entrantes; si false, el webhook solo
        // registra (no analiza). Derivado/independiente de META_ENABLED.
        'meta_enabled'  => filter_var(env('MARKETING_INBOUND_META_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // ¿Enrutar el texto entrante al cerebro (analyze)?
        'auto_analyze'  => filter_var(env('MARKETING_INBOUND_AUTO_ANALYZE', true), FILTER_VALIDATE_BOOLEAN),
        // ¿Ejecutar herramientas (link/followup/takeover) de forma automática?
        // Falso por defecto; además requiere marketing.agent_enabled=true.
        'auto_execute'  => filter_var(env('MARKETING_INBOUND_AUTO_EXECUTE', false), FILTER_VALIDATE_BOOLEAN),
        // Guardar el evento crudo de Meta en metadata (debug). Off por defecto.
        'store_raw_payload' => filter_var(env('MARKETING_INBOUND_STORE_RAW_PAYLOAD', false), FILTER_VALIDATE_BOOLEAN),
        // Tipos de mensaje soportados hoy (solo texto). El resto se escala.
        'supported_message_types' => ['text'],
    ],

    // Cerebro comercial IA (Fase 2). Por defecto usa un responder DETERMINISTA
    // (reglas, sin OpenAI). Cuando exista infraestructura segura se podrá
    // cambiar el driver SIN tocar el orquestador. La IA solo DECIDE y registra;
    // las acciones reales siguen protegidas por flags/guardrails.
    'ai' => [
        // fake (reglas locales) | openai (requiere config segura + OPENAI_API_KEY).
        // MARKETING_SALES_AI_DRIVER es el nombre canónico; se conserva el alias
        // MARKETING_AI_DRIVER por retrocompatibilidad con la Fase 2.
        'driver'  => env('MARKETING_SALES_AI_DRIVER', env('MARKETING_AI_DRIVER', 'fake')),
        // Interruptor del cerebro; con false el orquestador devuelve unknown.
        'enabled' => filter_var(env('MARKETING_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Retrasos de seguimiento por temperatura (minutos). Usados al programar
        // marketing_followups; nada se envía solo si los flags de envío están off.
        'followup_delays' => [
            'very_hot' => (int) env('MARKETING_AI_FOLLOWUP_VERY_HOT', 60),
            'hot'      => (int) env('MARKETING_AI_FOLLOWUP_HOT', 120),
            'warm'     => (int) env('MARKETING_AI_FOLLOWUP_WARM', 360),
        ],

        // Cerebro OpenAI (Fase 3). INERTE por defecto: aunque driver=openai, solo
        // se usa si openai.enabled=true Y existe OPENAI_API_KEY (services.openai).
        // La API key NO se duplica aquí: se reutiliza config('services.openai').
        // Laravel SIEMPRE tiene la última palabra (validator + guardrails).
        'openai' => [
            'enabled'           => filter_var(env('MARKETING_OPENAI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // Modelo; por defecto reusa el de IRON IA (services.openai.model).
            'model'             => env('MARKETING_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
            'timeout'           => (int) env('MARKETING_OPENAI_TIMEOUT', 20),
            'max_retries'       => (int) env('MARKETING_OPENAI_MAX_RETRIES', 1),
            'temperature'       => (float) env('MARKETING_OPENAI_TEMPERATURE', 0.2),
            'max_output_tokens' => (int) env('MARKETING_OPENAI_MAX_OUTPUT_TOKENS', 1200),
            // Por seguridad/privacidad, NO se loguean prompts por defecto.
            'log_prompts'       => filter_var(env('MARKETING_OPENAI_LOG_PROMPTS', false), FILTER_VALIDATE_BOOLEAN),
            // true → ante error/JSON inválido se devuelve una decisión SEGURA
            // (unknown). false → cae al responder determinista (fake).
            'fail_closed'       => filter_var(env('MARKETING_OPENAI_FAIL_CLOSED', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
