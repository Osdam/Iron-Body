<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class RegisterMemberRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'document_number' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:40'],
            'goal' => ['nullable', 'string', 'max:120'],
            'training_level' => ['nullable', 'string', 'max:80'],
            'injuries' => ['nullable', 'string', 'max:2000'],
            'birth_date' => ['nullable', 'date'],
            'is_minor' => ['sometimes', 'boolean'],
        ];
    }
}
