<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de POST /api/admin/billing/quote.
 *
 * El frontend solo puede pedir "cuánto vale ESTE plan/producto". No puede
 * enviar tarifas, bases, totales ni modos de cálculo: la autoridad financiera
 * es exclusivamente el backend.
 */
class BillingQuoteRequest extends FormRequest
{
    /** La autorización real la aplica el middleware auth.admin de la ruta. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', Rule::in(['plan', 'product'])],
            'source_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_type.in' => 'source_type debe ser "plan" o "product".',
            'quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }
}
