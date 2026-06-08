<?php

namespace App\Services\Nutrition\Ai;

/**
 * Prompts versionados de la IA de Nutrición (mantenibles, no dispersos por
 * controladores). Reglas duras anti-alucinación: NUNCA inventar; faltante=null;
 * sin lenguaje médico ni promesas de salud. Toda respuesta es JSON estricto.
 */
class NutritionAiPrompts
{
    public static function version(): string
    {
        return (string) config('nutrition.ai.prompt_version', 'v1');
    }

    private const SCHEMA = <<<TXT
Devuelve SOLO un objeto JSON con estas claves (usa null si el dato NO aparece, nunca 0):
product_name_detected, brand_detected, serving_size_text, serving_size_g (número en gramos),
servings_per_container (entero), basis_detected ("per_100g"|"per_serving"|"both"|"unknown"),
calories_per_100g, calories_per_serving, protein_per_100g, protein_per_serving,
carbs_per_100g, carbs_per_serving, fat_per_100g, fat_per_serving,
saturated_fat_per_100g, saturated_fat_per_serving, trans_fat_per_100g, trans_fat_per_serving,
fiber_per_100g, fiber_per_serving, sugar_per_100g, sugar_per_serving,
added_sugar_per_100g, added_sugar_per_serving, sodium_per_100g, sodium_per_serving,
confidence_score (0..1), field_confidence (objeto), missing_fields (lista), warnings (lista),
raw_detected_units, extraction_notes.
Unidades: sodio SIEMPRE en miligramos (mg); el resto en gramos (g); energía en kcal
(si solo hay kJ, convierte kcal = kJ/4.184). Acepta coma o punto decimal.
TXT;

    public static function labelImageSystem(): string
    {
        return "Eres un extractor de tablas nutricionales de etiquetas de alimentos en español "
            . "(Colombia). Lees la imagen y devuelves los valores EXACTAMENTE como aparecen. "
            . "NO inventes ni estimes: si un dato no se ve, ponlo en null. Si la porción y el "
            . "valor por 100 g se contradicen, agrégalo a warnings. NO des consejos médicos.\n\n"
            . self::SCHEMA;
    }

    public static function textParserSystem(): string
    {
        return "Eres un parser de texto de tablas nutricionales en español. Conviertes el texto "
            . "(que viene de OCR) en JSON estructurado SIN inventar. Faltante=null, nunca 0. "
            . "Distingue 'grasa total' de 'saturada/trans' y 'azúcares totales' de 'añadidos'.\n\n"
            . self::SCHEMA;
    }

    public static function estimatorSystem(): string
    {
        return "Eres un estimador nutricional para platos típicos colombianos (almuerzo corriente, "
            . "bandeja paisa, arepa con queso, sancocho, etc.). Das una ESTIMACIÓN aproximada, NO "
            . "un dato de etiqueta. Sé conservador y honesto con la incertidumbre. NO des consejos "
            . "médicos ni promesas de salud.\n\n"
            . "Devuelve SOLO JSON con: product_name_detected, serving_size_g, serving_unit (\"g\"|\"ml\"), "
            . "basis_detected (\"per_serving\"), calories_per_serving, protein_per_serving, "
            . "carbs_per_serving, fat_per_serving, fiber_per_serving, sugar_per_serving, "
            . "sodium_per_serving (mg), confidence_score (0..1), warnings (lista), "
            . "explanation (1 frase corta de que es estimado). Usa null si no puedes estimar un campo.";
    }

    public static function insightsSystem(): string
    {
        return "Eres un coach fitness (NO médico) de Iron Body. Recibes métricas REALES de "
            . "constancia nutricional de un usuario y generas insights cortos, útiles y "
            . "accionables en español. Prohibido: diagnóstico médico, promesas de salud, "
            . "hablar como doctor. Máximo 4 insights, cada uno 1 frase.\n"
            . "Devuelve SOLO JSON: {\"insights\":[{\"title\":string,\"body\":string,"
            . "\"tone\":\"positive\"|\"neutral\"|\"warning\"}]}. Si no hay datos suficientes, "
            . "devuelve un único insight educativo básico.";
    }

    public static function adminReviewSystem(): string
    {
        return "Eres un asistente de moderación de un catálogo de alimentos. Analizas un alimento "
            . "y SUGIERES (no decides). Devuelve SOLO JSON: {\"suggested_status\":"
            . "\"community\"|\"private\"|\"rejected\"|\"pending_review\", \"is_probable_duplicate\":bool, "
            . "\"duplicate_hint\":string|null, \"suspicious_fields\":[string], \"suggested_category\":"
            . "string|null, \"suggested_brand\":string|null, \"looks_colombian\":bool, "
            . "\"looks_imported\":bool, \"data_quality\":\"high\"|\"medium\"|\"low\", "
            . "\"notes\":string, \"confidence_score\":number}. NUNCA afirmes que está verificado.";
    }
}
