<?php

namespace App\Services\Nutrition;

/**
 * Parser de tabla nutricional desde texto OCR (español + inglés).
 *
 * Reglas clave (Iron Body):
 *  - NUNCA inventa: si un campo no se detecta, queda `null` (no 0).
 *  - Soporta coma o punto decimal y unidades g / mg / kcal / kJ.
 *  - Energía en kJ se convierte a kcal (kcal = kJ / 4.184).
 *  - Sodio se normaliza a miligramos (mg). "Sal/Salt" se convierte a sodio.
 *  - Detecta tamaño de porción ("por 100 g", "porción 30 g", "por porción").
 *  - La confianza es proporcional a cuántos macros base (cal/prot/carb/grasa)
 *    se detectaron. Baja confianza → la app pide revisión reforzada.
 */
class NutritionLabelParser
{
    /** Devuelve serving_size/unit + macros (null donde no se detecta) + confianza. */
    public function parse(string $text): array
    {
        $t = $this->normalize($text);
        $warnings = [];

        $serving = $this->parseServing($t);

        $macros = [
            'calories' => $this->parseCalories($t),
            'protein'  => $this->grab($t, ['prote[ií]nas?', 'proteins?', 'protein']),
            'carbs'    => $this->grab($t, [
                'carbohidratos? totales?', 'hidratos de carbono', 'carbohidratos?',
                'carbohydrates?', 'carbohyd\w*', 'carbos?', 'carbs?',
            ]),
            'fat'      => $this->grab($t, [
                'grasas? totales?', 'grasa total', 'grasas?', 'total fat', 'fat',
            ]),
            // Azúcares: total (evita "azúcares añadidos" si el total existe).
            'sugar'    => $this->grab($t, ['az[uú]cares? totales?', 'az[uú]cares?', 'sugars?', 'sugar']),
            'fiber'    => $this->grab($t, [
                'fibra(?: diet[eé]tica| alimentaria)?', 'dietary fiber', 'fiber', 'fibre',
            ]),
            'sodium'   => $this->parseSodium($t),
        ];

        // Datos adicionales (no rompen el draft; enriquecen calidad/auditoría).
        $extras = [
            'saturated_fat' => $this->grab($t, ['grasas? saturadas?', 'saturated fat', 'sat\.? fat']),
            'trans_fat'     => $this->grab($t, ['grasas? trans', 'trans fat']),
            'added_sugar'   => $this->grab($t, ['az[uú]cares? a[nñ]adidos?', 'added sugars?']),
        ];
        $portionsPerPackage = $this->grab($t, [
            'porciones por (?:envase|paquete|empaque)', 'servings per (?:container|package)',
        ]);

        // Confianza global + por campo (cada campo: 1.0 si detectado, 0 si null).
        $core = array_filter(
            [$macros['calories'], $macros['protein'], $macros['carbs'], $macros['fat']],
            static fn ($v) => $v !== null
        );
        $confidence = round(count($core) / 4, 3);
        $fieldConfidence = [];
        foreach ($macros as $k => $v) {
            $fieldConfidence[$k] = $v === null ? 0.0 : 1.0;
        }

        if ($confidence < 0.75) {
            $warnings[] = 'Confianza baja: revisa con cuidado los valores detectados.';
        }
        if ($serving['serving_size'] === null) {
            $warnings[] = 'No se detectó el tamaño de porción; verifica el valor.';
        }

        return [
            'serving_size'         => $serving['serving_size'],
            'serving_unit'         => $serving['serving_unit'],
            'basis'                => $serving['basis'],
            'portions_per_package' => $portionsPerPackage !== null ? (int) round($portionsPerPackage) : null,
            'macros'               => $macros,
            'extras'               => $extras,
            'confidence'           => $confidence,
            'field_confidence'     => $fieldConfidence,
            'warnings'             => $warnings,
        ];
    }

    /** ¿El texto es legible como tabla nutricional (al menos calorías o 1 macro)? */
    public function isReadable(array $parsed): bool
    {
        $m = $parsed['macros'];
        return $m['calories'] !== null || $m['protein'] !== null
            || $m['carbs'] !== null || $m['fat'] !== null;
    }

    private function normalize(string $text): string
    {
        // Minúsculas + colapsar espacios para regex estables.
        return preg_replace('/[ \t]+/', ' ', mb_strtolower($text));
    }

    /**
     * Busca el primer label que aparezca seguido de un número (con unidad
     * opcional g/mg). Devuelve el número como float o null si no aparece.
     */
    private function grab(string $t, array $labels): ?float
    {
        foreach ($labels as $label) {
            // Unidad opcional (µg/mcg/mg/g) — el valor se toma tal cual aparece.
            if (preg_match('/' . $label . '\D{0,15}(\d+(?:[.,]\d+)?)\s*(mcg|µg|mg|g)?/u', $t, $m)) {
                return $this->toFloat($m[1]);
            }
        }
        return null;
    }

    /** Calorías: prioriza kcal explícito; convierte kJ; soporta etiquetas ES/EN. */
    private function parseCalories(string $t): ?float
    {
        // 1) Número seguido de "kcal" → calorías directas.
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kcal/u', $t, $m)) {
            return $this->toFloat($m[1]);
        }
        // 2) Etiqueta de energía/calorías seguida de número (posible kJ).
        if (preg_match(
            '/(?:calor[ií]as|valor energ[eé]tico|energ[ií]a|calories|energy)\D{0,15}(\d+(?:[.,]\d+)?)\s*(kj)?/u',
            $t,
            $m
        )) {
            $val = $this->toFloat($m[1]);
            return ! empty($m[2]) ? round($val / 4.184, 1) : $val;
        }
        // 3) Solo kJ en el texto → convertir a kcal.
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kj/u', $t, $m)) {
            return round($this->toFloat($m[1]) / 4.184, 1);
        }
        return null;
    }

    /** Sodio normalizado a mg. Soporta "sodio" (mg/g) y "sal/salt" (conversión). */
    private function parseSodium(string $t): ?float
    {
        if (preg_match('/(?:sodio|sodium)\D{0,15}(\d+(?:[.,]\d+)?)\s*(mg|g)?/u', $t, $m)) {
            $val = $this->toFloat($m[1]);
            return (isset($m[2]) && $m[2] === 'g') ? round($val * 1000, 1) : round($val, 1);
        }
        // Sal/Salt → sodio = sal / 2.5 (0.4×). Por defecto la sal viene en g.
        if (preg_match('/(?:\bsal\b|\bsalt\b)\D{0,15}(\d+(?:[.,]\d+)?)\s*(mg|g)?/u', $t, $m)) {
            $val = $this->toFloat($m[1]);
            $saltMg = (isset($m[2]) && $m[2] === 'mg') ? $val : $val * 1000;
            return round($saltMg * 0.4, 1);
        }
        return null;
    }

    /** Detecta el tamaño de porción y la base de cálculo. */
    private function parseServing(string $t): array
    {
        $hasPortion = (bool) preg_match('/porci[oó]n|serving/u', $t);

        // 1) Cantidad entre paréntesis "(80 g)" / "(250 ml)" — peso real típico de
        //    la porción, p.ej. "1/3 de paquete (80 g)". Evita capturar el "1/3".
        if (preg_match('/\((\d+(?:[.,]\d+)?)\s*(ml|gr|g)\)/u', $t, $m)) {
            $size = $this->toFloat($m[1]);
            $unit = $this->normUnit($m[2]);
            // "(100 g)" sin mención de porción → base por 100 g (formato en línea
            // "Información Nutricional (100 g): …").
            $basis = ((int) $size === 100 && ! $hasPortion) ? '100' : 'serving';
            return ['serving_size' => $size, 'serving_unit' => $unit, 'basis' => $basis];
        }

        // 2) Porción explícita con tamaño (sin fracción): "Tamaño de porción 30 g".
        if (preg_match(
            '/(?:tama[nñ]o de porci[oó]n|tama[nñ]o porci[oó]n|cantidad por porci[oó]n|por porci[oó]n|porci[oó]n(?: de)?|serving size)\D{0,12}(\d+(?:[.,]\d+)?)\s*(ml|gr|g)?/u',
            $t,
            $m
        )) {
            return [
                'serving_size' => $this->toFloat($m[1]),
                'serving_unit' => $this->normUnit($m[2] ?? 'g'),
                'basis'        => 'serving',
            ];
        }
        // 3) "por 100 g" / "por 100 ml" / "100 g" en encabezado → base 100.
        if (preg_match('/por\s*100\s*(ml|gr|g)/u', $t, $m)
            || preg_match('/\b100\s*(ml|gr|g)\b/u', $t, $m)) {
            return [
                'serving_size' => 100.0,
                'serving_unit' => $this->normUnit($m[1]),
                'basis'        => '100',
            ];
        }
        return ['serving_size' => null, 'serving_unit' => 'g', 'basis' => null];
    }

    private function normUnit(string $unit): string
    {
        $u = trim(mb_strtolower($unit));
        return $u === 'ml' ? 'ml' : 'g';
    }

    private function toFloat(string $raw): float
    {
        return (float) str_replace(',', '.', $raw);
    }
}
