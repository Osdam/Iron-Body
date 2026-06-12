<?php

namespace App\Http\Requests\Wompi;

class WompiDaviplataPaymentRequest extends AbstractWompiPaymentRequest
{
    protected function methodRules(): array
    {
        return [
            'user_legal_id'      => 'required|string|max:40',
            'user_legal_id_type' => 'nullable|string|max:5',
        ];
    }
}
