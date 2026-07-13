<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de suscripción con pago automático. La autenticación la garantiza
 * `auth.member`; el sujeto (member/user) se toma del miembro autenticado, NO del
 * body (anti suplantación). El monto y la duración son AUTORITATIVOS del backend:
 * `amount`/`interval_days` NO se aceptan desde el cliente (se ignoran si llegan).
 *
 * Los dos consentimientos de Wompi son obligatorios. El método permitido lo
 * valida además el servicio (solo tarjeta por ahora).
 */
class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth.member ya validó; ownership se fija en el controller.
    }

    public function rules(): array
    {
        return [
            'plan_id'           => 'required|integer|exists:plans,id',
            // Tipo de fuente. El servicio rechaza cualquiera que no sea tarjeta
            // (PSE/DaviPlata/Bancolombia no admiten cobro desatendido).
            'type'              => 'nullable|string|max:30',
            // Token de tarjeta (tok_...) O una fuente existente del miembro.
            'token'             => 'required_without:payment_source_id|nullable|string|max:200',
            'payment_source_id' => 'nullable|integer',
            // Datos NO sensibles de la tarjeta (marca/últimos 4/expiración).
            'card_brand'        => 'nullable|string|max:30',
            'card_last_four'    => 'nullable|string|max:4',
            'exp_month'         => 'nullable|string|max:2',
            'exp_year'          => 'nullable|string|max:4',
            // Consentimientos Wompi (ambos obligatorios).
            'accepted_terms'         => 'required|accepted',
            'accepted_personal_data' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'accepted_terms.accepted'         => 'Debes aceptar los términos y condiciones para continuar.',
            'accepted_personal_data.accepted' => 'Debes autorizar el tratamiento de tus datos personales para continuar.',
            'token.required_without'          => 'Falta el método de pago.',
        ];
    }
}
