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

    // Potencias de Iron IA para el plan integral del entrenador
    // (docs/iron-ia/POTENCIAS-IRON-IA.md). Solo afectan a los niveles 2..5; el
    // nivel 1 sigue usando exactamente lo de siempre (services.openai.model,
    // nutrition.ai.timeout_seconds, 3000 tokens).
    'plan_powers' => [
        // El MISMO modelo que el resto de Iron IA salvo que se separe aquí.
        'model' => env('IRON_AI_PLAN_POWERS_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
        // Plazo de la generación ENTERA. La app corta a los 20 s; lo que no
        // quepa aquí falla reintentable en vez de llegar cuando ya nadie mira.
        'deadline_seconds' => (float) env('IRON_AI_PLAN_DEADLINE_SECONDS', 17),
        // No se lanza una reparación si queda menos que esto: no llegaría.
        'min_repair_seconds' => (float) env('IRON_AI_PLAN_MIN_REPAIR_SECONDS', 6),
        'temperature' => (float) env('IRON_AI_PLAN_TEMPERATURE', 0.2),
        // Totales del día frente a la meta (kcal y proteína), escalonado:
        // dentro de la tolerancia, bien; hasta el umbral, se acepta con una
        // nota calculada en PHP; por encima, se repara una vez o falla.
        'daily_target_tolerance' => (float) env('IRON_AI_PLAN_DAILY_TARGET_TOLERANCE', 0.10),
        'daily_target_repair_threshold' => (float) env('IRON_AI_PLAN_DAILY_TARGET_REPAIR_THRESHOLD', 0.25),
        // Reparaciones por generación. Con más secciones malas que esto no se
        // repara nada: una ronda no las arreglaría todas y solo gastaría.
        'max_repairs' => (int) env('IRON_AI_PLAN_MAX_REPAIRS', 6),
        // Balance de porciones en PHP (nivel 5 con meta): cada ingrediente se
        // escala entre estos factores; y tope de proteína por gramo de alimento
        // (salvo los densos conocidos: soya texturizada, seitán, parmesano).
        'balance_min_factor' => (float) env('IRON_AI_PLAN_BALANCE_MIN', 0.6),
        'balance_max_factor' => (float) env('IRON_AI_PLAN_BALANCE_MAX', 1.8),
        'protein_per_gram_max' => (float) env('IRON_AI_PLAN_PROTEIN_PER_GRAM_MAX', 0.35),
        // Catálogo que recibe CADA día de rutina (sus grupos musculares): un
        // mínimo para elegir con alternativas y un tope para no reenviar el
        // catálogo entero. Los ids se validan siempre contra el completo.
        'catalog_per_day_min' => (int) env('IRON_AI_PLAN_CATALOG_PER_DAY_MIN', 24),
        'catalog_per_day_max' => (int) env('IRON_AI_PLAN_CATALOG_PER_DAY_MAX', 48),
        // Comidas de una sección con EXACTAMENTE la meta por comida (±1 g) a
        // partir de las cuales se considera copia y se repara.
        'copy_meals_threshold' => (int) env('IRON_AI_PLAN_COPY_MEALS_THRESHOLD', 2),
        // Mínimo prudente de kcal por día en los totales calculados, también
        // sin meta y en modo seguro. null = el piso de la calculadora por sexo
        // metabólico (nutrition.goal_calculator.calorie_safety_floors:
        // 1.500 hombre, 1.200 mujer, 1.300 sin dato).
        'min_daily_kcal' => env('IRON_AI_PLAN_MIN_DAILY_KCAL'),
        // Coste y concurrencia (A1).
        'trainer_max_per_window' => (int) env('IRON_AI_PLAN_TRAINER_MAX', 10),
        'trainer_window_seconds' => (int) env('IRON_AI_PLAN_TRAINER_WINDOW', 600),
        'daily_tokens_budget' => (int) env('IRON_AI_PLAN_DAILY_TOKENS', 3000000),
        'member_daily_generations' => (int) env('IRON_AI_PLAN_MEMBER_DAILY', 10),
    ],

    // Estimación de costo (USD por 1M tokens). Solo para iron_ai_usage_logs;
    // ajustable. null en cualquiera → estimated_cost = null.
    'pricing' => [
        'input_per_million'  => env('IRON_AI_PRICE_INPUT_PER_M', 0.40),
        'output_per_million' => env('IRON_AI_PRICE_OUTPUT_PER_M', 1.60),
    ],
];
