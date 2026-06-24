<?php

namespace App\Models;

use App\Enums\InvoiceLogAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traza append-only de la integración con Factus. El payload SIEMPRE va saneado
 * (FactusPayloadSanitizer) antes de llegar aquí. Sin updated_at: inmutable.
 */
class ElectronicInvoiceLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'electronic_invoice_id', 'action', 'endpoint', 'http_status',
        'result', 'message', 'payload_excerpt', 'duration_ms',
    ];

    protected $casts = [
        'action'          => InvoiceLogAction::class,
        'http_status'     => 'integer',
        'duration_ms'     => 'integer',
        'payload_excerpt' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class, 'electronic_invoice_id');
    }
}
