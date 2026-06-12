<?php

namespace App\Http\Requests\Wompi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas comunes a todo inicio de pago Wompi. La autenticación la garantiza el
 * middleware `auth.member`; el sujeto (member/user) se toma del miembro
 * autenticado, NO del body (anti suplantación). El monto es autoritativo del
 * backend: `amount` solo se acepta como referencia, jamás como verdad.
 *
 * Los DOS consentimientos de Wompi son OBLIGATORIOS: sin ambos no se puede pagar.
 */
abstract class AbstractWompiPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth.member ya validó; ownership se aplica en el controller
    }

    /** Reglas específicas del método concreto. */
    abstract protected function methodRules(): array;

    public function rules(): array
    {
        return array_merge([
            'plan_id'           => 'nullable|integer|exists:plans,id',
            'order_id'          => 'nullable|integer',
            'purpose'           => 'nullable|string|in:membership,store',
            // Solo referencia visual: el backend recalcula el precio real.
            'amount'            => 'nullable|numeric|min:1',
            'currency'          => 'nullable|string|size:3',
            'description'       => 'nullable|string|max:160',
            // Idempotencia desde la app (doble toque / reintento de red).
            'client_request_id' => 'nullable|string|max:120',
            // Consentimientos Wompi (ambos obligatorios).
            'accepted_terms'          => 'required|accepted',
            'accepted_personal_data'  => 'required|accepted',
        ], $this->methodRules());
    }

    public function messages(): array
    {
        return [
            'accepted_terms.accepted'         => 'Debes aceptar los términos y condiciones para continuar.',
            'accepted_personal_data.accepted' => 'Debes autorizar el tratamiento de tus datos personales para continuar.',
        ];
    }
}
