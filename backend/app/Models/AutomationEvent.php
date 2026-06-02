<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_type
 * @property int|null $member_id
 * @property array|null $payload_json
 * @property string $status
 * @property string $idempotency_key
 * @property int $attempts
 * @property string|null $last_error
 * @property \Carbon\Carbon|null $processed_at
 */
class AutomationEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'event_type', 'member_id', 'payload_json', 'status',
        'idempotency_key', 'attempts', 'last_error', 'processed_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Payload que se envía a n8n (envelope estándar). */
    public function toWebhookPayload(): array
    {
        return [
            'event_id' => (string) $this->id,
            'event_type' => $this->event_type,
            'member_id' => $this->member_id !== null ? (string) $this->member_id : null,
            'idempotency_key' => $this->idempotency_key,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'data' => $this->payload_json ?? [],
        ];
    }
}
