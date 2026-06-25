<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarifa de impuesto (IVA) por concepto, mapeada al tributo de Factus.
 * Planes y productos referencian una tarifa; el builder calcula base/IVA.
 */
class TaxRate extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'rate', 'factus_tribute_id', 'price_includes_tax', 'active',
    ];

    protected $casts = [
        'rate'   => 'decimal:2',
        'active' => 'boolean',
        'price_includes_tax' => 'boolean',
    ];

    /** Factor decimal de la tarifa (19.00 -> 0.19). */
    public function factor(): float
    {
        return (float) $this->rate / 100;
    }
}
