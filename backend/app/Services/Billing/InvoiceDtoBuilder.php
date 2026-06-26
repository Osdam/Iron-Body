<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;

/**
 * Construye el DTO de facturación Factus V2 a partir de la fuente (pago o venta)
 * y los datos fiscales ya resueltos (FiscalProfileResolver).
 *
 * Devuelve:
 *   - 'snapshot': datos a persistir en electronic_invoices (montos + customer).
 *   - 'payload' : cuerpo EXACTO para POST /v2/bills/validate (estructura oficial
 *                 confirmada contra docs/factus/factus-v2.postman_collection.json).
 *
 * Reglas del payload V2:
 *   - Montos como string; payment_form entero; price unitario SIN IVA.
 *   - customer con sufijos *_code; natural → names; jurídica → company+trade_name.
 *   - items.taxes[] = [{code, rate}] o [{is_excluded:true}] si tarifa 0/excluida.
 *   - El reference_code raíz lo fija InvoicingService/Job (uuid de la factura).
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

        $name = $plan ? 'Membresía ' . $plan->name . ' - Iron Body' : 'Pago Iron Body';

        $line = $this->line(
            codeReference: $plan ? 'PLAN-' . $plan->id : 'PAGO',
            name: $name,
            quantity: 1,
            unitBase: round($base, 2),
            taxAmount: round($tax, 2),
            taxRate: $rate?->rate !== null ? (float) $rate->rate : null,
            taxCode: $rate?->factus_tribute_id,
            unspsc: $plan?->unspsc_code,
        );

        return $this->assemble($customer, [$line], 0.0, $name);
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

            $qty = max(1, (int) $item->quantity);
            $gross = (float) $item->unit_price * $qty;
            [$base, $tax] = $this->splitTax($gross, $rate?->factor() ?? 0.0, $includesTax);

            $lines[] = $this->line(
                codeReference: $product ? 'PROD-' . $product->id : 'ITEM',
                name: (string) $item->name,
                quantity: $qty,
                unitBase: round($base / $qty, 2),
                taxAmount: round($tax, 2),
                taxRate: $rate?->rate !== null ? (float) $rate->rate : null,
                taxCode: $rate?->factus_tribute_id,
                unspsc: $product?->unspsc_code,
            );
        }

        return $this->assemble($customer, $lines, (float) $sale->discount, 'Venta Iron Body');
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

    /** Línea interna (base para snapshot de montos + item del payload). */
    private function line(
        string $codeReference,
        string $name,
        int $quantity,
        float $unitBase,
        float $taxAmount,
        ?float $taxRate,
        ?string $taxCode,
        ?string $unspsc,
    ): array {
        $d = (array) config('billing.defaults');
        $hasTax = $taxRate !== null && $taxRate > 0;

        return [
            'code_reference'    => $codeReference,
            'name'              => $name,
            'quantity'          => $quantity,
            'unit_base'         => $unitBase,
            'tax_amount'        => $taxAmount,
            'tax_rate'          => $taxRate,
            // Item del payload Factus V2.
            'payload' => [
                'code_reference'   => $codeReference,
                'name'             => $name,
                'quantity'         => $this->num($quantity),
                'discount_rate'    => '0.00',
                'price'            => $this->num($unitBase),
                'unit_measure_code' => (string) ($d['unit_measure_code'] ?? '94'),
                'standard_code'    => $unspsc ?: (string) ($d['standard_code'] ?? '999'),
                'taxes'            => $hasTax
                    ? [['code' => $taxCode ?: (string) ($d['tax_code'] ?? '01'), 'rate' => $this->num($taxRate)]]
                    : [['is_excluded' => true]],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $customer
     * @param  array<int,array<string,mixed>>  $lines
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>}
     */
    private function assemble(array $customer, array $lines, float $discount, string $observation): array
    {
        $d = (array) config('billing.defaults');

        $subtotal = array_sum(array_map(static fn ($l) => $l['unit_base'] * $l['quantity'], $lines));
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
            'currency'                 => (string) ($d['currency'] ?? 'COP'),
            'subtotal'                 => round($subtotal, 2),
            'discount'                 => round($discount, 2),
            'tax_total'                => round($taxTotal, 2),
            'total'                    => $total,
        ];

        $payload = [
            'document'             => (string) ($d['document'] ?? '01'),
            'operation_type'       => (string) ($d['operation_type'] ?? '10'),
            'numbering_range_id'   => (int) config('billing.numbering.range_id'),
            'send_email'           => $this->shouldSendEmail($customer),
            'observation'          => $observation,
            'cash_rounding_amount' => '0.00',
            'payment_details'      => [[
                'payment_form'        => (int) ($d['payment_form'] ?? 1),
                'payment_method_code' => (string) ($d['payment_method_code'] ?? '10'),
                'amount'              => $this->num($total),
            ]],
            'customer' => $this->customer($customer, $d),
            'items'    => array_map(static fn ($l) => $l['payload'], $lines),
        ];

        return ['snapshot' => $snapshot, 'payload' => $payload];
    }

    /**
     * Decide si se solicita a Factus el envío del comprobante al correo del cliente.
     * Solo true si el flag FACTUS_SEND_EMAIL está activo Y el cliente tiene un
     * email válido. Sin email válido => false, pero la factura se emite igual.
     */
    private function shouldSendEmail(array $customer): bool
    {
        if (! filter_var(config('billing.send_email', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return self::hasValidEmail($customer['email'] ?? null);
    }

    /** Validación segura de email (consumidor final puede no traer email). */
    public static function hasValidEmail(mixed $email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Construye el bloque customer V2 (natural vs jurídica). */
    private function customer(array $c, array $d): array
    {
        $juridica = ($c['person_type'] ?? null) === 'juridica';
        $legalOrg = $juridica ? '1' : (string) ($d['legal_organization_code'] ?? '2');

        $out = [
            'identification_document_code' => $this->docCode($c['doc_type'] ?? null),
            'identification'               => (string) ($c['doc_number'] ?? ''),
            'legal_organization_code'      => $legalOrg,
            'tribute_code'                 => (string) ($d['tribute_code'] ?? 'ZZ'),
            'municipality_code'            => $c['city_code'] ?: ($d['municipality_code'] ?? null),
        ];

        if ($juridica) {
            $out['company']    = (string) ($c['legal_name'] ?? $c['name'] ?? '');
            $out['trade_name'] = (string) ($c['legal_name'] ?? $c['name'] ?? '');
        } else {
            $out['names'] = (string) ($c['name'] ?? '');
        }

        if (! empty($c['dv'])) {
            $out['dv'] = (string) $c['dv'];
        }
        foreach (['address', 'email', 'phone'] as $k) {
            if (! empty($c[$k])) {
                $out[$k] = (string) $c[$k];
            }
        }

        return $out;
    }

    /** Traduce el tipo de documento interno (CC/NIT…) a código DIAN/Factus. */
    private function docCode(?string $docType): string
    {
        if ($docType === null || $docType === '') {
            return '';
        }
        if (ctype_digit($docType)) {
            return $docType; // ya es código
        }
        $map = (array) config('billing.document_type_map', []);

        return (string) ($map[strtoupper($docType)] ?? $docType);
    }

    /** Formatea número como string con 2 decimales (formato Factus). */
    private function num(float|int $n): string
    {
        return number_format((float) $n, 2, '.', '');
    }
}
