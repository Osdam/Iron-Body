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
|
| Multimodal (voz/imagen/archivos/realtime) — campos por capacidad:
|   - ai_chat_enabled             (bool)  chat de texto.
|   - ai_voice_chat_enabled       (bool)  chat por voz (audio → transcripción).
|   - ai_image_analysis_enabled   (bool)  análisis de imágenes (visión).
|   - ai_realtime_voice_enabled   (bool)  conversación de voz en vivo (por turnos
|                                         hoy; arquitectura lista para streaming).
|   - ai_file_upload_enabled      (bool)  adjuntar archivos genéricos.
|   - ai_audio_monthly_limit      (?int)  audios/mes (null = sin límite propio).
|   - ai_image_monthly_limit      (?int)  imágenes/mes (null = sin límite propio).
|   - ai_max_audio_seconds        (int)   duración máx. por audio.
|   - ai_max_image_size_mb        (int)   tamaño máx. por imagen.
*/

return [

    // Estado del caller cuando NO tiene membresía activa: prueba gratuita.
    // Solo texto (5 mensajes). Voz, imagen y realtime bloqueados.
    'free_trial' => [
        'ai_enabled'                     => true,
        'ai_chat_enabled'                => true,
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
        // Multimodal — todo bloqueado en prueba gratuita.
        'ai_voice_chat_enabled'          => false,
        'ai_realtime_voice_enabled'      => false,
        'ai_image_analysis_enabled'      => false,
        'ai_file_upload_enabled'         => false,
        'ai_audio_monthly_limit'         => 0,
        'ai_image_monthly_limit'         => 0,
        'ai_max_audio_seconds'           => 60,
        'ai_max_image_size_mb'           => 5,
    ],

    // Membresía activa cuyo plan no se puede mapear a un tier conocido.
    'default_membership' => [
        'ai_enabled'                     => true,
        'ai_chat_enabled'                => true,
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
        // Multimodal — conservador: solo texto.
        'ai_voice_chat_enabled'          => false,
        'ai_realtime_voice_enabled'      => false,
        'ai_image_analysis_enabled'      => false,
        'ai_file_upload_enabled'         => false,
        'ai_audio_monthly_limit'         => 0,
        'ai_image_monthly_limit'         => 0,
        'ai_max_audio_seconds'           => 60,
        'ai_max_image_size_mb'           => 5,
    ],

    // Tiers tentativos (las capacidades oficiales aún no están definidas).
    // Se mapean por palabras clave en el nombre/código del plan existente.
    'tiers' => [
        'basic' => [
            'ai_enabled'                     => true,
            'ai_chat_enabled'                => true,
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
            // Multimodal — básico: solo texto.
            'ai_voice_chat_enabled'          => false,
            'ai_realtime_voice_enabled'      => false,
            'ai_image_analysis_enabled'      => false,
            'ai_file_upload_enabled'         => false,
            'ai_audio_monthly_limit'         => 0,
            'ai_image_monthly_limit'         => 0,
            'ai_max_audio_seconds'           => 60,
            'ai_max_image_size_mb'           => 5,
        ],
        'intermediate' => [
            'ai_enabled'                     => true,
            'ai_chat_enabled'                => true,
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
            // Multimodal — voz habilitada (10/mes); IMAGEN y REALTIME bloqueados.
            'ai_voice_chat_enabled'          => true,
            'ai_realtime_voice_enabled'      => false,
            'ai_image_analysis_enabled'      => false,
            'ai_file_upload_enabled'         => false,
            'ai_audio_monthly_limit'         => 10,
            'ai_image_monthly_limit'         => 0,
            'ai_max_audio_seconds'           => 60,
            'ai_max_image_size_mb'           => 5,
        ],
        'premium' => [
            'ai_enabled'                     => true,
            'ai_chat_enabled'                => true,
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
            // Multimodal — completo (cubre premium/pro/elite/vip) + voz en vivo.
            'ai_voice_chat_enabled'          => true,
            'ai_realtime_voice_enabled'      => true,
            'ai_image_analysis_enabled'      => true,
            'ai_file_upload_enabled'         => true,
            'ai_audio_monthly_limit'         => 50,
            'ai_image_monthly_limit'         => 30,
            'ai_max_audio_seconds'           => 90,
            'ai_max_image_size_mb'           => 8,
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

    // Defaults globales para multimedia (techos/formatos). La cuota/duración
    // efectiva se toma de la capacidad del plan; estos valores son el respaldo
    // y la lista de formatos aceptados. Discos: imagen en `public` (preview
    // URL), audio en `local` (privado, no se expone).
    'media' => [
        'max_audio_seconds' => (int) env('IRON_AI_MAX_AUDIO_SECONDS', 60),
        'max_image_size_mb' => (int) env('IRON_AI_MAX_IMAGE_SIZE_MB', 5),
        'max_audio_size_mb' => (int) env('IRON_AI_MAX_AUDIO_SIZE_MB', 25),
        'audio_mimes'       => ['m4a', 'mp3', 'wav', 'webm', 'aac', 'ogg', 'mp4', 'mpeg', 'mpga', 'x-m4a'],
        'audio_exts'        => ['m4a', 'mp3', 'wav', 'webm', 'aac', 'ogg', 'mp4'],
        'image_mimes'       => ['jpg', 'jpeg', 'png', 'webp'],
        'image_exts'        => ['jpg', 'jpeg', 'png', 'webp'],
        'audio_disk'        => env('IRON_AI_AUDIO_DISK', 'local'),
        'image_disk'        => env('IRON_AI_IMAGE_DISK', 'public'),
    ],

    // Estimación de costo (USD por 1M tokens). Solo para iron_ai_usage_logs;
    // ajustable. null en cualquiera → estimated_cost = null.
    'pricing' => [
        'input_per_million'  => env('IRON_AI_PRICE_INPUT_PER_M', 0.40),
        'output_per_million' => env('IRON_AI_PRICE_OUTPUT_PER_M', 1.60),
    ],
];
