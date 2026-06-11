<?php

namespace App\Services\Nutrition;

/**
 * Calculadora DETERMINÍSTICA de metas nutricionales (estilo Fitia).
 *
 *   BMR (Mifflin-St Jeor) → TDEE (× factor de actividad) → ajuste por objetivo
 *   → macros (g/kg con carbohidratos por resto).
 *
 * NO toca la base de datos ni la IA: recibe datos ya normalizados y devuelve un
 * resultado puro y auditable. Todas las constantes vienen de
 * config('nutrition.goal_calculator'). La IA puede EXPLICAR este resultado, pero
 * jamás inventarlo ni sobreescribirlo.
 */
class NutritionGoalCalculatorService
{
    /** @return array<string,mixed> */
    public function config(): array
    {
        return (array) config('nutrition.goal_calculator');
    }

    /**
     * Calcula la meta a partir de entradas normalizadas y validadas.
     *
     * @param array{
     *   metabolic_sex:string, age:int, weight_kg:float, height_cm:float,
     *   objective:string, experience_level?:?string, activity_level:string,
     *   activity_factor?:?float, pace?:?string, is_minor?:bool
     * } $in
     * @return array<string,mixed>
     */
    public function calculate(array $in): array
    {
        $cfg = $this->config();
        $warnings = [];

        $sex = in_array($in['metabolic_sex'] ?? null, ['male', 'female'], true)
            ? $in['metabolic_sex'] : 'unspecified';
        $weight = (float) $in['weight_kg'];
        $height = (float) $in['height_cm'];
        $age    = (int) $in['age'];
        $objective  = $this->normalizeObjective($in['objective'] ?? 'general_wellness');
        $experience = $in['experience_level'] ?? null;

        // ── 1) BMR — Mifflin-St Jeor ─────────────────────────────────────────
        $base = 10 * $weight + 6.25 * $height - 5 * $age;
        if ($sex === 'male') {
            $bmr = $base + 5;
        } elseif ($sex === 'female') {
            $bmr = $base - 161;
        } else {
            // Neutral: promedio de las constantes hombre/mujer (+5 y -161) = -78.
            // Menor precisión → warning explícito.
            $bmr = $base + ((5 + -161) / 2);
            $warnings[] = 'metabolic_sex_unspecified';
        }
        $bmr = max(0.0, $bmr);

        // ── 2) TDEE ──────────────────────────────────────────────────────────
        $activityLevel = (string) ($in['activity_level'] ?? 'light');
        $factor = (float) ($in['activity_factor']
            ?? ($cfg['activity_factors'][$activityLevel] ?? $cfg['activity_factors']['light']));
        $tdee = $bmr * $factor;

        // ── 3) Ajuste calórico por objetivo ──────────────────────────────────
        $adjustment = $this->calorieAdjustment($cfg, $objective, $experience, $in['pace'] ?? null);
        $target = $tdee + $adjustment;

        // Piso de seguridad por sexo metabólico (nunca metas peligrosamente bajas).
        $floor = (float) ($cfg['calorie_safety_floors'][$sex] ?? 1300);
        if ($target < $floor) {
            $target = $floor;
            $warnings[] = 'calorie_floor_applied';
        }

        if (! empty($in['is_minor'])) {
            // Menor de edad: meta conservadora (sin déficit agresivo) + aviso.
            if ($adjustment < 0) {
                $target = max($target, $tdee); // no aplicar déficit a un menor
            }
            $warnings[] = 'minor_conservative';
        }

        // ── 4) Macros (g/kg, carbohidratos por resto) ────────────────────────
        $atw = $cfg['atwater'];
        $proteinPerKg = (float) ($cfg['macro_ranges']['protein_g_per_kg'][$objective] ?? 1.6);
        $fatPerKg     = (float) ($cfg['macro_ranges']['fat_g_per_kg'][$objective] ?? 0.9);

        $protein = $proteinPerKg * $weight;
        $fat     = $fatPerKg * $weight;

        $carbsKcal = $target - ($protein * $atw['protein']) - ($fat * $atw['fat']);

        // Si los carbohidratos quedan negativos: primero bajamos grasa hasta su
        // piso; si aún negativo, bajamos proteína hasta su piso; nunca < 0.
        if ($carbsKcal < 0) {
            $fatFloor = (float) ($cfg['macro_ranges']['fat_g_per_kg_floor'] ?? 0.5) * $weight;
            $fat = max($fatFloor, ($target - ($protein * $atw['protein'])) / $atw['fat']);
            $fat = max(0.0, $fat);
            $carbsKcal = $target - ($protein * $atw['protein']) - ($fat * $atw['fat']);
            $warnings[] = 'fat_reduced_to_fit_calories';
        }
        if ($carbsKcal < 0) {
            $proteinFloor = (float) ($cfg['macro_ranges']['protein_g_per_kg_floor'] ?? 1.2) * $weight;
            $protein = max($proteinFloor, ($target - ($fat * $atw['fat'])) / $atw['protein']);
            $protein = max(0.0, $protein);
            $carbsKcal = $target - ($protein * $atw['protein']) - ($fat * $atw['fat']);
            $warnings[] = 'protein_reduced_to_fit_calories';
        }
        $carbs = max(0.0, $carbsKcal) / $atw['carbs'];

        $fiber = ((float) ($cfg['macro_ranges']['fiber_g_per_1000_kcal'] ?? 14)) * ($target / 1000.0);

        // ── 5) Redondeos finales ─────────────────────────────────────────────
        $calStep   = max(1, (int) ($cfg['rounding_rules']['calories_to'] ?? 10));
        $macroStep = max(1, (int) ($cfg['rounding_rules']['macros_to'] ?? 1));

        $targetCalories = (int) (round($target / $calStep) * $calStep);
        $proteinG = (int) (round($protein / $macroStep) * $macroStep);
        $carbsG   = (int) (round($carbs / $macroStep) * $macroStep);
        $fatG     = (int) (round($fat / $macroStep) * $macroStep);
        $fiberG   = (int) round($fiber);

        return [
            'formula'         => $cfg['default_formula'] ?? 'mifflin_st_jeor',
            'formula_version' => $cfg['formula_version'] ?? 'v1',
            'metabolic_sex'   => $sex,
            'objective'       => $objective,
            'activity_level'  => $activityLevel,
            'activity_factor' => round($factor, 3),
            'bmr'             => (int) round($bmr),
            'tdee'            => (int) round($tdee),
            'maintenance_calories' => (int) round($tdee),
            'calorie_adjustment'   => (int) round($adjustment),
            'daily_calories'  => $targetCalories,
            'protein_g'       => $proteinG,
            'carbs_g'         => $carbsG,
            'fat_g'           => $fatG,
            'fiber_g'         => $fiberG,
            'warnings'        => array_values(array_unique($warnings)),
            'explanation'     => $this->explanation($objective, (int) round($tdee), $targetCalories, $proteinG, $carbsG, $fatG),
        ];
    }

    /** Ajuste calórico (kcal) sobre el TDEE según objetivo/experiencia/ritmo. */
    private function calorieAdjustment(array $cfg, string $objective, ?string $experience, ?string $pace): float
    {
        $map = $cfg['objective_calorie_adjustments'][$objective] ?? ['default' => 0];

        // fat_loss usa ritmo (conservative|moderate|aggressive).
        if ($objective === 'fat_loss') {
            $key = $pace ?: 'default';
            return (float) ($map[$key] ?? $map['default'] ?? -450);
        }

        // muscle_gain / strength usan experiencia.
        if (in_array($objective, ['muscle_gain', 'strength'], true)) {
            $key = $experience ?: 'default';
            return (float) ($map[$key] ?? $map['default'] ?? 0);
        }

        return (float) ($map['default'] ?? 0);
    }

    /** Texto breve y claro (sin lenguaje médico) que explica el cálculo. */
    private function explanation(string $objective, int $tdee, int $target, int $protein, int $carbs, int $fat): string
    {
        $labels = [
            'muscle_gain'      => 'hipertrofia muscular',
            'fat_loss'         => 'pérdida de grasa',
            'endurance'        => 'resistencia',
            'strength'         => 'fuerza',
            'general_wellness' => 'bienestar general',
        ];
        $label = $labels[$objective] ?? 'tu objetivo';
        $delta = $target - $tdee;
        if ($delta > 0) {
            $adj = "empezamos con un superávit controlado de {$delta} kcal";
        } elseif ($delta < 0) {
            $adj = 'aplicamos un déficit de ' . abs($delta) . ' kcal';
        } else {
            $adj = 'mantenemos tus calorías de mantenimiento';
        }

        return "Tu mantenimiento estimado es {$tdee} kcal. Para {$label}, {$adj}. "
            . "Tu meta diaria es {$target} kcal, con {$protein} g de proteína, "
            . "{$carbs} g de carbohidratos y {$fat} g de grasa. "
            . 'Calculado según tu peso, estatura, edad, actividad y objetivo.';
    }

    /** Normaliza el objetivo a una de las 5 claves canónicas. */
    public function normalizeObjective(string $objective): string
    {
        $valid = ['muscle_gain', 'fat_loss', 'endurance', 'strength', 'general_wellness'];
        return in_array($objective, $valid, true) ? $objective : 'general_wellness';
    }
}
