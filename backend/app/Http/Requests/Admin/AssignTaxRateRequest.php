<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asignación de tarifa tributaria a un plan/producto (o en masa).
 * tax_rate_id nullable: null = dejar pendiente (sin decisión fiscal).
 */
class AssignTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
        ];
    }
}
