<?php

namespace App\Enums;

/**
 * Tipo de comprobante. Forma parte de la clave de idempotencia
 * (source_type + source_id + type): una misma venta puede tener UNA factura
 * y UNA nota crédito sin colisionar.
 */
enum InvoiceType: string
{
    case INVOICE     = 'invoice';
    case CREDIT_NOTE = 'credit_note';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
