<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IronAiConversation extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'uuid',
        'user_id',
        'member_id',
        'document',
        'title',
        'topic',
        'summary',
        'last_message_preview',
        'messages_count',
        'status',
        'metadata',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'        => 'array',
            'messages_count'  => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IronAiMessage::class, 'iron_ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function toPublicArray(): array
    {
        return [
            'uuid'                 => $this->uuid,
            'title'                => $this->title,
            'topic'                => $this->topic,
            'summary'              => $this->summary,
            'last_message_preview' => $this->last_message_preview,
            'messages_count'       => (int) $this->messages_count,
            'last_message_at'      => optional($this->last_message_at)->toIso8601String(),
            'status'               => $this->status,
            'created_at'           => optional($this->created_at)->toIso8601String(),
        ];
    }
}
