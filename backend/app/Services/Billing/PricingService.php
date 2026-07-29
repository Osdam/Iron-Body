<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;

/**
 * FUENTE ÚNICA DE VERDAD del cálculo monetario de Iron Body.
 *
 * Todo flujo que cobre dinero (Wompi único, suscripciones, renovaciones, caja,
 * pagos manuales) y todo flujo que facture DEBEN pasar por aquí. El objetivo es
 * estructural: si cobro y facturación consumen el mismo PriceQuote, no pueden
 * divergir.
 *
 * FÓRMULAS (aritmética entera, ver Money):
 *
 *   legacy_inclusive  (el precio configurado YA trae IVA)
 *       gross = configured_price
 *       base  = gross * 10000 / (10000 + rate_bp)     [HALF_UP]
 *       tax   = gross - base                          [exacto, cuadra siempre]
 *
 *   base_plus_tax     (el precio configurado es la BASE)
 *       base  = configured_price
 *       tax   = base * rate_bp / 10000                [HALF_UP]
 *       gross = base + tax                            [exacto]
 *
 *   tarifa 0 / excluido / exento / no gravado
 *       base = configured_price ; tax = 0 ; gross = base
 *
 * No se asume 19% en ningún caso: la tasa sale del TaxRate asociado. La
 * constante FACTUS_DEFAULT_TAX_RATE es solo un default de catálogo Factus y
 * NUNCA participa de un cálculo.
 */
class PricingService
{
    /**
     * Versión de las reglas de cálculo. Se congela en cada snapshot para poder
     * auditar con qué reglas se calculó una operación histórica.
     */
    public const RULES_VERSION = 'v2.2026.07';

    /**
     * Cotiza un plan de membresía.
     *
     * @throws PricingException si el plan es facturable y gravable pero no tiene tarifa.
     */
    public function quoteForPlan(Plan $plan, int $quantity = 1): PriceQuote
    {
        $plan->loadMissing('taxRate');

        return $this->quote(
            configuredUnitPrice: Money::fromAmount($plan->price),
            quantity: $quantity,
            taxRate: $plan->taxRate,
            mode: self::effectiveMode($plan->pricingMode()),
            billable: $plan->isBillable(),
            label: "El plan «{$plan->name}»",
        );
    }

    /**
     * Cotiza una línea de producto.
     *
     * @param  Money|null  $discount  Descuento sobre la línea (importe bruto).
     *
     * @throws PricingException si el producto es gravable pero no tiene tarifa,
     *                          o si el descuento supera el importe de la línea.
     */
    public function quoteForProduct(Product $product, int $quantity = 1, ?Money $discount = null): PriceQuote
    {
        $product->loadMissing('taxRate');

        return $this->quote(
            configuredUnitPrice: Money::fromAmount($product->sale_price),
            quantity: $quantity,
            taxRate: $product->taxRate,
            mode: self::effectiveMode($product->pricingMode()),
            billable: $product->isBillable(),
            label: "El producto «{$product->name}»",
            discount: $discount,
        );
    }

    /**
     * Cotiza tratando el precio como TOTAL con IVA incluido (comportamiento
     * histórico). Se usa para reconstruir operaciones legacy sin snapshot.
     */
    public function quoteLegacyInclusive(
        Money $grossUnitPrice,
        ?TaxRate $taxRate,
        int $quantity = 1,
        ?Money $discount = null,
    ): PriceQuote {
        return $this->quote(
            configuredUnitPrice: $grossUnitPrice,
            quantity: $quantity,
            taxRate: $taxRate,
            mode: PricingMode::LEGACY_INCLUSIVE,
            billable: true,
            label: 'La operación',
            discount: $discount,
            requireTaxRate: false,
        );
    }

    /**
     * Cotiza tratando el precio como BASE, sumando el impuesto por encima.
     */
    public function quoteBasePlusTax(
        Money $baseUnitPrice,
        ?TaxRate $taxRate,
        int $quantity = 1,
        ?Money $discount = null,
    ): PriceQuote {
        return $this->quote(
            configuredUnitPrice: $baseUnitPrice,
            quantity: $quantity,
            taxRate: $taxRate,
            mode: PricingMode::BASE_PLUS_TAX,
            billable: true,
            label: 'La operación',
            discount: $discount,
            requireTaxRate: false,
        );
    }

    // ── Núcleo ──────────────────────────────────────────────────────────────

    /**
     * @param  bool  $billable  Si false (plan gratuito / no facturable), no se exige tarifa.
     * @param  bool  $requireTaxRate  Si false, la ausencia de tarifa se trata como tasa 0.
     *
     * @throws PricingException
     */
    private function quote(
        Money $configuredUnitPrice,
        int $quantity,
        ?TaxRate $taxRate,
        PricingMode $mode,
        bool $billable,
        string $label,
        ?Money $discount = null,
        bool $requireTaxRate = true,
    ): PriceQuote {
        if ($quantity < 1) {
            throw PricingException::invalidQuantity($quantity);
        }
        if ($configuredUnitPrice->isNegative()) {
            throw PricingException::negativeAmount($label.' tiene un precio que');
        }

        $discount ??= Money::zero();
        if ($discount->isNegative()) {
            throw PricingException::negativeAmount('El descuento');
        }

        $rateBp = self::basisPoints($taxRate);

        // Una operación gravable sin tarifa NO se cobra a ciegas. Las gratuitas y
        // las marcadas como no facturables sí pueden continuar sin tratamiento.
        if ($requireTaxRate && $taxRate === null && $billable && $configuredUnitPrice->isPositive()) {
            throw PricingException::missingTaxRate($label);
        }

        // El impuesto se calcula sobre el AGREGADO de la línea, nunca por unidad:
        // evita la deriva de centavos al multiplicar redondeos.
        $configuredTotal = $configuredUnitPrice->multipliedBy($quantity);

        if ($rateBp <= 0) {
            $base = $configuredTotal;
            $tax = Money::zero();
        } elseif ($mode->isLegacy()) {
            $base = $configuredTotal->baseFromGross($rateBp);
            $tax = $configuredTotal->minus($base);
        } else {
            $base = $configuredTotal;
            $tax = $configuredTotal->taxOnTop($rateBp);
        }

        $gross = $base->plus($tax)->minus($discount);

        if ($gross->isNegative()) {
            throw PricingException::discountTooLarge(
                'el descuento ('.$discount->toDecimalString().') supera el total de la línea ('
                .$base->plus($tax)->toDecimalString().').'
            );
        }

        $quote = new PriceQuote(
            baseAmount: $base,
            taxAmount: $tax,
            grossAmount: $gross,
            discountAmount: $discount,
            taxRateId: $taxRate?->id,
            taxRateBasisPoints: $rateBp,
            quantity: $quantity,
            currency: 'COP',
            pricingMode: $mode,
            pricingRulesVersion: self::RULES_VERSION,
            pricedAt: CarbonImmutable::now(),
            // Base unitaria para el item de Factus. Se deriva de la base agregada
            // para que base_unitaria x cantidad reconstruya el agregado sin deriva
            // cuando la división es exacta; si no lo es, manda el agregado.
            unitBaseAmount: Money::fromCents(intdiv($base->cents(), $quantity)),
        );

        if (! $quote->isBalanced()) {
            throw PricingException::unbalanced();
        }

        return $quote;
    }

    /**
     * Reconstruye un PriceQuote desde un snapshot ya persistido. NO recalcula:
     * lee los importes congelados tal cual quedaron al cobrar.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function fromSnapshot(array $snapshot, int $quantity = 1): PriceQuote
    {
        $base = Money::fromAmount($snapshot['base_amount'] ?? null);
        $tax = Money::fromAmount($snapshot['tax_amount'] ?? null);
        $gross = Money::fromAmount($snapshot['gross_amount'] ?? null);
        $discount = Money::fromAmount($snapshot['discount_amount'] ?? null);
        $qty = max(1, (int) ($snapshot['quantity'] ?? $quantity));

        return new PriceQuote(
            baseAmount: $base,
            taxAmount: $tax,
            grossAmount: $gross,
            discountAmount: $discount,
            taxRateId: isset($snapshot['tax_rate_id']) ? (int) $snapshot['tax_rate_id'] : null,
            taxRateBasisPoints: (int) round(((float) ($snapshot['tax_rate'] ?? 0)) * 100),
            quantity: $qty,
            currency: (string) ($snapshot['currency'] ?? 'COP'),
            pricingMode: PricingMode::fromValue($snapshot['pricing_mode'] ?? null),
            pricingRulesVersion: (string) ($snapshot['pricing_rules_version'] ?? self::RULES_VERSION),
            pricedAt: isset($snapshot['priced_at'])
                ? CarbonImmutable::parse($snapshot['priced_at'])
                : CarbonImmutable::now(),
            unitBaseAmount: Money::fromCents(intdiv($base->cents(), $qty)),
        );
    }

    /**
     * Modo efectivo tras aplicar el interruptor global BILLING_TAX_ON_TOP_ENABLED.
     *
     * Red de seguridad de despliegue: con el flag apagado, un registro marcado
     * como base_plus_tax se cotiza como legacy_inclusive. Así se puede preparar
     * el catálogo (marcar los planes que migrarán) SIN que ningún cliente pague
     * de más, y activar el cambio de cobro en un paso posterior y reversible.
     */
    public static function effectiveMode(PricingMode $configured): PricingMode
    {
        if ($configured === PricingMode::BASE_PLUS_TAX && ! config('billing.pricing.tax_on_top', false)) {
            return PricingMode::LEGACY_INCLUSIVE;
        }

        return $configured;
    }

    /** Tasa del TaxRate en puntos básicos enteros (19.00% => 1900). */
    /**
     * Tarifa efectiva en puntos básicos.
     *
     * DELEGA en {@see TaxPolicy}: es el único punto del sistema que decide si
     * una operación lleva IVA. Con Iron Body como no responsable (RUT 49) el
     * resultado es SIEMPRE 0, aunque el plan apunte a «IVA 19% incluido».
     *
     * Con 0 puntos básicos, `quote()` toma la rama `base = precio comercial,
     * tax = 0`: no se divide por 1,19 ni se suma nada por encima.
     */
    public static function basisPoints(?TaxRate $taxRate): int
    {
        return app(TaxPolicy::class)->effectiveBasisPoints($taxRate);
    }
}
