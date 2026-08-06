<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento crudo de Meta, tal como llegó y ya verificado por firma.
 *
 * Es el sistema de registro del canal: si el worker se cae, si un job falla o
 * si hay que auditar qué dijo Meta exactamente, la verdad está aquí y no en los
 * logs. Nada de esto se expone a Internet.
 */
class MetaWebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /** Agotó los reintentos: requiere intervención humana (o replay explícito). */
    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'correlation_id', 'payload_hash', 'object', 'phone_number_id', 'payload',
        'payload_bytes', 'messages_count', 'statuses_count',
        'status', 'attempts', 'last_error_class', 'last_error', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'payload_bytes' => 'integer',
        'messages_count' => 'integer',
        'statuses_count' => 'integer',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error' => null,
            'last_error_class' => null,
        ])->save();
    }

    /** Marca el fallo; `dead` cuando ya no quedan reintentos por delante. */
    public function markFailed(string $errorClass, string $message, bool $dead = false): void
    {
        $this->forceFill([
            'status' => $dead ? self::STATUS_DEAD : self::STATUS_FAILED,
            'last_error_class' => $errorClass,
            // Acotado: el mensaje de error es diagnóstico, no un volcado.
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
