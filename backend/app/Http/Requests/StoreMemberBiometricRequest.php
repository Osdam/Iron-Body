<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberBiometricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'captured_at' => ['nullable', 'date'],
            'bytes_length' => ['nullable', 'integer', 'min:0'],
            'face' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ];
    }
}
