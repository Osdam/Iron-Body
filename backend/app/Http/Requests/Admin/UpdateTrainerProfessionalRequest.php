<?php

namespace App\Http\Requests\Admin;

use App\Models\TrainerRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Actualización del perfil profesional de un entrenador desde el CRM: roles,
 * sede, tipo de contrato y especialidades. La autorización es la del CRM (capa
 * de red/front), igual que el resto de rutas `/admin/*`.
 */
class UpdateTrainerProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(TrainerRole::ALL)],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contract_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'main_specialty' => ['sometimes', 'nullable', 'string', 'max:120'],
            'specialties' => ['sometimes', 'nullable', 'array'],
            'specialties.*' => ['string', 'max:80'],
            'admin_id' => ['nullable', 'integer'],
        ];
    }
}
