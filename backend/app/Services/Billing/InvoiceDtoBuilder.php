<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;

/**
 * Construye el DTO de facturación a partir de la fuente (pago o venta) y los
 * datos fiscales ya resueltos (FiscalProfileResolver).
 *
 * Devuelve un arreglo con DOS partes:
 *   - 'snapshot': datos a persistir en electronic_invoices (montos + customer).
 *   - 'payload' : cuerpo para la API de Factus V2.
 *
 * El cálculo de base/IVA respeta price_includes_tax del plan/producto: si el
 * precio ya incluye IVA, se desglosa hacia atrás; si no, se suma. Las CLAVES
 * exactas del payload Factus deben confirmarse contra la colección oficial; al
 * confirmarlas se ajustan SOLO aquí.
 */
class InvoiceDtoBuilder
{
    /**
     * @param  array<string,mixed>  $customer  Salida de FiscalProfileResolver.
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>}
     */
    public function forPayment(Payment $payment, array $customer): array
    {
        $plan = $payment->plan_id ? Plan::with('taxRate')->find($payment->plan_id) : null;
        $rate = $plan?->taxRate;
        $includesTax = $plan ? (bool) $plan->price_includes_tax : true;

        $gross = (float) $payment->amount;
        [$base, $tax] = $this->splitTax($gross, $rate?->factor() ?? 0.0, $includesTax);

        $description = $plan ? 'Membresía ' . $plan->name . ' - Iron Body' : 'Pago Iron Body';

        $line = [
            'name'        => $description,
            'quantity'    => 1,
            'unit_price'  => round($base, 2),
            'discount'    => 0,
            'tax_rate'    => $rate?->rate !== null ? (float) $rate->rate : 0,
            'tribute_id'  => $rate?->factus_tribute_id ?? config('billing.defaults.tribute_id'),
            'tax_amount'  => round($tax, 2),
            'unspsc_code' => $plan?->unspsc_code,
            'total'       => round($base + $tax, 2),
        ];

        return $this->assemble($customer, [$line], 0.0);
    }

    /**
     * @param  array<string,mixed>  $customer
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>}
     */
    public function forSale(ProductSale $sale, array $customer): array
    {
        $sale->loadMissing('items.product.taxRate');
        $lines = [];

        foreach ($sale->items as $item) {
            $product = $item->product;
            $rate = $product?->taxRate;
            $includesTax = $product ? (bool) $product->price_includes_tax : true;

            $gross = (float) $item->unit_price * (int) $item->quantity;
            [$base, $tax] = $this->splitTax($gross, $rate?->factor() ?? 0.0, $includesTax);

            $lines[] = [
                'name'        => $item->name,
                'quantity'    => (int) $item->quantity,
                'unit_price'  => round($base / max(1, (int) $item->quantity), 2),
                'discount'    => 0,
                'tax_rate'    => $rate?->rate !== null ? (float) $rate->rate : 0,
                'tribute_id'  => $rate?->factus_tribute_id ?? config('billing.defaults.tribute_id'),
                'tax_amount'  => round($tax, 2),
                'unspsc_code' => $product?->unspsc_code,
                'total'       => round($base + $tax, 2),
            ];
        }

        return $this->assemble($customer, $lines, (float) $sale->discount);
    }

    /**
     * Desglosa (o suma) el IVA.
     * @return array{0: float, 1: float} [base, impuesto]
     */
    private function splitTax(float $gross, float $factor, bool $includesTax): array
    {
        if ($factor <= 0) {
            return [$gross, 0.0];
        }
        if ($includesTax) {
            $base = $gross / (1 + $factor);
            return [$base, $gross - $base];
        }

        return [$gross, $gross * $factor];
    }

    /**
     * @param  array<string,mixed>  $customer
     * @param  array<int,array<string,mixed>>  $lines
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>}
     */
    private function assemble(array $customer, array $lines, float $discount): array
    {
        $subtotal = array_sum(array_map(static fn ($l) => $l['unit_price'] * $l['quantity'], $lines));
        $taxTotal = array_sum(array_map(static fn ($l) => $l['tax_amount'], $lines));
        $total    = round($subtotal + $taxTotal - $discount, 2);

        $snapshot = [
            'customer_doc_type'        => $customer['doc_type'] ?? null,
            'customer_doc_number'      => $customer['doc_number'] ?? null,
            'customer_dv'              => $customer['dv'] ?? null,
            'customer_name'            => $customer['name'] ?? null,
            'customer_email'           => $customer['email'] ?? null,
            'customer_phone'           => $customer['phone'] ?? null,
            'customer_address'         => $customer['address'] ?? null,
            'customer_city_code'       => $customer['city_code'] ?? null,
            'customer_department_code' => $customer['department_code'] ?? null,
            'is_final_consumer'        => (bool) ($customer['is_final_consumer'] ?? false),
            'currency'                 => config('billing.defaults.currency', 'COP'),
            'subtotal'                 => round($subtotal, 2),
            'discount'                 => round($discount, 2),
            'tax_total'                => round($taxTotal, 2),
            'total'                    => $total,
        ];

        $payload = [
            'numbering_range_id' => config('billing.numbering.range_id'),
            'reference_code'     => null, // lo fija InvoicingService (uuid de la factura)
            'payment_method_code' => config('billing.defaults.payment_method_code'),
            'customer' => [
                'identification'        => $customer['doc_number'] ?? null,
                'identification_document_id' => $customer['doc_type'] ?? null,
                'dv'                    => $customer['dv'] ?? null,
                'names'                 => $customer['name'] ?? null,
                'legal_organization_id' => ($customer['is_final_consumer'] ?? false) ? null : ($customer['legal_name'] ? 2 : 1),
                'email'                 => $customer['email'] ?? null,
                'phone'                 => $customer['phone'] ?? null,
                'address'               => $customer['address'] ?? null,
                'municipality_id'       => $customer['city_code'] ?? null,
            ],
            'items' => array_map(static function (array $l) {
                return [
                    'name'              => $l['name'],
                    'quantity'          => $l['quantity'],
                    'price'             => $l['unit_price'],
                    'discount_rate'     => 0,
                    'tax_rate'          => $l['tax_rate'],
                    'tribute_id'        => $l['tribute_id'],
                    'standard_code_id'  => $l['unspsc_code'],
                ];
            }, $lines),
        ];

        return ['snapshot' => $snapshot, 'payload' => $payload];
    }
}
