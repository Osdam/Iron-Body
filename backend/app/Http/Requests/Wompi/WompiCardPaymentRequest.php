<?php

namespace App\Http\Requests\Wompi;

/**
 * Pago con tarjeta. El backend recibe SOLO el token (tokenizado en Flutter con
 * la llave pública). Aquí NUNCA llegan PAN/CVC.
 */
class WompiCardPaymentRequest extends AbstractWompiPaymentRequest
{
    protected function methodRules(): array
    {
        return [
            'card_token'   => 'required|string|min:10|max:120',
            'installments' => 'nullable|integer|min:1|max:36',
        ];
    }
}
