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

    // Cerebro comercial IA (Fase 2). Por defecto usa un responder DETERMINISTA
    // (reglas, sin OpenAI). Cuando exista infraestructura segura se podrá
    // cambiar el driver SIN tocar el orquestador. La IA solo DECIDE y registra;
    // las acciones reales siguen protegidas por flags/guardrails.
    'ai' => [
        // fake (reglas locales) | openai (futuro, requiere config segura).
        'driver'  => env('MARKETING_AI_DRIVER', 'fake'),
        // Interruptor del cerebro; con false el orquestador devuelve unknown.
        'enabled' => filter_var(env('MARKETING_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Retrasos de seguimiento por temperatura (minutos). Usados al programar
        // marketing_followups; nada se envía solo si los flags de envío están off.
        'followup_delays' => [
            'very_hot' => (int) env('MARKETING_AI_FOLLOWUP_VERY_HOT', 60),
            'hot'      => (int) env('MARKETING_AI_FOLLOWUP_HOT', 120),
            'warm'     => (int) env('MARKETING_AI_FOLLOWUP_WARM', 360),
        ],
    ],
];
