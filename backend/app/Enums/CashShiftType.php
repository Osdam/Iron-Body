<?php

namespace App\Enums;

/**
 * Las dos cajas monetarias del negocio. Son independientes: apertura, turno,
 * cierre, totales, historial y auditoría propios. NO existe una caja general;
 * cualquier vista "combinada" es lectura sobre dos cierres, nunca un tercer
 * turno.
 *
 * PRODUCTS agrupa el dinero de productos físicos (cafetería, mostrador) y su
 * fuente canónica es `product_sales`. GYM agrupa el dinero del gimnasio
 * (membresías, planes, renovaciones) y su fuente es `payments`. Mezclarlas
 * contablemente es justo lo que este tipo impide.
 */
enum CashShiftType: string
{
    case PRODUCTS = 'products';
    case GYM = 'gym';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PRODUCTS => 'Productos',
            self::GYM => 'Gimnasio',
        };
    }

    /** La otra caja. Lo usa la operación doble para saber a quién más tocar. */
    public function other(): self
    {
        return match ($this) {
            self::PRODUCTS => self::GYM,
            self::GYM => self::PRODUCTS,
        };
    }

    /** Permiso de consulta de ESTA caja. */
    public function viewPermission(): string
    {
        return "cash.{$this->value}.view";
    }

    /** Permiso para abrir/cerrar ESTA caja. */
    public function operatePermission(): string
    {
        return "cash.{$this->value}.operate";
    }

    /** Permiso de supervisión: cerrar turno ajeno, registrar diferencia. */
    public function managePermission(): string
    {
        return "cash.{$this->value}.manage";
    }
}
