<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * Se intentó sacar más unidades de las que hay en existencia.
 *
 * Antes esto no era un error: `Product::decrementStock()` devolvía `false` y
 * quien lo llamaba descartaba el resultado, así que la venta quedaba cobrada y
 * el stock intacto. Fallar en voz alta es justamente el punto: el inventario y
 * el dinero cobrado tienen que contar la misma historia.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Stock insuficiente de «%s»: se piden %d unidades y hay %d disponibles.',
            $productName,
            $requested,
            $available,
        ));
    }

    public static function forProduct(Product $product, int $requested): self
    {
        return new self(
            (int) $product->id,
            (string) $product->name,
            $requested,
            (int) $product->stock,
        );
    }

    /** Detalle estructurado para la respuesta HTTP del CRM. */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'requested' => $this->requested,
            'available' => $this->available,
        ];
    }
}
