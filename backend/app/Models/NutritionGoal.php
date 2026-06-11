<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta nutricional del miembro: calorías + macros FINALES (canónicos) más el
 * snapshot del cálculo personalizado (BMR/TDEE/fórmula) que los produjo.
 *
 * Fuente de verdad en PostgreSQL. Una meta activa por miembro (is_active). El
 * cálculo lo hace SIEMPRE el backend (NutritionGoalCalculatorService); la app
 * nunca calcula ni hardcodea la meta.
 *
 * @property int $id
 * @property int $member_id
 * @property int $daily_calories
 * @property int $protein_g
 * @property int $carbs_g
 * @property int $fat_g
 * @property string|null $goal_type
 * @property string|null $objective
 * @property string|null $experience_level
 * @property string|null $gender_identity
 * @property string|null $metabolic_sex
 * @property int|null $age_at_calculation
 * @property string|null $activity_level
 * @property float|null $activity_factor
 * @property int|null $bmr
 * @property int|null $tdee
 * @property string $source
 * @property string $status
 * @property bool $is_manual_override
 * @property array|null $warnings
 * @property string|null $explanation
 * @property bool $is_active
 */
class NutritionGoal extends Model
{
    protected $fillable = [
        'member_id', 'daily_calories', 'protein_g', 'carbs_g', 'fat_g',
        'goal_type', 'objective', 'experience_level', 'gender_identity',
        'metabolic_sex', 'age_at_calculation', 'birthdate', 'weight_kg',
        'height_cm', 'target_weight_kg', 'activity_level', 'activity_factor',
        'training_days_per_week', 'training_type', 'pace', 'formula',
        'formula_version', 'bmr', 'tdee', 'fiber_g', 'source', 'status',
        'is_manual_override', 'warnings', 'explanation', 'calculated_at',
        'is_active',
    ];

    protected $casts = [
        'daily_calories' => 'integer',
        'protein_g' => 'integer',
        'carbs_g' => 'integer',
        'fat_g' => 'integer',
        'age_at_calculation' => 'integer',
        'birthdate' => 'date:Y-m-d',
        'weight_kg' => 'float',
        'height_cm' => 'float',
        'target_weight_kg' => 'float',
        'activity_factor' => 'float',
        'training_days_per_week' => 'integer',
        'bmr' => 'integer',
        'tdee' => 'integer',
        'fiber_g' => 'integer',
        'is_manual_override' => 'boolean',
        'warnings' => 'array',
        'calculated_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Payload público que consume la app. Mantiene las llaves históricas
     * (daily_calories/protein_g/carbs_g/fat_g/goal_type) para no romper la UI
     * actual, y agrega el detalle del cálculo + estado.
     */
    public function toPublicArray(): array
    {
        return [
            'daily_calories' => $this->daily_calories,
            'protein_g' => $this->protein_g,
            'carbs_g' => $this->carbs_g,
            'fat_g' => $this->fat_g,
            'fiber_g' => $this->fiber_g,
            'goal_type' => $this->goal_type,
            'objective' => $this->objective,
            'experience_level' => $this->experience_level,
            'gender_identity' => $this->gender_identity,
            'metabolic_sex' => $this->metabolic_sex,
            'age_at_calculation' => $this->age_at_calculation,
            'weight_kg' => $this->weight_kg,
            'height_cm' => $this->height_cm,
            'target_weight_kg' => $this->target_weight_kg,
            'activity_level' => $this->activity_level,
            'activity_factor' => $this->activity_factor,
            'training_days_per_week' => $this->training_days_per_week,
            'pace' => $this->pace,
            'bmr' => $this->bmr,
            'tdee' => $this->tdee,
            'formula' => $this->formula,
            'formula_version' => $this->formula_version,
            'source' => $this->source,
            'status' => $this->status ?: 'manual',
            'is_manual_override' => (bool) $this->is_manual_override,
            'warnings' => $this->warnings ?? [],
            'explanation' => $this->explanation,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
        ];
    }
}
