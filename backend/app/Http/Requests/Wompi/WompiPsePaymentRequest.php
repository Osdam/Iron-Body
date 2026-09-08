<?php

namespace App\Http\Requests\Wompi;

/**
 * PSE es el único método cuyo formulario pide datos del PAGADOR, y por una
 * razón: quien autoriza en el banco no tiene por qué ser el titular del perfil
 * —un familiar, la empresa que paga la membresía—, y PSE valida el documento
 * contra el titular de la cuenta bancaria. Por eso aquí se aceptan correo y
 * teléfono del checkout; en el resto de métodos no existen y siguen saliendo
 * del miembro autenticado.
 */
class WompiPsePaymentRequest extends AbstractWompiPaymentRequest
{
    protected function methodRules(): array
    {
        return [
            'financial_institution_code' => 'required|string|max:10',
            'user_type' => 'nullable|in:0,1,natural,juridica,business',
            'user_legal_id_type' => 'nullable|string|max:5',
            'user_legal_id' => 'nullable|string|max:40',

            // Datos de contacto del PAGADOR para esta transacción. Vacíos o
            // ausentes, se cae al perfil del miembro (ver `payPse`).
            'customer_email' => 'nullable|email:rfc|max:160',
            'customer_phone' => 'nullable|string|min:7|max:20',
        ];
    }
}
