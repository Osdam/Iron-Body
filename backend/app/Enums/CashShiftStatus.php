<?php

namespace App\Enums;

/** Estado de un turno de caja. */
enum CashShiftStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Abierto',
            self::CLOSED => 'Cerrado',
        };
    }
}
