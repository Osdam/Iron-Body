<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de webhook recibido de una pasarela (Wompi). Sirve para idempotencia:
 * un mismo payload (reentrega idéntica) no se procesa dos veces — el índice
 * único (provider, payload_hash) lo garantiza. NO guarda secretos de la
 * pasarela; `payload` es el cuerpo del evento ya recibido (sin Authorization).
 */
class PaymentWebhookEvent extends Model
{
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'uuid', 'provider', 'event_type', 'checksum', 'transaction_id',
        'environment', 'payload_hash', 'payload', 'processing_status',
        'processed_at', 'error_message', 'retry_count',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
        'retry_count'  => 'integer',
    ];
}
