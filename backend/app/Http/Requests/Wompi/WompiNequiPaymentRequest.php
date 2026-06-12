<?php

namespace App\Http\Requests\Wompi;

class WompiNequiPaymentRequest extends AbstractWompiPaymentRequest
{
    protected function methodRules(): array
    {
        return [
            'phone' => 'required|string|min:10|max:13',
        ];
    }
}
