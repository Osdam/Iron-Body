<?php

namespace App\Models;

use App\Exceptions\ReceiptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De qué fila de dinero sale un recibo.
 *
 * `unique(source_type, source_id)` en la base de datos: una operación tiene UN
 * recibo. Es la garantía dura de que el mismo abono no aparece en dos tirillas,
 * aunque se pida suelto y luego dentro de un cobro de varias deudas.
 *
 * `source_type` es un nombre corto y estable ('payment', 'product_sale',
 * 'receivable_payment'), no el nombre de la clase: es parte del contrato con el
 * CRM y no puede cambiar porque alguien mueva un modelo de carpeta.
 */
class ReceiptSource extends Model
{
    public const TYPE_PAYMENT = 'payment';

    public const TYPE_PRODUCT_SALE = 'product_sale';

    public const TYPE_RECEIVABLE_PAYMENT = 'receivable_payment';

    public const TYPES = [self::TYPE_PAYMENT, self::TYPE_PRODUCT_SALE, self::TYPE_RECEIVABLE_PAYMENT];

    public $timestamps = false;

    protected $fillable = ['receipt_id', 'source_type', 'source_id'];

    protected $casts = ['source_id' => 'integer'];

    protected static function booted(): void
    {
        // Mover una fuente de recibo sería reasignar dinero a otra tirilla.
        static::updating(fn () => throw ReceiptException::immutable(null));
        static::deleting(fn () => throw ReceiptException::immutable(null));
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
