<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IronAiMessage extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'user_id',
        'member_id',
        'iron_ai_conversation_id',
        'conversation_uuid',
        'conversation_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(IronAiConversation::class, 'iron_ai_conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IronAiMessageAttachment::class, 'message_id');
    }
}
