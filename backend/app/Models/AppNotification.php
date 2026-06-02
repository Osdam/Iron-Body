<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string|null $action_type
 * @property string|null $action_route
 * @property array|null $payload_json
 * @property \Carbon\Carbon|null $read_at
 * @property \Carbon\Carbon|null $delivered_at
 * @property string|null $source
 * @property string|null $priority
 */
class AppNotification extends Model
{
    protected $fillable = [
        'member_id', 'type', 'title', 'body', 'action_type', 'action_route',
        'payload_json', 'read_at', 'delivered_at', 'source', 'priority',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'action_type' => $this->action_type,
            'action_route' => $this->action_route,
            'priority' => $this->priority,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
