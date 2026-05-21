<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class LoginMemberRequest extends FormRequest
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
            'document_number' => ['required', 'string', 'max:50'],
        ];
    }
}
