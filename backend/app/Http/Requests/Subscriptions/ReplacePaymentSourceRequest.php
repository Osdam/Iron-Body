<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reemplazo de la fuente de pago de una suscripción (solo tarjeta). El sujeto se
 * toma del miembro autenticado (ownership en el controller). Nunca llega PAN/CVC:
 * solo el token de Wompi + datos NO sensibles. Consentimientos obligatorios
 * (el usuario autoriza un nuevo medio de cobro recurrente).
 */
class ReplacePaymentSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth.member ya validó; ownership se aplica en el controller.
    }

    public function rules(): array
    {
        return [
            'type'           => 'nullable|string|max:30',
            'token'          => 'required|string|max:200',
            'card_brand'     => 'nullable|string|max:30',
            'card_last_four' => 'nullable|string|max:4',
            'exp_month'      => 'nullable|string|max:2',
            'exp_year'       => 'nullable|string|max:4',
            'accepted_terms'         => 'required|accepted',
            'accepted_personal_data' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'                  => 'Falta el método de pago.',
            'accepted_terms.accepted'         => 'Debes aceptar los términos y condiciones para continuar.',
            'accepted_personal_data.accepted' => 'Debes autorizar el tratamiento de tus datos personales para continuar.',
        ];
    }
}
