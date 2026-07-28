<?php

namespace App\Http\Requests\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Texto de una apelación. El miembro y la acción se resuelven en el servidor
 * (bearer + `public_id` con comprobación de pertenencia), nunca del body.
 */
class SubmitAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appeal_text' => [
                'required',
                'string',
                'min:10',
                'max:'.(int) config('ugc.appeal_text_max_length', 1000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'appeal_text.required' => 'Escribe por qué crees que la decisión fue incorrecta.',
            'appeal_text.min' => 'Cuéntanos un poco más para poder revisar tu caso.',
            'appeal_text.max' => 'El texto de la apelación es demasiado largo.',
        ];
    }
}
