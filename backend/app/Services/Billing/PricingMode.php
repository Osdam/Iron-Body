<?php

namespace App\Services\Billing;

/**
 * Semántica del precio configurado en el catálogo (plans.price / products.sale_price).
 *
 * Es un dato POR REGISTRO, no un interruptor global: la migración a IVA adicional
 * se hace plan por plan / producto por producto, nunca en bloque.
 */
enum PricingMode: string
{
    /**
     * El precio configurado YA contiene el IVA. La base se extrae hacia atrás.
     * Es el comportamiento histórico y el default de todo el catálogo existente:
     * garantiza que las operaciones y comprobantes anteriores no cambien.
     */
    case LEGACY_INCLUSIVE = 'legacy_inclusive';

    /**
     * El precio configurado es la BASE antes de impuesto. El IVA se suma por
     * encima ANTES de cobrar, de modo que el cliente paga base + impuesto.
     */
    case BASE_PLUS_TAX = 'base_plus_tax';

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::LEGACY_INCLUSIVE;
    }

    public function isLegacy(): bool
    {
        return $this === self::LEGACY_INCLUSIVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::LEGACY_INCLUSIVE => 'IVA incluido en el precio',
            self::BASE_PLUS_TAX => 'IVA adicional sobre el precio base',
        };
    }
}
