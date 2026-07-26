<?php

/*
|--------------------------------------------------------------------------
| IRON — Copiloto administrativo del CRM (OpenAI, solo lectura)
|--------------------------------------------------------------------------
| Este archivo configura EXCLUSIVAMENTE al asistente IRON del panel/CRM. NO se
| mezcla con `config/iron_ai.php` (capacidades de IRON IA de la app móvil).
|
| Arquitectura: Angular (CRM) → Laravel (auth.admin) → OpenAI. La API key vive
| SOLO en el backend; el frontend jamás la ve ni llama a OpenAI directo.
|
| Reutiliza la credencial ya configurada en `services.openai` (api_key/base_url)
| para no duplicar secretos. El MODELO sí es propio (por defecto gpt-4.1) para
| poder usar uno más capaz en el CRM sin alterar el de la app móvil.
|
| FASE 1: SOLO LECTURA. IRON consulta datos reales mediante herramientas fijas
| (function calling); nunca genera SQL crudo ni ejecuta escrituras.
*/

return [

    // Interruptor general del copiloto del CRM.
    'enabled' => filter_var(env('IRON_CRM_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Credencial y endpoint reutilizados de services.openai (sin duplicar .env).
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => rtrim(env('OPENAI_BASE_URL', env('IRON_CRM_AI_BASE_URL', 'https://api.openai.com')), '/'),

    // Modelo del copiloto (independiente del de la app móvil). El requerimiento
    // del producto pide gpt-4.1.
    'model' => env('IRON_CRM_AI_MODEL', 'gpt-4.1'),

    // Parámetros de generación.
    'temperature' => (float) env('IRON_CRM_AI_TEMPERATURE', 0.2),
    'max_tokens' => (int) env('IRON_CRM_AI_MAX_TOKENS', 900),
    'timeout' => (int) env('IRON_CRM_AI_TIMEOUT', 45),

    // Máximo de iteraciones de "function calling" por mensaje (evita bucles).
    'max_tool_iterations' => (int) env('IRON_CRM_AI_MAX_TOOL_ITERATIONS', 4),

    // Límites de entrada (sanitización / anti-abuso).
    'max_message_chars' => (int) env('IRON_CRM_AI_MAX_MESSAGE_CHARS', 4000),
    'max_history_messages' => (int) env('IRON_CRM_AI_MAX_HISTORY_MESSAGES', 12),
    'max_rows_per_tool' => (int) env('IRON_CRM_AI_MAX_ROWS_PER_TOOL', 25),

    // Adjuntos de imagen (visión). Solo lectura: no se persiste el archivo.
    'image' => [
        'enabled' => filter_var(env('IRON_CRM_AI_IMAGE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_size_mb' => (int) env('IRON_CRM_AI_IMAGE_MAX_SIZE_MB', 5),
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
