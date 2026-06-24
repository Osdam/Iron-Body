<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta/edición del perfil fiscal de un usuario o miembro. NO bloquea pagos:
 * solo enriquece los datos para factura nominativa (si falta, se factura a
 * consumidor final). Autorización = capa /admin/* del CRM.
 */
class UpdateFiscalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_type'             => ['required', 'string', 'max:20'],
            'doc_number'           => ['required', 'string', 'max:50'],
            'dv'                   => ['nullable', 'string', 'max:2'],
            'person_type'          => ['nullable', Rule::in(['natural', 'juridica'])],
            'legal_name'           => ['nullable', 'string', 'max:255'],
            'tax_responsibilities' => ['nullable', 'array'],
            'tax_responsibilities.*' => ['string', 'max:60'],
            'email'                => ['nullable', 'email', 'max:255'],
            'phone'                => ['nullable', 'string', 'max:40'],
            'address'              => ['nullable', 'string', 'max:255'],
            'city_code'            => ['nullable', 'string', 'max:20'],
            'department_code'      => ['nullable', 'string', 'max:20'],
        ];
    }
}
