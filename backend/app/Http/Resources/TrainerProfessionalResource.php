<?php

namespace App\Http\Resources;

use App\Models\Trainer;
use App\Services\Trainer\TrainerFaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista profesional de un entrenador para el CRM y el bootstrap del portal.
 * Expone roles, permisos efectivos, sede e identidad. No incluye datos
 * sensibles del documento (solo si está enlazado a una identidad).
 *
 * @mixin Trainer
 */
class TrainerProfessionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'contract_type' => $this->contract_type,
            'location' => $this->location,
            'main_specialty' => $this->main_specialty,
            'specialties' => $this->specialties ?? [],
            'identity_id' => $this->identity_id,
            'roles' => $this->roleNames(),
            'permissions' => $this->permissions(),
            // ¿Ya tiene rostro enrolado? El portal oculta "Configurar mi rostro"
            // y el CRM habilita el botón de borrar rostro según esto.
            'face_enrolled' => app(TrainerFaceService::class)->hasReference($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
