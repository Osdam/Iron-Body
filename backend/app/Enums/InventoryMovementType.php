<?php

namespace App\Enums;

/**
 * Dirección de un movimiento de inventario. El signo vive aquí, no en la
 * cantidad: `quantity` siempre es positiva.
 */
enum InventoryMovementType: string
{
    case IN = 'in';
    case OUT = 'out';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Entrada',
            self::OUT => 'Salida',
        };
    }
}
