<?php

return [
    // Búsqueda externa global (si false, solo BD local Iron Body / usuario).
    'external_search_enabled' => filter_var(env('NUTRITION_EXTERNAL_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Open Food Facts — productos con código de barras (sin API key).
    'openfoodfacts' => [
        'enabled'  => filter_var(env('NUTRITION_OPENFOODFACTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('NUTRITION_OPENFOODFACTS_BASE_URL', 'https://world.openfoodfacts.org'),
        // User-Agent identificable (lo exige OFF), país e idioma para priorizar
        // resultados en Colombia/español. SIEMPRE se envía en las llamadas HTTP.
        'user_agent' => env('NUTRITION_OFF_USER_AGENT', 'IronBodyNeiva/1.0 (soporte@ironbodyneiva.cloud)'),
        'country'    => env('NUTRITION_OFF_COUNTRY', 'colombia'),
        'language'   => env('NUTRITION_OFF_LANGUAGE', 'es'),
        // Importador masivo opcional desde dump local (deshabilitado por defecto).
        'import' => [
            'enabled'    => filter_var(env('NUTRITION_OFF_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'path'       => env('NUTRITION_OFF_IMPORT_PATH'),
            'batch_size' => (int) env('NUTRITION_OFF_IMPORT_BATCH_SIZE', 500),
            'limit'      => env('NUTRITION_OFF_IMPORT_LIMIT') !== null
                ? (int) env('NUTRITION_OFF_IMPORT_LIMIT') : null,
        ],
    ],

    // USDA FoodData Central — alimentos genéricos (requiere API key).
    'usda' => [
        'enabled'  => filter_var(env('NUTRITION_USDA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('NUTRITION_USDA_BASE_URL', 'https://api.nal.usda.gov/fdc/v1'),
        'api_key'  => env('NUTRITION_USDA_API_KEY'),
    ],

    // Nutritionix — proveedor comercial (adapter futuro).
    'nutritionix' => [
        'enabled' => filter_var(env('NUTRITION_NUTRITIONIX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'app_id'  => env('NUTRITION_NUTRITIONIX_APP_ID'),
        'app_key' => env('NUTRITION_NUTRITIONIX_APP_KEY'),
    ],

    // OCR de etiqueta nutricional (modo seguro: disabled → unavailable).
    // Motor real: Tesseract instalado en el VPS (sin costos mensuales). El OCR
    // SOLO propone valores; el usuario SIEMPRE confirma antes de guardar.
    'ocr' => [
        'enabled'  => filter_var(env('NUTRITION_OCR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // 'disabled' | 'tesseract' (o 'local' legado: parsea solo texto de cliente).
        'provider' => env('NUTRITION_OCR_PROVIDER', 'disabled'),
        'tesseract_bin' => env('NUTRITION_OCR_TESSERACT_BIN', '/usr/bin/tesseract'),
        'lang' => env('NUTRITION_OCR_LANG', 'spa+eng'),
        'timeout_seconds' => (int) env('NUTRITION_OCR_TIMEOUT_SECONDS', 20),
        'max_image_mb' => (int) env('NUTRITION_OCR_MAX_IMAGE_MB', 8),
        // Si false, NUNCA se persiste la imagen original (solo se usa en memoria/temporal).
        'store_original' => filter_var(env('NUTRITION_OCR_STORE_ORIGINAL', false), FILTER_VALIDATE_BOOLEAN),
        'require_user_confirmation' => filter_var(env('NUTRITION_OCR_REQUIRE_USER_CONFIRMATION', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Caché de alimentos externos (días) y timeouts (segundos).
    'cache_ttl_days'          => (int) env('NUTRITION_CACHE_TTL_DAYS', 90),
    'search_timeout_seconds'  => (int) env('NUTRITION_SEARCH_TIMEOUT_SECONDS', 8),
    'barcode_timeout_seconds' => (int) env('NUTRITION_BARCODE_TIMEOUT_SECONDS', 8),
];
