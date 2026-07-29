<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Error controlado de cotización. Se lanza cuando NO es seguro cobrar ni
 * facturar: tarifa faltante en una operación gravable, cantidad inválida,
 * precio negativo o descuento superior al importe.
 *
 * Nunca se traga silenciosamente: el llamador decide si se convierte en 422
 * (API) o en un fallo controlado de facturación (best-effort).
 */
class PricingException extends RuntimeException
{
    public static function missingTaxRate(string $what): self
    {
        return new self(
            "{$what} es facturable y tiene precio mayor que cero, pero no tiene tarifa de impuesto asignada. "
            .'Asigna su tratamiento tributario antes de cobrar o factúralo como no facturable.'
        );
    }

    public static function invalidQuantity(int $quantity): self
    {
        return new self("Cantidad inválida: {$quantity}. Debe ser un entero mayor o igual a 1.");
    }

    public static function negativeAmount(string $what): self
    {
        return new self("{$what} no puede ser negativo.");
    }

    public static function discountTooLarge(string $detail): self
    {
        return new self("Descuento inválido: {$detail}");
    }

    public static function unbalanced(): self
    {
        return new self('Cotización descuadrada: base + impuesto - descuento no coincide con el total.');
    }
}
