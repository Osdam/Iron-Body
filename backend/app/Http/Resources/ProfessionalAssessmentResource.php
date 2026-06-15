<?php

namespace App\Http\Resources;

use App\Models\ProfessionalAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación de una valoración profesional. La misma forma sirve al
 * entrenador (autor) y al miembro (solo lectura): el miembro no tiene endpoints
 * de escritura, así que ver estos datos nunca implica poder editarlos.
 *
 * @mixin ProfessionalAssessment
 */
class ProfessionalAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'version' => $this->version,
            'trainer_type' => $this->trainer_type,
            'location' => $this->location,
            'trainer_id' => $this->trainer_id,
            'trainer_name' => $this->whenLoaded('trainer', fn () => $this->trainer?->full_name),
            'measurements' => [
                'weight_kg' => $this->weight_kg,
                'height_cm' => $this->height_cm,
                'body_fat_pct' => $this->body_fat_pct,
                'muscle_mass_pct' => $this->muscle_mass_pct,
                'waist_cm' => $this->waist_cm,
                'hip_cm' => $this->hip_cm,
                'chest_cm' => $this->chest_cm,
                'arm_cm' => $this->arm_cm,
                'leg_cm' => $this->leg_cm,
            ],
            'observations' => $this->observations,
            'recommendations' => $this->recommendations,
            'amendment_reason' => $this->amendment_reason,
            'is_editable' => $this->isDraft(),
            'parent_uuid' => $this->whenLoaded('parent', fn () => $this->parent?->uuid),
            'submitted_at' => $this->submitted_at,
            'acknowledged_at' => $this->acknowledged_at,
            'created_at' => $this->created_at,
        ];
    }
}
