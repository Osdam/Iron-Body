<?php

return [
    // Búsqueda externa global (si false, solo BD local Iron Body / usuario).
    'external_search_enabled' => filter_var(env('NUTRITION_EXTERNAL_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Open Food Facts — productos con código de barras (sin API key).
    'openfoodfacts' => [
        'enabled'  => filter_var(env('NUTRITION_OPENFOODFACTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('NUTRITION_OPENFOODFACTS_BASE_URL', 'https://world.openfoodfacts.org'),
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
    'ocr' => [
        'enabled'  => filter_var(env('NUTRITION_OCR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('NUTRITION_OCR_PROVIDER', 'local'),
    ],

    // Caché de alimentos externos (días) y timeouts (segundos).
    'cache_ttl_days'          => (int) env('NUTRITION_CACHE_TTL_DAYS', 90),
    'search_timeout_seconds'  => (int) env('NUTRITION_SEARCH_TIMEOUT_SECONDS', 8),
    'barcode_timeout_seconds' => (int) env('NUTRITION_BARCODE_TIMEOUT_SECONDS', 8),
];
