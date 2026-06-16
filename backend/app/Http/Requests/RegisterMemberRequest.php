<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_number' => Member::normalizeDocumentNumber($this->input('document_number')),
            // Normaliza el teléfono a solo dígitos (quita +57/espacios) ANTES de
            // validar: evita datos corruptos por copy/paste y compara igual.
            'phone' => Member::normalizePhone($this->input('phone')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'document_number' => ['required', 'string', 'max:50'],
            // Celular colombiano: exactamente 10 dígitos y empieza por 3.
            'phone' => ['required', 'string', 'regex:/^3\d{9}$/'],
            // Género obligatorio y dentro del conjunto válido ("Seleccionar" no
            // es un valor: la app no debe enviarlo).
            'gender' => ['required', 'string', Rule::in(Member::GENDERS)],
            'goal' => ['nullable', 'string', 'max:120'],
            'training_level' => ['nullable', 'string', 'max:80'],
            'injuries' => ['nullable', 'string', 'max:2000'],
            'birth_date' => ['nullable', 'date'],
            'is_minor' => ['sometimes', 'boolean'],
            // Intención de biometría (opcional, Apple). Validación clara (no 500).
            'biometric_status' => ['sometimes', 'nullable', 'in:pending,registered,skipped,manual_required'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.regex' => 'El celular debe tener 10 dígitos y empezar por 3.',
            'gender.required' => 'Selecciona un género.',
            'gender.in' => 'Selecciona un género válido.',
        ];
    }
}
