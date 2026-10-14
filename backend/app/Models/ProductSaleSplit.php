<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de una venta cobrada entre varios medios.
 *
 * Espejo de {@see PaymentSplit}. Ver la migración para por qué son dos tablas
 * y no una polimórfica.
 */
class ProductSaleSplit extends Model
{
    protected $fillable = ['product_sale_id', 'method', 'amount', 'reference'];

    protected $casts = ['amount' => 'decimal:2'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class, 'product_sale_id');
    }
}
