<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAuditLog extends Model
{
    public const UPDATED_AT = null; // tabla append-only (solo created_at)

    public const ACTOR_MEMBER = 'member';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    public const ACTION_CREATED = 'created';
    public const ACTION_VIEWED = 'viewed';
    public const ACTION_ACCEPTED = 'accepted';
    public const ACTION_SIGNED = 'signed';
    public const ACTION_PDF_GENERATED = 'pdf_generated';
    public const ACTION_DOWNLOADED = 'downloaded';
    public const ACTION_VOIDED = 'voided';

    protected $fillable = [
        'member_contract_id',
        'actor_type',
        'actor_id',
        'action',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MemberContract::class, 'member_contract_id');
    }
}
