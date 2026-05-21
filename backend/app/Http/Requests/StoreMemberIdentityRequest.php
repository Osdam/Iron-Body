<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberIdentityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_number' => Member::normalizeDocumentNumber($this->input('document_number')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['nullable', 'string', Rule::in(['CC', 'TI', 'CE', 'PASAPORTE'])],
            'document_number' => ['required', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date'],
            'ocr_full_name' => ['nullable', 'string', 'max:255'],
            'ocr_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'identity_status' => ['required', Rule::in(['verified', 'needs_manual_review'])],
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'back' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ];
    }
}
