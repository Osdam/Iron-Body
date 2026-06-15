<?php

namespace App\Http\Requests\Trainer;

/**
 * Igual que crear/editar borrador, pero la corrección EXIGE un motivo (queda en
 * la valoración y en la auditoría). Reutiliza las reglas de medidas.
 */
class AmendProfessionalAssessmentRequest extends StoreProfessionalAssessmentRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'amendment_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
    }
}
