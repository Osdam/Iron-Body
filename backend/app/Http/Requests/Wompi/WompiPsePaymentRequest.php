<?php

namespace App\Http\Requests\Wompi;

class WompiPsePaymentRequest extends AbstractWompiPaymentRequest
{
    protected function methodRules(): array
    {
        return [
            'financial_institution_code' => 'required|string|max:10',
            'user_type'                  => 'nullable|in:0,1,natural,juridica,business',
            'user_legal_id_type'         => 'nullable|string|max:5',
            'user_legal_id'              => 'nullable|string|max:40',
        ];
    }
}
