<?php

/** Convierte un CSV de .env ("a,b,c") en un array limpio sin vacíos. */
$csv = static function (?string $value, array $default = []): array {
    if ($value === null || trim($value) === '') {
        return $default;
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
};

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

        // ── Cobertura Colombia ───────────────────────────────────────────────
        // Prioriza productos vendidos en Colombia (cadenas, marcas locales,
        // prefijos de código de barras). NO garantiza 100% de los productos:
        // la cobertura se cierra con base propia + caché + importador + OCR.
        'colombia_enabled'  => filter_var(env('NUTRITION_OFF_COLOMBIA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'colombia_retailers' => $csv(env('NUTRITION_OFF_COLOMBIA_RETAILERS'), [
            'D1', 'Tiendas D1', 'Éxito', 'Exito', 'Carulla', 'Surtimax', 'Super Inter',
            'Olímpica', 'Olimpica', 'Ara', 'Jumbo', 'Metro', 'Alkosto', 'PriceSmart',
            'Colsubsidio', 'Euro', 'La 14', 'Popular',
        ]),
        'colombia_brand_seeds' => $csv(env('NUTRITION_OFF_COLOMBIA_BRAND_SEEDS'), [
            'D1', 'Éxito', 'Exito', 'Carulla', 'Ara', 'Olímpica', 'Olimpica', 'Zenú', 'Zenu',
            'Colanta', 'Alpina', 'Alquería', 'Alqueria', 'Noel', 'Ramo', 'Jet', 'Postobón',
            'Postobon', 'Hatsu', 'Fruco', 'La Muñeca', 'Diana', 'Roa', 'Florhuila',
            'Doña Gallina', 'Margarita', 'Nestlé', 'Nestle', 'Kellogg', 'Quaker', 'Bimbo',
            'Colombina', 'Frutiño', 'Frutino', 'Juan Valdez', 'Sello Rojo', 'Águila Roja',
            'Aguila Roja',
        ]),
        // Prefijos GS1 de Colombia (no todos los importados los tienen: solo prioriza).
        'colombia_barcode_prefixes' => $csv(env('NUTRITION_OFF_COLOMBIA_BARCODE_PREFIXES'), ['770']),
        'import_colombia_priority'  => filter_var(env('NUTRITION_OFF_IMPORT_COLOMBIA_PRIORITY', true), FILTER_VALIDATE_BOOLEAN),

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

    // Metas nutricionales por defecto SOLO de fallback de adherencia (constancia).
    // NO es la meta del usuario: la meta real personalizada vive por miembro en
    // nutrition_goals (calculada con goal_calculator). Esta tolerancia/valores se
    // usan únicamente cuando un miembro aún no tiene meta para no romper gráficas.
    'goals' => [
        'calories'  => (float) env('NUTRITION_GOAL_CALORIES', 2200),
        'protein'   => (float) env('NUTRITION_GOAL_PROTEIN', 150),
        'carbs'     => (float) env('NUTRITION_GOAL_CARBS', 250),
        'fat'       => (float) env('NUTRITION_GOAL_FAT', 70),
        // Tolerancia ± para considerar un día "en rango" (0.10 = ±10%).
        'tolerance' => (float) env('NUTRITION_GOAL_TOLERANCE', 0.10),
    ],

    // ── Calculadora de metas nutricionales personalizadas (estilo Fitia) ──────
    // El BACKEND es la única autoridad: calcula BMR (Mifflin-St Jeor) → TDEE →
    // ajuste por objetivo → macros. Flutter NUNCA calcula la meta final ni
    // hardcodea valores. Todas las constantes de negocio viven aquí (no en
    // controladores ni en la app). Versionado para auditar cambios de fórmula.
    'goal_calculator' => [
        'default_formula' => env('NUTRITION_GOAL_FORMULA', 'mifflin_st_jeor'),
        'formula_version' => env('NUTRITION_GOAL_FORMULA_VERSION', 'v1'),

        // Factores de actividad sobre el BMR → TDEE.
        'activity_factors' => [
            'sedentary'   => 1.20,
            'light'       => 1.375,
            'moderate'    => 1.55,
            'very_active' => 1.725,
            'athlete'     => 1.90,
        ],

        // Sugerencia de nivel de actividad según días de entrenamiento/semana.
        'training_days_to_activity' => [
            0 => 'sedentary', 1 => 'light', 2 => 'light',
            3 => 'moderate', 4 => 'moderate', 5 => 'moderate',
            6 => 'very_active', 7 => 'athlete',
        ],

        // Ajuste calórico (kcal) sobre el TDEE por objetivo. Donde el superávit/
        // déficit depende de experiencia o ritmo, se define por clave.
        'objective_calorie_adjustments' => [
            'muscle_gain'      => ['beginner' => 300, 'intermediate' => 250, 'advanced' => 200, 'default' => 250],
            'strength'         => ['beginner' => 250, 'intermediate' => 200, 'advanced' => 150, 'default' => 200],
            'fat_loss'         => ['conservative' => -250, 'moderate' => -450, 'aggressive' => -600, 'default' => -450],
            'endurance'        => ['default' => 100],
            'general_wellness' => ['default' => 0],
        ],

        // Gramos por kg de peso (valor objetivo). Carbohidratos = calorías resto.
        'macro_ranges' => [
            'protein_g_per_kg' => [
                'fat_loss' => 2.0, 'muscle_gain' => 1.8, 'strength' => 1.8,
                'endurance' => 1.6, 'general_wellness' => 1.6,
            ],
            'fat_g_per_kg' => [
                'fat_loss' => 0.7, 'muscle_gain' => 0.9, 'strength' => 0.9,
                'endurance' => 0.85, 'general_wellness' => 0.9,
            ],
            // Piso de grasa (g/kg) al ajustar para que los carbos no queden < 0.
            'fat_g_per_kg_floor'     => 0.5,
            // Piso de proteína (g/kg) al ajustar (preservar masa muscular).
            'protein_g_per_kg_floor' => 1.2,
            // Fibra sugerida: 14 g por cada 1000 kcal (estándar dietético).
            'fiber_g_per_1000_kcal'  => 14,
        ],

        // kcal por gramo de macronutriente (factores de Atwater).
        'atwater' => ['protein' => 4, 'carbs' => 4, 'fat' => 9],

        // Piso de calorías por sexo metabólico: nunca metas peligrosamente bajas.
        'calorie_safety_floors' => [
            'male' => 1500, 'female' => 1200, 'unspecified' => 1300,
        ],

        // Rangos realistas de validación (fuera de rango → se rechaza/avisa).
        'validation_ranges' => [
            'age'                 => ['min' => 14, 'max' => 100],
            'weight_kg'           => ['min' => 30, 'max' => 300],
            'height_cm'           => ['min' => 120, 'max' => 230],
            'minor_age_threshold' => 18,
        ],

        // Sugerir recálculo cuando el peso cambia ≥ este delta (kg).
        'recalculation_thresholds' => [
            'weight_delta_kg' => 2.0,
        ],

        // Redondeos finales.
        'rounding_rules' => [
            'calories_to' => 10, // múltiplos de 10 kcal
            'macros_to'   => 1,  // gramos enteros
        ],

        // Campos mínimos para poder calcular una meta real (si falta alguno →
        // status setup_required, la app pide solo lo faltante).
        'setup_required_fields' => ['metabolic_sex', 'age', 'weight_kg', 'height_cm', 'objective', 'activity_level'],
    ],

    // ── Capa IA (OpenAI) de asistencia — NO es fuente certificada de verdad ──
    // Solo extrae/estructura/estima(marcado)/sugiere. Nunca marca verified ni
    // sobreescribe datos verificados. La key vive en services.openai (backend).
    'ai' => [
        'enabled'  => filter_var(env('NUTRITION_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('NUTRITION_AI_PROVIDER', 'openai'),
        // Modelos por flujo (null → cae a services.openai.vision_model/model).
        'model_label_image' => env('NUTRITION_AI_MODEL_LABEL_IMAGE'),
        'model_text_parser' => env('NUTRITION_AI_MODEL_TEXT'),
        'model_estimator'   => env('NUTRITION_AI_MODEL_ESTIMATE'),
        'model_insights'    => env('NUTRITION_AI_MODEL_INSIGHTS'),
        'model_admin_review' => env('NUTRITION_AI_MODEL_ADMIN'),
        'timeout_seconds'   => (int) env('NUTRITION_AI_TIMEOUT_SECONDS', 30),
        'max_image_mb'      => (int) env('NUTRITION_AI_MAX_IMAGE_MB', 6),
        // Límite de llamadas IA por usuario por día (anti-abuso).
        'rate_limit_per_user' => (int) env('NUTRITION_AI_RATE_LIMIT_PER_USER', 20),
        // Tope global de llamadas IA por día (cost guard, proxy de costo).
        'daily_cost_guard'  => (int) env('NUTRITION_AI_DAILY_COST_GUARD', 1000),
        'cache_enabled'     => filter_var(env('NUTRITION_AI_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'min_confidence_autofill' => (float) env('NUTRITION_AI_MIN_CONFIDENCE_AUTOFILL', 0.70),
        'min_confidence_estimate' => (float) env('NUTRITION_AI_MIN_CONFIDENCE_ESTIMATE', 0.60),
        'prompt_version'    => env('NUTRITION_AI_PROMPT_VERSION', 'v1'),
    ],

    // Base comunitaria: alimentos creados por usuarios que retroalimentan la base.
    'community' => [
        // Reportes necesarios para ocultar de búsquedas un alimento NO verificado.
        'reports_hide_threshold' => (int) env('NUTRITION_COMMUNITY_REPORTS_HIDE_THRESHOLD', 3),
        // Ventana (segundos) para deduplicar creaciones idénticas (anti doble-tap).
        'idempotency_window_seconds' => (int) env('NUTRITION_COMMUNITY_IDEMPOTENCY_WINDOW', 15),
    ],

    // Caché de alimentos externos (días) y timeouts (segundos).
    'cache_ttl_days'          => (int) env('NUTRITION_CACHE_TTL_DAYS', 90),
    'search_timeout_seconds'  => (int) env('NUTRITION_SEARCH_TIMEOUT_SECONDS', 8),
    'barcode_timeout_seconds' => (int) env('NUTRITION_BARCODE_TIMEOUT_SECONDS', 8),
];
