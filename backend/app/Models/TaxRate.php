<?php

namespace App\Models;

use App\Services\Billing\TaxPolicy;
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
        'rate' => 'decimal:2',
        'active' => 'boolean',
        'price_includes_tax' => 'boolean',
    ];

    /**
     * Factor decimal de la tarifa EFECTIVA (19.00 -> 0.19).
     *
     * Pasa por {@see TaxPolicy}, así que con Iron Body
     * como responsabilidad 49 (no responsable de IVA) devuelve SIEMPRE 0.0.
     *
     * Este es el punto de corte universal: todo consumidor del cálculo —el
     * `InvoiceDtoBuilder`, el motor de cotización, el CRM— lee la tarifa desde
     * aquí. Neutralizarla en el modelo evita que un camino olvidado vuelva a
     * partir el precio en base + IVA, que es lo que produjo IBFE2–IBFE8.
     */
    public function factor(): float
    {
        return $this->effectiveRate() / 100;
    }

    /**
     * Tarifa efectiva en porcentaje (0.0 con emisor no responsable).
     *
     * `rate` conserva su valor original en la base de datos: no se migran datos
     * y revertir la política es cambiar una variable de entorno.
     */
    public function effectiveRate(): float
    {
        return app(TaxPolicy::class)->effectiveBasisPoints($this) / 100;
    }
}
