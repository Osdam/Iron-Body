<?php

namespace App\Http\Requests\Trainer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del contenido de una valoración profesional (crear/editar borrador
 * y enmendar). Todas las medidas son opcionales pero, si llegan, deben ser
 * numéricas y no negativas: medidas tipadas, nunca texto libre.
 */
class StoreProfessionalAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real (permiso + asignación) la aplican el middleware
        // `trainer.can` y TrainerMemberAccess en el controlador.
        return true;
    }

    public function rules(): array
    {
        $measurement = ['nullable', 'numeric', 'min:0', 'max:999'];

        return [
            'weight_kg' => $measurement,
            'height_cm' => $measurement,
            'body_fat_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'muscle_mass_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'waist_cm' => $measurement,
            'hip_cm' => $measurement,
            'chest_cm' => $measurement,
            'arm_cm' => $measurement,
            'leg_cm' => $measurement,
            'observations' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
