<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMemberSignatureRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('signature_kind') && ! $this->filled('kind')) {
            $this->merge(['kind' => $this->input('signature_kind')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['drawn', 'uploadedImage', 'uploadedPdf'])],
            'signature' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('signature');

            if (! $file || ! $this->filled('kind')) {
                return;
            }

            $extension = strtolower($file->getClientOriginalExtension());

            if ($this->input('kind') === 'uploadedPdf' && $extension !== 'pdf') {
                $validator->errors()->add('signature', 'La firma uploadedPdf debe enviarse como PDF.');
            }

            if (in_array($this->input('kind'), ['drawn', 'uploadedImage'], true) && ! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                $validator->errors()->add('signature', 'La firma dibujada o en imagen debe ser jpg, jpeg o png.');
            }
        });
    }
}
