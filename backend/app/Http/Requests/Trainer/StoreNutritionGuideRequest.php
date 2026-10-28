<?php

namespace App\Http\Requests\Trainer;

use App\Services\Trainer\NutritionGuideService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del contenido de una guía nutricional (crear/editar borrador y
 * corregir).
 *
 * Todo es opcional aquí a propósito: un borrador a medias es trabajo en curso
 * legítimo y exigirle el documento completo desde la primera pulsación
 * obligaría al entrenador a rellenarlo de una sentada. Lo mínimo para que la
 * guía sirva —objetivo y al menos una comida— se exige al PUBLICAR, en
 * {@see NutritionGuideService}.
 *
 * El plan de comidas es una lista libre: ni el número ni los nombres están
 * fijados, porque no todos los socios hacen desayuno-almuerzo-cena.
 */
class StoreNutritionGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real (permiso + asignación) la aplican el middleware
        // `trainer.can` y TrainerMemberAccess en el controlador.
        return true;
    }

    public function rules(): array
    {
        return [
            'use_last_assessment' => ['nullable', 'boolean'],

            'objective' => ['nullable', 'string', 'max:180'],
            'objective_description' => ['nullable', 'string', 'max:2000'],
            'training_stage' => ['nullable', 'string', 'max:120'],

            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'body_fat_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'muscle_mass_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'visceral_fat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'basal_kcal' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:120'],

            // Tope de 50 comidas: una semana de Iron IA MAX son 7 días × hasta
            // 6 comidas (42), y el entrenador tiene que poder guardarla al
            // editarla. Por encima no es un plan, es un cliente repitiendo el
            // envío. Los textos, igual: caben la lista de mercado y los
            // totales de la semana con margen para lo que añada a mano
            // (IntegralPlanValidator::EXTENDED_LIMITS genera por debajo).
            'meals' => ['nullable', 'array', 'max:50'],
            'meals.*.label' => ['required_with:meals', 'string', 'max:80'],
            'meals.*.time' => ['nullable', 'string', 'max:20'],
            'meals.*.description' => ['nullable', 'string', 'max:2500'],
            'meals.*.order' => ['nullable', 'integer', 'min:0', 'max:100'],

            'recommendations' => ['nullable', 'string', 'max:10000'],
            'restrictions' => ['nullable', 'string', 'max:5000'],
            'supplements' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],

            'amendment_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
