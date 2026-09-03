<?php

namespace App\Services\Billing;

use App\Models\Product;

/**
 * ¿Se puede cobrar este producto en mostrador, y si no, por qué?
 *
 * Existe porque el CRM listaba como vendible cualquier producto activo y el
 * fallo solo aparecía al final, en el cobro: recepción metía el producto en el
 * carrito, pulsaba cobrar y recibía un mensaje sobre tratamiento tributario que
 * ni entiende ni puede resolver. Y 23 de los 31 productos del catálogo real
 * estaban en ese estado.
 *
 * La regla NO se reescribe aquí. Se pregunta al propio {@see PricingService}
 * intentando la cotización: si cotiza, se puede vender; si lanza, no. Duplicar
 * la condición —«tiene tarifa y precio positivo y es facturable»— habría
 * creado una segunda verdad que se separa de la primera en cuanto una cambie,
 * y entonces la UI diría una cosa y el cobro otra.
 *
 * Es un cálculo puro: no toca base de datos ni escribe nada.
 */
class SaleReadiness
{
    /** El producto no se puede cobrar porque le falta el tratamiento tributario. */
    public const REASON_TAX = 'missing_tax_treatment';

    /** No hay unidades. No impide cotizar, pero sí vender. */
    public const REASON_STOCK = 'out_of_stock';

    /** Retirado del catálogo. */
    public const REASON_INACTIVE = 'inactive';

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @return array{sale_ready: bool, sale_block_reason: string|null, sale_block_message: string|null}
     */
    public function for(Product $product): array
    {
        if (! $product->active) {
            return self::blocked(self::REASON_INACTIVE, 'No está disponible en el catálogo.');
        }

        // La cotización es la autoridad. Se le pide una unidad, que es lo mínimo
        // que puede venderse; si esa no sale, ninguna cantidad saldrá.
        try {
            $this->pricing->quoteForProduct($product, 1);
        } catch (PricingException) {
            // El mensaje que ve recepción NO es el del motor de precios: aquel
            // habla de tarifas y tratamientos, y quien atiende el mostrador ni
            // los conoce ni puede cambiarlos. Se le dice qué pasa y a quién
            // acudir, que es lo único accionable desde su puesto.
            return self::blocked(
                self::REASON_TAX,
                'Requiere configuración tributaria. Solicita a un administrador que la complete.',
            );
        }

        if ((int) $product->stock <= 0) {
            return self::blocked(self::REASON_STOCK, 'Sin unidades disponibles.');
        }

        return ['sale_ready' => true, 'sale_block_reason' => null, 'sale_block_message' => null];
    }

    /** @return array{sale_ready: bool, sale_block_reason: string, sale_block_message: string} */
    private static function blocked(string $reason, string $message): array
    {
        return [
            'sale_ready' => false,
            'sale_block_reason' => $reason,
            'sale_block_message' => $message,
        ];
    }
}
