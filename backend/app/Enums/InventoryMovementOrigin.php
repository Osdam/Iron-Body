<?php

namespace App\Enums;

/**
 * Naturaleza de un movimiento de inventario.
 *
 * Es la frontera entre lo COMERCIAL y lo ADMINISTRATIVO:
 *
 *  • `SALE_CAFETERIA` lo genera el sistema al cobrar una venta de producto
 *    físico. Nunca lo elige un usuario a mano.
 *  • El resto son movimientos administrativos que un operador registra desde
 *    Inventario: reposición, daño, pérdida, vencimiento, consumo interno o
 *    corrección de conteo.
 *
 * Una venta de plan/membresía NO tiene origen aquí: no mueve inventario.
 */
enum InventoryMovementOrigin: string
{
    // ── Entradas ────────────────────────────────────────────────────────────
    case PURCHASE = 'purchase';            // compra a proveedor / reposición
    case RETURN_IN = 'return';             // devolución de un cliente
    case INITIAL_STOCK = 'initial_stock';  // carga inicial del producto

    // ── Salidas administrativas ─────────────────────────────────────────────
    case DAMAGE = 'damage';                // producto dañado
    case LOSS = 'loss';                    // pérdida / faltante
    case EXPIRATION = 'expiration';        // vencido
    case INTERNAL_USE = 'internal_use';    // consumo interno del gimnasio

    // ── Salida comercial (automática) ───────────────────────────────────────
    case SALE_CAFETERIA = 'sale_cafeteria';

    // ── Corrección de saldo (ambas direcciones) ─────────────────────────────
    case ADJUSTMENT = 'adjustment';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Orígenes que un operador PUEDE elegir al registrar una entrada.
     *
     * @return string[]
     */
    public static function manualEntryValues(): array
    {
        return [
            self::PURCHASE->value,
            self::RETURN_IN->value,
            self::INITIAL_STOCK->value,
            self::ADJUSTMENT->value,
        ];
    }

    /**
     * Orígenes que un operador PUEDE elegir al registrar una salida.
     *
     * `SALE_CAFETERIA` queda deliberadamente fuera: una salida por venta la
     * escribe el cobro, no el formulario de inventario. Permitirla a mano sería
     * volver a meter el punto de venta dentro de Inventario por la puerta de
     * atrás, y además descuadraría el inventario contra las ventas.
     *
     * @return string[]
     */
    public static function manualExitValues(): array
    {
        return [
            self::DAMAGE->value,
            self::LOSS->value,
            self::EXPIRATION->value,
            self::INTERNAL_USE->value,
            self::ADJUSTMENT->value,
        ];
    }

    /** ¿Lo genera el sistema (y por tanto no es registrable a mano)? */
    public function isAutomatic(): bool
    {
        return $this === self::SALE_CAFETERIA;
    }

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Compra a proveedor',
            self::RETURN_IN => 'Devolución de cliente',
            self::INITIAL_STOCK => 'Carga inicial',
            self::DAMAGE => 'Daño',
            self::LOSS => 'Pérdida',
            self::EXPIRATION => 'Vencimiento',
            self::INTERNAL_USE => 'Consumo interno',
            self::SALE_CAFETERIA => 'Venta de cafetería',
            self::ADJUSTMENT => 'Ajuste de inventario',
        };
    }
}
