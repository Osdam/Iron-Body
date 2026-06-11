<?php

namespace App\Http\Requests\Nutrition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos que la app puede enviar para calcular/guardar la meta
 * nutricional. TODOS son opcionales: lo que no venga se resuelve desde el perfil
 * + la última evaluación física. Las reglas de NEGOCIO (rangos realistas, pisos
 * de seguridad) viven en NutritionGoalService/config, no aquí: estas son solo
 * guardas de formato/sanidad.
 *
 * La autorización del miembro la hace el middleware auth.member (no aquí).
 */
class NutritionGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'metabolic_sex'   => ['nullable', 'in:male,female,unspecified'],
            'birthdate'       => ['nullable', 'date', 'before:today'],
            'age'             => ['nullable', 'integer', 'min:10', 'max:120'],
            'weight_kg'       => ['nullable', 'numeric', 'min:20', 'max:400'],
            'height_cm'       => ['nullable', 'numeric', 'min:80', 'max:260'],
            'objective'       => ['nullable', 'string', 'max:40'],
            'experience_level' => ['nullable', 'in:beginner,intermediate,advanced'],
            'activity_level'  => ['nullable', 'in:sedentary,light,moderate,very_active,athlete'],
            'activity_factor' => ['nullable', 'numeric', 'min:1', 'max:2.5'],
            'training_days_per_week' => ['nullable', 'integer', 'min:0', 'max:7'],
            'training_type'   => ['nullable', 'string', 'max:40'],
            'target_weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'pace'            => ['nullable', 'in:conservative,moderate,aggressive'],
        ];
    }

    /** Solo los overrides presentes (sin nulls) para no pisar datos del perfil. */
    public function overrides(): array
    {
        return array_filter(
            $this->only(array_keys($this->rules())),
            static fn ($v) => $v !== null && $v !== '',
        );
    }
}
