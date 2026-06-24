<?php

namespace App\Http\Requests\Admin;

use App\Services\Billing\InvoicingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Emisión manual de una factura desde el CRM por source_type + source_id.
 * El source_type se restringe al mapa cerrado de InvoicingService (seguridad:
 * no se instancian clases arbitrarias). Autorización = capa /admin/* del CRM.
 */
class ManualEmitInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', Rule::in(array_keys(InvoicingService::SOURCE_MAP))],
            'source_id'   => ['required', 'integer', 'min:1'],
            'force'       => ['sometimes', 'boolean'],
        ];
    }
}
