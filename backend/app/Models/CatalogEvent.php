<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Señal efímera de cambio de catálogo, común a todos los socios.
 *
 * No lleva precio ni stock: es una invalidación. El cliente recibe «el producto
 * 46 cambió de stock» y vuelve a pedir el estado canónico por la API. Así el
 * SSE nunca se convierte en una segunda fuente de verdad que pueda quedar
 * desincronizada de la base.
 */
class CatalogEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'product_id', 'class_id', 'session_date', 'changed', 'version', 'created_at'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'class_id' => 'integer',
            'session_date' => 'date',
            'changed' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
