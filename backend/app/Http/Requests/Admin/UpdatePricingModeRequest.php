<?php

namespace App\Http\Requests\Admin;

use App\Services\Billing\PricingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de la semántica del precio de un plan/producto.
 *
 * Solo se admiten los dos modos del enum: el frontend no puede introducir un
 * tercer comportamiento ni enviar tasas o totales propios.
 */
class UpdatePricingModeRequest extends FormRequest
{
    /** La autorización real la aplica el middleware auth.admin de la ruta. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pricing_mode' => ['required', 'string', Rule::in([
                PricingMode::LEGACY_INCLUSIVE->value,
                PricingMode::BASE_PLUS_TAX->value,
            ])],
            'billing_enabled' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pricing_mode.in' => 'pricing_mode debe ser "legacy_inclusive" o "base_plus_tax".',
        ];
    }
}
