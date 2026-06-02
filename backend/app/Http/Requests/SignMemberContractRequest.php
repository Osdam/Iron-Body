<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SignMemberContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la propiedad/ownership se valida en el controlador
    }

    public function rules(): array
    {
        return [
            // Firma: archivo PNG/JPG (multipart) O base64 (data URI / crudo).
            'signature'       => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
            'signature_image' => ['nullable', 'string', 'max:5000000'],

            // Aceptación explícita de checkboxes.
            'acceptance'      => ['required', 'array'],
            'acceptance.*'    => ['boolean'],

            // Datos del usuario (se confirman/actualizan al firmar).
            'full_name'       => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:50'],
            'birth_date'      => ['nullable', 'date'],
            'rh'              => ['nullable', 'string', 'max:10'],
            'address'         => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:255'],

            // Datos médicos (sensibles).
            'medical_notes'   => ['nullable', 'string', 'max:2000'],
            'injuries'        => ['nullable', 'string', 'max:2000'],

            // Acudiente (solo menor).
            'guardian_full_name'       => ['nullable', 'string', 'max:255'],
            'guardian_document_number' => ['nullable', 'string', 'max:50'],
            'guardian_document_city'   => ['nullable', 'string', 'max:120'],
            'guardian_phone'           => ['nullable', 'string', 'max:30'],
            'guardian_address'         => ['nullable', 'string', 'max:255'],
            'guardian_city'            => ['nullable', 'string', 'max:120'],
            'guardian_relationship'    => ['nullable', 'string', 'max:80'],
            'minor_full_name'          => ['nullable', 'string', 'max:255'],
            'minor_document_number'    => ['nullable', 'string', 'max:50'],
            'sign_city'                => ['nullable', 'string', 'max:120'],

            // Metadatos de trazabilidad (no sensibles).
            'device_id'    => ['nullable', 'string', 'max:128'],
            'app_platform' => ['nullable', 'string', 'max:40'],
            'app_version'  => ['nullable', 'string', 'max:40'],
            'locale'       => ['nullable', 'string', 'max:10'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasFile('signature') && blank($this->input('signature_image'))) {
                $validator->errors()->add('signature', 'La firma es obligatoria (archivo PNG o imagen base64).');
            }
        });
    }
}
