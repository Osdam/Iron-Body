<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creación de nota crédito (anulación) sobre una factura validada. La razón es
 * obligatoria (trazabilidad / causal). El payload exacto de la NC en Factus se
 * confirma contra la doc oficial; aquí solo se captura la intención.
 */
class CreateCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
