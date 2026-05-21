<?php

/*
|--------------------------------------------------------------------------
| IRON IA — capacidades por membresía (configurable, NO hardcodeado en Flutter)
|--------------------------------------------------------------------------
| Estas son las plantillas base usadas por:
|   - el comando `iron-ai:sync-membership-capabilities` (siembra/actualiza la
|     tabla `membership_ai_capabilities` a partir de los planes existentes), y
|   - `IronAiMembershipAccessService` como fallback si una fila no existe.
|
| Las CAPACIDADES viven aquí y en la tabla auxiliar `membership_ai_capabilities`
| (asociada a los planes existentes). NO se crea un sistema de planes paralelo:
| los precios/planes comerciales siguen en la tabla `plans` del módulo actual.
| Flutter solo muestra lo que devuelve el backend.
*/

return [

    // Estado del caller cuando NO tiene membresía activa: prueba gratuita.
    'free_trial' => [
        'ai_enabled'                     => true,
        'free_trial_messages'            => 5,
        'monthly_messages_limit'         => 5,
        'daily_messages_limit'           => 5,
        'max_output_tokens'              => 500,
        'context_level'                  => 'basic',
        'progress_analysis_enabled'      => false,
        'smart_recommendations_enabled'  => false,
        'weekly_summary_enabled'         => false,
        'proactive_notifications_enabled'=> false,
        'fair_use_limit'                 => null,
    ],

    // Membresía activa cuyo plan no se puede mapear a un tier conocido.
    'default_membership' => [
        'ai_enabled'                     => true,
        'free_trial_messages'            => 0,
        'monthly_messages_limit'         => 20,
        'daily_messages_limit'           => 5,
        'max_output_tokens'              => 700,
        'context_level'                  => 'basic',
        'progress_analysis_enabled'      => false,
        'smart_recommendations_enabled'  => false,
        'weekly_summary_enabled'         => false,
        'proactive_notifications_enabled'=> false,
        'fair_use_limit'                 => null,
    ],

    // Tiers tentativos (las capacidades oficiales aún no están definidas).
    // Se mapean por palabras clave en el nombre/código del plan existente.
    'tiers' => [
        'basic' => [
            'ai_enabled'                     => true,
            'free_trial_messages'            => 0,
            'monthly_messages_limit'         => 20,
            'daily_messages_limit'           => 5,
            'max_output_tokens'              => 700,
            'context_level'                  => 'basic',
            'progress_analysis_enabled'      => false,
            'smart_recommendations_enabled'  => false,
            'weekly_summary_enabled'         => false,
            'proactive_notifications_enabled'=> false,
            'fair_use_limit'                 => null,
        ],
        'intermediate' => [
            'ai_enabled'                     => true,
            'free_trial_messages'            => 0,
            'monthly_messages_limit'         => 50,
            'daily_messages_limit'           => 10,
            'max_output_tokens'              => 900,
            'context_level'                  => 'personalized',
            'progress_analysis_enabled'      => true,
            'smart_recommendations_enabled'  => true,
            'weekly_summary_enabled'         => false,
            'proactive_notifications_enabled'=> false,
            'fair_use_limit'                 => null,
        ],
        'premium' => [
            'ai_enabled'                     => true,
            'free_trial_messages'            => 0,
            'monthly_messages_limit'         => 150,
            'daily_messages_limit'           => 25,
            'max_output_tokens'              => 1200,
            'context_level'                  => 'full',
            'progress_analysis_enabled'      => true,
            'smart_recommendations_enabled'  => true,
            'weekly_summary_enabled'         => true,
            'proactive_notifications_enabled'=> true,
            'fair_use_limit'                 => null,
        ],
    ],

    // Palabras clave (sin acentos, en minúscula) para inferir el tier a partir
    // del nombre/código del plan existente. Se evalúa de premium → basic
    // (el primero que coincide gana; p. ej. "VIP Mensual" → premium por "vip").
    //
    // Regla comercial:
    //  - premium/full: SOLO planes superiores reales (premium, pro, elite, vip,
    //    full, total, avanzado). NO se incluyen duraciones (anual) ni colores.
    //  - intermedio: mensual/monthly/mes, standard, intermedio, plus, etc.
    //  - basic: básico/inicial/starter.
    'tier_keywords' => [
        'premium'      => ['premium', 'pro', 'elite', 'vip', 'full', 'total', 'avanzado'],
        'intermediate' => ['intermedio', 'intermediate', 'plus', 'standard', 'estandar', 'mensual', 'monthly', 'mes', 'trimestral', 'semestral'],
        'basic'        => ['basico', 'basic', 'inicial', 'starter'],
    ],

    // Estimación de costo (USD por 1M tokens). Solo para iron_ai_usage_logs;
    // ajustable. null en cualquiera → estimated_cost = null.
    'pricing' => [
        'input_per_million'  => env('IRON_AI_PRICE_INPUT_PER_M', 0.40),
        'output_per_million' => env('IRON_AI_PRICE_OUTPUT_PER_M', 1.60),
    ],
];
