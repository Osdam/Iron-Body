<?php

namespace App\Http\Resources;

use App\Models\NutritionGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación de una guía nutricional. La misma forma sirve al entrenador
 * (autor) y al socio (solo lectura): el socio no tiene endpoints de escritura,
 * así que ver estos datos nunca implica poder editarlos.
 *
 * Todo lo que sale de aquí lo escribió el ENTRENADOR. Iron IA no añade nada a
 * esta forma: su aporte viaja por otro camino y se rotula como tal, para que
 * nadie confunda una sugerencia con una indicación profesional.
 *
 * @mixin NutritionGuide
 */
class NutritionGuideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'version' => $this->version,
            'trainer_type' => $this->trainer_type,
            'trainer_id' => $this->trainer_id,
            'trainer_name' => $this->whenLoaded('trainer', fn () => $this->trainer?->full_name),

            'objective' => $this->objective,
            'objective_description' => $this->objective_description,
            'training_stage' => $this->training_stage,

            // Congeladas al publicar: no se leen de la valoración vigente.
            'anthropometrics' => [
                'weight_kg' => $this->weight_kg,
                'height_cm' => $this->height_cm,
                'body_fat_pct' => $this->body_fat_pct,
                'muscle_mass_pct' => $this->muscle_mass_pct,
                'visceral_fat' => $this->visceral_fat,
                'basal_kcal' => $this->basal_kcal,
                'age_years' => $this->age_years,
            ],

            'meals' => $this->orderedMeals(),
            'recommendations' => $this->recommendations,
            'restrictions' => $this->restrictions,
            'supplements' => $this->supplements,
            'notes' => $this->notes,

            'amendment_reason' => $this->amendment_reason,
            'void_reason' => $this->void_reason,
            'is_editable' => $this->isDraft(),
            'parent_uuid' => $this->whenLoaded('parent', fn () => $this->parent?->uuid),
            'source_assessment_uuid' => $this->whenLoaded('sourceAssessment', fn () => $this->sourceAssessment?->uuid),

            'published_at' => $this->published_at,
            'acknowledged_at' => $this->acknowledged_at,
            'created_at' => $this->created_at,
        ];
    }
}
