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
            // Metadata de normalización cross-platform (opcional, no rompe
            // clientes viejos). Permite versionar la referencia desde el enrol.
            'normalizer_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:20'],
            'camera' => ['nullable', 'string', 'max:20'],
            'image_width' => ['nullable', 'integer', 'min:0'],
            'image_height' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
