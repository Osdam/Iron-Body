<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;

/**
 * Construye el DTO de facturación Factus V2 a partir de la fuente (pago o venta)
 * y los datos fiscales ya resueltos (FiscalProfileResolver).
 *
 * Devuelve:
 *   - 'snapshot'   : datos a persistir en electronic_invoices (montos + customer).
 *   - 'payload'    : cuerpo EXACTO para POST /v2/bills/validate.
 *   - 'line_items' : desglose legible por línea para el CRM (sin datos sensibles).
 *
 * ── DE DÓNDE SALEN LOS IMPORTES (Pricing V2) ────────────────────────────────
 *
 * PRIORIDAD 1 — Snapshot financiero congelado (operaciones nuevas):
 *   Si el pago/venta trae `gross_amount` (y las líneas su desglose), se usan
 *   TAL CUAL. No se consulta Plan ni Product: el catálogo puede haber cambiado
 *   desde el cobro, la operación cobrada no cambia. Es lo que garantiza que
 *   total_cobrado == total_facturado por construcción.
 *
 * PRIORIDAD 2 — Fallback legacy (operaciones anteriores, snapshot NULL):
 *   Se conserva EXACTAMENTE el comportamiento histórico: el importe cobrado se
 *   trata como bruto y la base se extrae hacia atrás con la tarifa del catálogo.
 *   Es lo que mantiene las 8 facturas pending actuales en 67.226,89 + 12.773,11
 *   = 80.000 y no las convierte en 80.000 + IVA.
 *
 * Reglas del payload V2 (confirmadas contra docs/factus/factus-v2.postman_collection.json):
 *   - Montos como string; payment_form entero; `price` = unitario SIN IVA.
 *   - customer con sufijos *_code; natural → names; jurídica → company+trade_name.
 *   - items.taxes[] = [{code, rate}]. Sin tarifa efectiva se declara EXENTO:
 *     [{code:'01', rate:'0.00'}]. `is_excluded` NO se usa (ver TaxPolicy).
 *   - El reference_code raíz lo fija InvoicingService/Job (uuid de la factura).
 */
class InvoiceDtoBuilder
{
    public function __construct(private PricingService $pricing) {}

    /**
     * @param  array<string,mixed>  $customer  Salida de FiscalProfileResolver.
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>, line_items: array<int,array<string,mixed>>}
     */
    public function forPayment(Payment $payment, array $customer): array
    {
        $plan = $payment->plan_id ? Plan::with('taxRate')->find($payment->plan_id) : null;
        $name = $plan ? 'Membresía '.$plan->name.' - Iron Body' : 'Pago Iron Body';
        $codeReference = $plan ? 'PLAN-'.$plan->id : 'PAGO';

        $quote = $payment->hasFinancialSnapshot()
            ? $this->quoteFromPaymentSnapshot($payment)
            : $this->legacyQuoteForPayment($payment, $plan);

        $line = $this->line(
            codeReference: $codeReference,
            name: $name,
            quote: $quote,
            taxCode: $plan?->taxRate?->factus_tribute_id,
            unspsc: $plan?->unspsc_code,
        );

        return $this->assemble($customer, [$line], $name);
    }

    /**
     * @param  array<string,mixed>  $customer
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>, line_items: array<int,array<string,mixed>>}
     */
    public function forSale(ProductSale $sale, array $customer): array
    {
        $sale->loadMissing('items.product.taxRate');
        $lines = [];

        foreach ($sale->items as $item) {
            $product = $item->product;

            $quote = $item->hasFinancialSnapshot()
                ? $this->quoteFromItemSnapshot($item)
                : $this->legacyQuoteForItem($item, $sale);

            $lines[] = $this->line(
                codeReference: $product ? 'PROD-'.$product->id : 'ITEM',
                name: (string) $item->name,
                quote: $quote,
                taxCode: $product?->taxRate?->factus_tribute_id,
                unspsc: $product?->unspsc_code,
            );
        }

        return $this->assemble($customer, $lines, 'Venta Iron Body');
    }

    // ── Origen de los importes ──────────────────────────────────────────────

    /** Snapshot congelado del pago. No mira el catálogo. */
    private function quoteFromPaymentSnapshot(Payment $payment): PriceQuote
    {
        return $this->pricing->fromSnapshot([
            'base_amount' => $payment->base_amount,
            'tax_amount' => $payment->tax_amount,
            'gross_amount' => $payment->gross_amount,
            'discount_amount' => $payment->discount_amount,
            'tax_rate_id' => $payment->tax_rate_id,
            'tax_rate' => $payment->tax_rate,
            'pricing_mode' => $payment->pricing_mode,
            'pricing_rules_version' => $payment->pricing_rules_version,
            'priced_at' => $payment->priced_at,
            'currency' => $payment->currency ?? 'COP',
            'quantity' => 1,
        ], 1);
    }

    /** Snapshot congelado de la línea de venta. No mira el producto. */
    private function quoteFromItemSnapshot(ProductSaleItem $item): PriceQuote
    {
        return $this->pricing->fromSnapshot([
            'base_amount' => $item->base_amount,
            'tax_amount' => $item->tax_amount,
            'gross_amount' => $item->gross_amount,
            'discount_amount' => $this->itemDiscount($item),
            'tax_rate_id' => $item->tax_rate_id,
            'tax_rate' => $item->tax_rate,
            'pricing_mode' => $item->pricing_mode,
            'pricing_rules_version' => $item->pricing_rules_version,
            'currency' => 'COP',
            'quantity' => max(1, (int) $item->quantity),
        ], max(1, (int) $item->quantity));
    }

    /**
     * Descuento congelado de la línea: base + impuesto - bruto. Se deriva en vez
     * de almacenarse aparte para que la invariante del quote cuadre siempre.
     */
    private function itemDiscount(ProductSaleItem $item): string
    {
        $base = Money::fromAmount($item->base_amount);
        $tax = Money::fromAmount($item->tax_amount);
        $gross = Money::fromAmount($item->gross_amount);

        return $base->plus($tax)->minus($gross)->toDatabase();
    }

    /**
     * FALLBACK LEGACY para pagos anteriores a Pricing V2 (snapshot NULL).
     *
     * `payments.amount` es el bruto realmente cobrado, así que la base se extrae
     * hacia atrás. Es SIEMPRE inclusive, deliberadamente: un pago legacy nunca
     * sumó IVA por encima al cobrar, y tratarlo como base_plus_tax facturaría
     * más de lo recaudado. Esto es exactamente lo que mantiene las 8 facturas
     * pending en 67.226,89 + 12.773,11 = 80.000.
     *
     * Nota: aquí se ignora `price_includes_tax` del plan a propósito. Ese flag
     * podía ponerse en false desde el CRM y, en la implementación anterior, eso
     * bastaba para facturar 95.200 sobre un cobro de 80.000.
     */
    private function legacyQuoteForPayment(Payment $payment, ?Plan $plan): PriceQuote
    {
        return $this->pricing->quoteLegacyInclusive(
            Money::fromAmount($payment->amount),
            $plan?->taxRate,
        );
    }

    /**
     * FALLBACK LEGACY para líneas de venta anteriores a Pricing V2.
     * `unit_price * quantity` era el bruto de mostrador.
     */
    private function legacyQuoteForItem(ProductSaleItem $item, ProductSale $sale): PriceQuote
    {
        $qty = max(1, (int) $item->quantity);
        $unitGross = Money::fromAmount($item->unit_price);
        $rate = $item->product?->taxRate;

        // El descuento legacy vivía a nivel de venta: se reparte proporcionalmente
        // sobre las líneas para que la suma reconstruya el total cobrado.
        $discount = $this->legacyLineDiscount($item, $sale);

        return $this->pricing->quoteLegacyInclusive($unitGross, $rate, $qty, $discount);
    }

    /** Reparto proporcional del descuento de venta legacy sobre una línea. */
    private function legacyLineDiscount(ProductSaleItem $item, ProductSale $sale): Money
    {
        $saleDiscount = Money::fromAmount($sale->discount);
        if ($saleDiscount->isZero()) {
            return Money::zero();
        }

        $saleSubtotal = Money::fromAmount($sale->subtotal);
        if ($saleSubtotal->isZero()) {
            return Money::zero();
        }

        $lineSubtotal = Money::fromAmount($item->subtotal);

        // (descuento * subtotal_línea) / subtotal_venta, en aritmética entera.
        return Money::fromCents(
            intdiv($saleDiscount->cents() * $lineSubtotal->cents(), $saleSubtotal->cents())
        );
    }

    // ── Ensamblado ──────────────────────────────────────────────────────────

    /**
     * Línea interna: base para el snapshot de montos + item del payload Factus.
     *
     * @return array<string,mixed>
     */
    private function line(
        string $codeReference,
        string $name,
        PriceQuote $quote,
        ?string $taxCode,
        ?string $unspsc,
    ): array {
        $d = (array) config('billing.defaults');

        return [
            'code_reference' => $codeReference,
            'name' => $name,
            'quantity' => $quote->quantity,
            'base' => $quote->baseAmount,
            'tax' => $quote->taxAmount,
            'gross' => $quote->grossAmount,
            'discount' => $quote->discountAmount,
            'tax_rate' => $quote->taxRateFloat(),
            'pricing_mode' => $quote->pricingMode->value,

            // Item del payload Factus V2. `price` es SIEMPRE el unitario base.
            'payload' => [
                'code_reference' => $codeReference,
                'name' => $name,
                'quantity' => $this->num($quote->quantity),
                'discount_rate' => $this->discountRate($quote),
                'price' => $quote->unitBaseAmount->toDecimalString(),
                'unit_measure_code' => (string) ($d['unit_measure_code'] ?? '94'),
                'standard_code' => $unspsc ?: (string) ($d['standard_code'] ?? '999'),
                // Sin tarifa efectiva el ítem se declara EXENTO: IVA con tarifa
                // 0 %, nunca `is_excluded` (ver App\Services\Billing\TaxPolicy).
                'taxes' => $quote->hasTax()
                    ? [['code' => $taxCode ?: (string) ($d['tax_code'] ?? '01'), 'rate' => $quote->taxRateString()]]
                    : app(TaxPolicy::class)->itemTaxes($taxCode),
            ],
        ];
    }

    /**
     * Porcentaje de descuento de la línea, como lo espera Factus.
     *
     * Antes se enviaba SIEMPRE '0.00' aunque la venta tuviera descuento, con lo
     * que el total calculado por Factus no podía coincidir con el cobrado. Ahora
     * se expresa el descuento real sobre la base de la línea.
     */
    private function discountRate(PriceQuote $quote): string
    {
        if ($quote->discountAmount->isZero() || $quote->baseAmount->isZero()) {
            return '0.00';
        }

        $rate = ($quote->discountAmount->cents() * 10000) / $quote->baseAmount->cents();

        return number_format($rate / 100, 2, '.', '');
    }

    /**
     * @param  array<string,mixed>  $customer
     * @param  array<int,array<string,mixed>>  $lines
     * @return array{snapshot: array<string,mixed>, payload: array<string,mixed>, line_items: array<int,array<string,mixed>>}
     */
    private function assemble(array $customer, array $lines, string $observation): array
    {
        $d = (array) config('billing.defaults');

        $subtotal = Money::zero();
        $taxTotal = Money::zero();
        $discount = Money::zero();
        $total = Money::zero();

        foreach ($lines as $l) {
            $subtotal = $subtotal->plus($l['base']);
            $taxTotal = $taxTotal->plus($l['tax']);
            $discount = $discount->plus($l['discount']);
            $total = $total->plus($l['gross']);
        }

        // BARRERA TRIBUTARIA. Iron Body es responsabilidad 49 (no responsable
        // de IVA): ningún comprobante puede salir con IVA discriminado. Si un
        // dato viejo o un snapshot congelado con el modelo anterior colara un
        // importe > 0, se aborta aquí — antes de construir el payload y mucho
        // antes de cualquier POST. Ajustarlo en silencio emitiría un documento
        // legal que nadie podría explicar (es lo que produjo IBFE2–IBFE8).
        $taxPolicy = app(TaxPolicy::class);
        $taxPolicy->assertNoVat($taxTotal, 'comprobante en construcción');

        $snapshot = [
            'customer_doc_type' => $customer['doc_type'] ?? null,
            'customer_doc_number' => $customer['doc_number'] ?? null,
            'customer_dv' => $customer['dv'] ?? null,
            'customer_name' => $customer['name'] ?? null,
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'customer_address' => $customer['address'] ?? null,
            'customer_city_code' => $customer['city_code'] ?? null,
            'customer_department_code' => $customer['department_code'] ?? null,
            'is_final_consumer' => (bool) ($customer['is_final_consumer'] ?? false),
            'currency' => (string) ($d['currency'] ?? 'COP'),
            'subtotal' => $subtotal->toDatabase(),
            'discount' => $discount->toDatabase(),
            'tax_total' => $taxTotal->toDatabase(),
            'total' => $total->toDatabase(),
            'pricing_rules_version' => PricingService::RULES_VERSION,
            // Snapshot fiscal INMUTABLE: deja registrado bajo qué política se
            // construyó el comprobante, para poder auditar años después por qué
            // un documento llevó (o no) IVA.
            'tax_policy' => $taxPolicy->toSnapshot(),
        ];

        $payload = [
            'document' => (string) ($d['document'] ?? '01'),
            'operation_type' => (string) ($d['operation_type'] ?? '10'),
            'numbering_range_id' => (int) config('billing.numbering.range_id'),
            'send_email' => $this->shouldSendEmail($customer),
            'observation' => $observation,
            'cash_rounding_amount' => '0.00',
            'payment_details' => [[
                'payment_form' => (int) ($d['payment_form'] ?? 1),
                'payment_method_code' => (string) ($d['payment_method_code'] ?? '10'),
                // Debe coincidir exactamente con el total del comprobante.
                'amount' => $total->toDecimalString(),
            ]],
            'customer' => $this->customer($customer, $d),
            'items' => array_map(static fn ($l) => $l['payload'], $lines),
        ];

        // Desglose legible para el CRM. Sin datos personales ni secretos.
        $lineItems = array_map(static fn ($l) => [
            'code_reference' => $l['code_reference'],
            'name' => $l['name'],
            'quantity' => $l['quantity'],
            'base' => $l['base']->toDecimalString(),
            'tax_rate' => $l['tax_rate'],
            'tax' => $l['tax']->toDecimalString(),
            'discount' => $l['discount']->toDecimalString(),
            'gross' => $l['gross']->toDecimalString(),
            'pricing_mode' => $l['pricing_mode'],
        ], $lines);

        return ['snapshot' => $snapshot, 'payload' => $payload, 'line_items' => $lineItems];
    }

    /**
     * Decide si se solicita a Factus el envío del comprobante al correo del
     * cliente. Solo true si FACTUS_SEND_EMAIL está activo Y el cliente tiene un
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
        // No basta con que el formato sea correcto: `socio-XXX@ironbody.local`
        // lo es y no existe. `send_email=true` hacia ahí deja al cliente sin su
        // comprobante y sin señal de que algo falló.
        return is_string($email) && InvoiceEmail::esEntregable($email);
    }

    /** Construye el bloque customer V2 (natural vs jurídica). */
    private function customer(array $c, array $d): array
    {
        $juridica = ($c['person_type'] ?? null) === 'juridica';
        $legalOrg = $juridica ? '1' : (string) ($d['legal_organization_code'] ?? '2');

        $out = [
            'identification_document_code' => $this->docCode($c['doc_type'] ?? null),
            'identification' => (string) ($c['doc_number'] ?? ''),
            'legal_organization_code' => $legalOrg,
            'tribute_code' => (string) ($d['tribute_code'] ?? 'ZZ'),
            'municipality_code' => ($c['city_code'] ?? null) ?: ($d['municipality_code'] ?? null),
        ];

        if ($juridica) {
            $out['company'] = (string) ($c['legal_name'] ?? $c['name'] ?? '');
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
