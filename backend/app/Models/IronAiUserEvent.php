<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property string $event_type
 * @property array|null $payload_json
 * @property int $importance
 * @property \Carbon\Carbon|null $occurred_at
 * @property string|null $idempotency_key
 */
class IronAiUserEvent extends Model
{
    protected $fillable = [
        'member_id', 'event_type', 'payload_json', 'importance',
        'occurred_at', 'idempotency_key',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'importance' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
