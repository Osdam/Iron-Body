<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Cotización monetaria CONGELADA de una operación.
 *
 * Es el contrato único entre cobro y facturación: el mismo objeto determina lo
 * que se le cobra al cliente (grossAmount) y lo que se declara en la factura
 * (baseAmount + taxAmount). Por construcción no pueden divergir.
 *
 * Invariante garantizada por PricingService:
 *     baseAmount + taxAmount - discountAmount == grossAmount
 *
 * Se persiste como snapshot en payments / product_sales / product_sale_items /
 * membership_subscriptions. Una vez persistido NUNCA se recalcula: si el
 * catálogo cambia después, la operación conserva su cotización original.
 */
final readonly class PriceQuote
{
    public function __construct(
        public Money $baseAmount,
        public Money $taxAmount,
        public Money $grossAmount,
        public Money $discountAmount,
        public ?int $taxRateId,
        /** Tasa en puntos básicos: 19.00% => 1900. Entero, sin float. */
        public int $taxRateBasisPoints,
        public int $quantity,
        public string $currency,
        public PricingMode $pricingMode,
        public string $pricingRulesVersion,
        public CarbonImmutable $pricedAt,
        /** Base unitaria antes de impuesto (para el item de Factus). */
        public Money $unitBaseAmount,
    ) {}

    /** Tasa como string decimal ("19.00") para el payload de Factus. */
    public function taxRateString(): string
    {
        return number_format($this->taxRateBasisPoints / 100, 2, '.', '');
    }

    public function taxRateFloat(): float
    {
        return round($this->taxRateBasisPoints / 100, 2);
    }

    public function hasTax(): bool
    {
        return $this->taxRateBasisPoints > 0;
    }

    /** Verificación de la invariante. Debe cumplirse siempre. */
    public function isBalanced(): bool
    {
        return $this->baseAmount
            ->plus($this->taxAmount)
            ->minus($this->discountAmount)
            ->equals($this->grossAmount);
    }

    /**
     * Snapshot para persistir en columnas `*_amount` / `*_snapshot`.
     * Los importes van como string decimal para no pasar por float.
     *
     * @return array<string,mixed>
     */
    public function toSnapshot(string $prefix = ''): array
    {
        $p = $prefix;

        return [
            $p.'base_amount' => $this->baseAmount->toDatabase(),
            $p.'tax_amount' => $this->taxAmount->toDatabase(),
            $p.'gross_amount' => $this->grossAmount->toDatabase(),
            $p.'discount_amount' => $this->discountAmount->toDatabase(),
            $p.'tax_rate_id' => $this->taxRateId,
            $p.'tax_rate' => $this->taxRateString(),
            $p.'pricing_mode' => $this->pricingMode->value,
            $p.'pricing_rules_version' => $this->pricingRulesVersion,
            $p.'priced_at' => $this->pricedAt,
            $p.'currency' => $this->currency,
        ];
    }

    /**
     * Forma pública para el CRM/app. El frontend la MUESTRA, no la calcula.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'pricing_mode' => $this->pricingMode->value,
            'quantity' => $this->quantity,
            'base_amount' => $this->baseAmount->toDecimalString(),
            'tax_rate' => $this->taxRateString(),
            'tax_rate_id' => $this->taxRateId,
            'tax_amount' => $this->taxAmount->toDecimalString(),
            'discount_amount' => $this->discountAmount->toDecimalString(),
            'gross_amount' => $this->grossAmount->toDecimalString(),
            'pricing_rules_version' => $this->pricingRulesVersion,
            'display' => [
                'base' => self::formatCop($this->baseAmount),
                'tax' => self::formatCop($this->taxAmount),
                'total' => self::formatCop($this->grossAmount),
            ],
        ];
    }

    /** Formato COP para presentación: $95.200 (sin decimales cuando son cero). */
    public static function formatCop(Money $money): string
    {
        $cents = $money->cents();
        $units = intdiv(abs($cents), Money::SCALE);
        $rest = abs($cents) % Money::SCALE;
        $sign = $cents < 0 ? '-' : '';

        $formatted = number_format($units, 0, ',', '.');
        if ($rest !== 0) {
            $formatted .= ','.str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
        }

        return $sign.'$'.$formatted;
    }
}
