<?php

namespace App\Models;

use App\Services\Billing\FiscalReconciliationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila = una comparación entre el registro local y el documento fiscal
 * real del proveedor, en un instante concreto.
 *
 * Es APPEND-ONLY por diseño: nunca se actualiza una fila existente, se añade
 * otra. Lo que se está auditando es precisamente la divergencia entre ambas
 * fuentes, así que sobrescribir destruiría la evidencia.
 *
 * @see FiscalReconciliationService
 */
class InvoiceFiscalReconciliation extends Model
{
    /** El documento del proveedor coincide con el registro local. */
    public const STATUS_RECONCILED = 'reconciled';

    /** El documento del proveedor difiere del registro local. */
    public const STATUS_MISMATCH = 'mismatch';

    /**
     * No se pudo obtener el documento del proveedor, o la factura no tiene
     * documento fiscal que consultar. Nunca significa «correcto».
     */
    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'electronic_invoice_id',
        'invoice_number',
        'reconciliation_status',
        'unavailable_reason',
        'local_subtotal',
        'local_tax_total',
        'local_total',
        'local_status',
        'provider_taxable_amount',
        'provider_tax_amount',
        'provider_total',
        'provider_rate',
        'provider_tribute_code',
        'provider_is_excluded',
        'provider_cufe',
        'provider_is_validated',
        'provider_validated_at',
        'differences',
        'provider_snapshot',
        'provider_payload_hash',
        'actor',
        'fetched_at',
    ];

    protected $casts = [
        'local_subtotal' => 'decimal:2',
        'local_tax_total' => 'decimal:2',
        'local_total' => 'decimal:2',
        'provider_taxable_amount' => 'decimal:2',
        'provider_tax_amount' => 'decimal:2',
        'provider_total' => 'decimal:2',
        'provider_rate' => 'decimal:2',
        'provider_is_excluded' => 'boolean',
        'provider_is_validated' => 'boolean',
        'provider_validated_at' => 'datetime',
        'differences' => 'array',
        'provider_snapshot' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class, 'electronic_invoice_id');
    }

    public function isMismatch(): bool
    {
        return $this->reconciliation_status === self::STATUS_MISMATCH;
    }

    public function isReconciled(): bool
    {
        return $this->reconciliation_status === self::STATUS_RECONCILED;
    }

    /** IVA que el proveedor tiene registrado, en centavos. Autoridad fiscal. */
    public function providerTaxCents(): int
    {
        return (int) round(((float) $this->provider_tax_amount) * 100);
    }
}
