<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un callback de estado de Meta, tal como llegó. Incluye los que NO movieron el
 * estado actual por llegar fuera de orden (`applied=false`): son la prueba de
 * por qué un mensaje figura como leído aunque después entrara un "sent".
 */
class MarketingMessageStatus extends Model
{
    /**
     * Orden real de la entrega. Un callback solo puede AVANZAR el estado; uno
     * que llega tarde no puede hacer retroceder un mensaje ya leído.
     */
    public const RANKS = [
        'dry_run' => 0,
        'pending' => 0,
        'queued' => 0,
        'sent' => 1,
        'failed' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    protected $fillable = [
        'message_id', 'status', 'applied', 'error_code', 'error_title',
        'error_message', 'occurred_at', 'correlation_id', 'metadata',
    ];

    protected $casts = [
        'applied' => 'boolean',
        'error_code' => 'integer',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MarketingMessage::class, 'message_id');
    }

    /** Estados desconocidos valen 0: nunca desplazan a uno conocido. */
    public static function rank(?string $status): int
    {
        return self::RANKS[$status ?? ''] ?? 0;
    }
}
