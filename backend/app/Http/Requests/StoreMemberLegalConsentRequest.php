<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMemberLegalConsentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $guardian = $this->input('guardian');

        if (is_array($guardian)) {
            $this->merge([
                'guardian_full_name' => $guardian['full_name'] ?? $guardian['guardian_full_name'] ?? $this->input('guardian_full_name'),
                'guardian_document_number' => $guardian['document_number'] ?? $guardian['guardian_document_number'] ?? $this->input('guardian_document_number'),
                'guardian_phone' => $guardian['phone'] ?? $guardian['guardian_phone'] ?? $this->input('guardian_phone'),
                'guardian_email' => $guardian['email'] ?? $guardian['guardian_email'] ?? $this->input('guardian_email'),
                'guardian_relationship' => $guardian['relationship'] ?? $guardian['guardian_relationship'] ?? $this->input('guardian_relationship'),
                'guardian_accepts_responsibility' => $guardian['accepts_responsibility'] ?? $guardian['guardian_accepts_responsibility'] ?? $this->input('guardian_accepts_responsibility'),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accepted_at' => ['nullable', 'date'],
            'contract_version' => ['nullable', 'string', 'max:80'],
            'terms_and_conditions' => ['required', 'accepted'],
            'data_processing' => ['required', 'accepted'],
            'truthfulness' => ['required', 'accepted'],
            'service_contract' => ['required', 'accepted'],
            'physical_risk_waiver' => ['required', 'accepted'],
            'guardian_authorization' => ['sometimes', 'boolean'],
            'guardian_full_name' => ['nullable', 'string', 'max:255'],
            'guardian_document_number' => ['nullable', 'string', 'max:50'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_relationship' => ['nullable', 'string', 'max:80'],
            'guardian_accepts_responsibility' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $member = $this->route('member');

            $hasGuardianData = collect([
                'guardian_full_name',
                'guardian_document_number',
                'guardian_phone',
                'guardian_email',
                'guardian_relationship',
            ])->contains(fn (string $field): bool => $this->filled($field));

            if ((! ($member instanceof Member) || ! $member->is_minor) && ! $hasGuardianData) {
                return;
            }

            foreach ([
                'guardian_full_name',
                'guardian_document_number',
                'guardian_phone',
                'guardian_email',
                'guardian_relationship',
            ] as $field) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, 'Este campo es obligatorio para menores de edad.');
                }
            }

            if (! $this->boolean('guardian_authorization')) {
                $validator->errors()->add('guardian_authorization', 'La autorizacion del acudiente es obligatoria para menores de edad.');
            }

            if (! $this->boolean('guardian_accepts_responsibility')) {
                $validator->errors()->add('guardian_accepts_responsibility', 'El acudiente debe aceptar la responsabilidad.');
            }
        });
    }
}
