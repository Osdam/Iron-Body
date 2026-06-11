<?php

namespace App\Services\Nutrition;

use App\Models\Member;
use App\Models\NutritionGoal;
use App\Models\PhysicalEvaluation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orquesta la meta nutricional personalizada del miembro: reúne los datos del
 * perfil (género, edad, objetivo, experiencia) + la última evaluación física
 * (peso/estatura) + los datos que falten (actividad, días), detecta si faltan
 * datos (setup_required), valida rangos, llama a la calculadora determinística
 * y persiste el resultado en nutrition_goals (fuente de verdad).
 *
 * La IA puede explicar o sugerir revisar; los cambios reales SIEMPRE pasan por
 * aquí. Nunca se hardcodea 2200 kcal ni se calcula en Flutter.
 */
class NutritionGoalService
{
    public function __construct(
        private NutritionGoalCalculatorService $calculator,
        private NutritionGoalMapper $mapper,
    ) {
    }

    /**
     * Estado de la meta para la app: meta calculada/manual O setup_required con
     * los datos ya conocidos prellenados y la lista de lo que falta.
     *
     * @return array<string,mixed>
     */
    public function state(Member $member): array
    {
        $goal = $this->activeGoal($member);
        if ($goal !== null) {
            $payload = $goal->toPublicArray();
            $recalc = $this->recalculationSuggestion($member, $goal);
            return [
                'status'             => $payload['status'],
                'goal'               => $payload,
                'needs_recalculation' => $recalc !== null,
                'recalculation'      => $recalc,
            ];
        }

        // Sin meta activa → setup_required con prellenado.
        $resolved = $this->buildInputs($member);
        return [
            'status'  => 'setup_required',
            'goal'    => null,
            'missing' => $resolved['missing'],
            'prefill' => $resolved['prefill'],
            'needs_recalculation' => false,
        ];
    }

    /**
     * Calcula una PREVIEW (sin guardar). Lanza ValidationException si hay valores
     * fuera de rango; devuelve setup_required si aún faltan datos obligatorios.
     *
     * @return array<string,mixed>
     */
    public function preview(Member $member, array $overrides = []): array
    {
        $resolved = $this->buildInputs($member, $overrides);

        if (! empty($resolved['errors'])) {
            throw ValidationException::withMessages($resolved['errors']);
        }
        if (! empty($resolved['missing'])) {
            return [
                'status'  => 'setup_required',
                'missing' => $resolved['missing'],
                'prefill' => $resolved['prefill'],
            ];
        }

        $result = $this->calculator->calculate($resolved['inputs']);
        return ['status' => 'preview', 'goal' => $result, 'inputs' => $resolved['prefill']];
    }

    /**
     * Calcula y GUARDA la meta. Desactiva la anterior y crea la nueva activa.
     *
     * @return array<string,mixed>
     */
    public function saveCalculated(Member $member, array $overrides = []): array
    {
        $resolved = $this->buildInputs($member, $overrides);

        if (! empty($resolved['errors'])) {
            throw ValidationException::withMessages($resolved['errors']);
        }
        if (! empty($resolved['missing'])) {
            return [
                'status'  => 'setup_required',
                'missing' => $resolved['missing'],
                'prefill' => $resolved['prefill'],
            ];
        }

        $inputs = $resolved['inputs'];
        $result = $this->calculator->calculate($inputs);

        $goal = DB::transaction(function () use ($member, $inputs, $result) {
            NutritionGoal::query()
                ->where('member_id', $member->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return NutritionGoal::create([
                'member_id'      => $member->id,
                'daily_calories' => $result['daily_calories'],
                'protein_g'      => $result['protein_g'],
                'carbs_g'        => $result['carbs_g'],
                'fat_g'          => $result['fat_g'],
                'fiber_g'        => $result['fiber_g'],
                'goal_type'      => $this->legacyGoalType($result['objective']),
                'objective'      => $result['objective'],
                'experience_level' => $inputs['experience_level'] ?? null,
                'gender_identity'  => $inputs['gender_identity'] ?? null,
                'metabolic_sex'    => $result['metabolic_sex'],
                'age_at_calculation' => $inputs['age'],
                'birthdate'        => $inputs['birthdate'] ?? null,
                'weight_kg'        => $inputs['weight_kg'],
                'height_cm'        => $inputs['height_cm'],
                'target_weight_kg' => $inputs['target_weight_kg'] ?? null,
                'activity_level'   => $result['activity_level'],
                'activity_factor'  => $result['activity_factor'],
                'training_days_per_week' => $inputs['training_days_per_week'] ?? null,
                'training_type'    => $inputs['training_type'] ?? null,
                'pace'             => $inputs['pace'] ?? null,
                'formula'          => $result['formula'],
                'formula_version'  => $result['formula_version'],
                'bmr'              => $result['bmr'],
                'tdee'             => $result['tdee'],
                'source'           => 'calculated',
                'status'           => 'complete',
                'is_manual_override' => false,
                'warnings'         => $result['warnings'],
                'explanation'      => $result['explanation'],
                'calculated_at'    => now(),
                'is_active'        => true,
            ]);
        });

        return ['status' => 'complete', 'goal' => $goal->toPublicArray()];
    }

    /**
     * Recalcula con los datos ACTUALES (perfil + última evaluación). Respeta una
     * meta manual: no la sobreescribe salvo confirmación explícita (force).
     *
     * @return array<string,mixed>
     */
    public function recalculate(Member $member, array $overrides = [], bool $force = false): array
    {
        $current = $this->activeGoal($member);
        if ($current && $current->is_manual_override && ! $force) {
            return [
                'status'  => 'manual_locked',
                'message' => 'La meta es manual. Confirma para recalcular y reemplazarla.',
                'goal'    => $current->toPublicArray(),
            ];
        }

        // Reusar el snapshot anterior como base si existe (objetivo, actividad…),
        // dejando que los overrides y los datos vivos manden.
        if ($current) {
            $overrides = array_merge($this->snapshotOverrides($current), $overrides);
        }

        return $this->saveCalculated($member, $overrides);
    }

    /**
     * Resuelve la meta activa del miembro (la que consume la app legacy también).
     */
    public function activeGoal(Member $member): ?NutritionGoal
    {
        return NutritionGoal::query()
            ->where('member_id', $member->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * Reúne y valida las entradas del cálculo desde perfil + overrides.
     *
     * @return array{inputs:array<string,mixed>,missing:array<int,string>,errors:array<string,string>,prefill:array<string,mixed>}
     */
    public function buildInputs(Member $member, array $overrides = []): array
    {
        $cfg = (array) config('nutrition.goal_calculator');
        $ranges = $cfg['validation_ranges'];

        // ── Sexo metabólico ──────────────────────────────────────────────────
        $genderIdentity = $member->gender;
        $metabolicSex = null;
        if (isset($overrides['metabolic_sex']) && in_array($overrides['metabolic_sex'], ['male', 'female', 'unspecified'], true)) {
            $metabolicSex = $overrides['metabolic_sex'];
        } else {
            $metabolicSex = $this->mapper->metabolicSex($member->gender);
        }

        // ── Edad / fecha de nacimiento ───────────────────────────────────────
        $birthdate = $overrides['birthdate'] ?? ($member->birth_date?->toDateString());
        $age = isset($overrides['age']) ? (int) $overrides['age'] : $this->ageFrom($birthdate);

        // ── Peso / estatura (override → última evaluación física) ────────────
        $eval = $this->latestEvaluation($member);
        $weight = $this->floatOrNull($overrides['weight_kg'] ?? $eval?->weight_kg);
        $height = $this->floatOrNull($overrides['height_cm'] ?? $eval?->height_cm);

        // ── Objetivo / experiencia ───────────────────────────────────────────
        $objective = isset($overrides['objective'])
            ? $this->calculator->normalizeObjective($overrides['objective'])
            : $this->mapper->objective($member->goal);
        $experience = isset($overrides['experience_level'])
            ? $overrides['experience_level']
            : $this->mapper->experienceLevel($member->training_level);

        // ── Actividad (override → días de entrenamiento → null) ───────────────
        $trainingDays = isset($overrides['training_days_per_week'])
            ? (int) $overrides['training_days_per_week'] : null;
        $activityLevel = $overrides['activity_level']
            ?? $this->mapper->activityFromTrainingDays($trainingDays);
        if ($activityLevel !== null && ! isset($cfg['activity_factors'][$activityLevel])) {
            $activityLevel = null;
        }

        $isMinor = (bool) $member->is_minor
            || ($age !== null && $age < ($ranges['minor_age_threshold'] ?? 18));

        $inputs = [
            'metabolic_sex'   => $metabolicSex,
            'gender_identity' => $genderIdentity,
            'age'             => $age,
            'birthdate'       => $birthdate,
            'weight_kg'       => $weight,
            'height_cm'       => $height,
            'objective'       => $objective,
            'experience_level' => $experience,
            'activity_level'  => $activityLevel,
            'activity_factor' => isset($overrides['activity_factor']) ? (float) $overrides['activity_factor'] : null,
            'training_days_per_week' => $trainingDays,
            'training_type'   => $overrides['training_type'] ?? null,
            'target_weight_kg' => $this->floatOrNull($overrides['target_weight_kg'] ?? null),
            'pace'            => $overrides['pace'] ?? null,
            'is_minor'        => $isMinor,
        ];

        // ── Faltantes (campos obligatorios para calcular) ────────────────────
        $required = $cfg['setup_required_fields'];
        $missing = [];
        foreach ($required as $field) {
            if ($inputs[$field] === null || $inputs[$field] === '') {
                $missing[] = $field;
            }
        }

        // ── Errores de rango (solo si el valor está presente) ────────────────
        $errors = [];
        if ($weight !== null && ! $this->inRange($weight, $ranges['weight_kg'])) {
            $errors['weight_kg'] = "El peso debe estar entre {$ranges['weight_kg']['min']} y {$ranges['weight_kg']['max']} kg.";
        }
        if ($height !== null && ! $this->inRange($height, $ranges['height_cm'])) {
            $errors['height_cm'] = "La estatura debe estar entre {$ranges['height_cm']['min']} y {$ranges['height_cm']['max']} cm.";
        }
        if ($age !== null && ! $this->inRange($age, $ranges['age'])) {
            $errors['age'] = "La edad debe estar entre {$ranges['age']['min']} y {$ranges['age']['max']} años.";
        }

        return [
            'inputs'  => $inputs,
            'missing' => $missing,
            'errors'  => $errors,
            'prefill' => $this->prefill($inputs),
        ];
    }

    /** Sugerencia de recálculo si el peso vivo cambió respecto al de la meta. */
    public function recalculationSuggestion(Member $member, ?NutritionGoal $goal = null): ?array
    {
        $goal ??= $this->activeGoal($member);
        if ($goal === null || $goal->weight_kg === null) {
            return null;
        }
        $eval = $this->latestEvaluation($member);
        $currentWeight = $this->floatOrNull($eval?->weight_kg);
        if ($currentWeight === null) {
            return null;
        }
        $threshold = (float) config('nutrition.goal_calculator.recalculation_thresholds.weight_delta_kg', 2.0);
        $delta = round($currentWeight - (float) $goal->weight_kg, 1);
        if (abs($delta) < $threshold) {
            return null;
        }
        return [
            'reason'        => 'weight_changed',
            'previous_weight_kg' => (float) $goal->weight_kg,
            'current_weight_kg'  => $currentWeight,
            'delta_kg'      => $delta,
            'message'       => 'Tu peso cambió. ¿Quieres recalcular tu objetivo nutricional?',
        ];
    }

    /** Overrides derivados del snapshot de una meta (para recalcular sobre ella). */
    private function snapshotOverrides(NutritionGoal $goal): array
    {
        return array_filter([
            'objective'        => $goal->objective,
            'experience_level' => $goal->experience_level,
            'metabolic_sex'    => $goal->metabolic_sex,
            'activity_level'   => $goal->activity_level,
            'training_days_per_week' => $goal->training_days_per_week,
            'training_type'    => $goal->training_type,
            'target_weight_kg' => $goal->target_weight_kg,
            'pace'             => $goal->pace,
        ], fn ($v) => $v !== null);
    }

    /** Mapea el objetivo canónico al goal_type legado (compat UI/admin). */
    private function legacyGoalType(string $objective): string
    {
        return match ($objective) {
            'fat_loss' => 'lose_fat',
            'general_wellness', 'endurance' => 'maintain',
            default => 'gain_muscle',
        };
    }

    private function latestEvaluation(Member $member): ?PhysicalEvaluation
    {
        return PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function ageFrom(?string $birthdate): ?int
    {
        if (! $birthdate) {
            return null;
        }
        try {
            return Carbon::parse($birthdate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function floatOrNull($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function inRange($value, array $range): bool
    {
        return $value >= $range['min'] && $value <= $range['max'];
    }

    /** Datos conocidos que la app puede mostrar prellenados en el setup. */
    private function prefill(array $inputs): array
    {
        return [
            'gender_identity'  => $inputs['gender_identity'],
            'metabolic_sex'    => $inputs['metabolic_sex'],
            'age'              => $inputs['age'],
            'birthdate'        => $inputs['birthdate'],
            'weight_kg'        => $inputs['weight_kg'],
            'height_cm'        => $inputs['height_cm'],
            'objective'        => $inputs['objective'],
            'experience_level' => $inputs['experience_level'],
            'activity_level'   => $inputs['activity_level'],
            'training_days_per_week' => $inputs['training_days_per_week'],
            'target_weight_kg' => $inputs['target_weight_kg'],
            'pace'             => $inputs['pace'],
            'is_minor'         => $inputs['is_minor'],
        ];
    }
}
