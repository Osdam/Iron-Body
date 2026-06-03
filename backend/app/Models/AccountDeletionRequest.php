<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de eliminación de cuenta/datos iniciada desde la app (App Store
 * Guideline 5.1.1(v)). Se conserva como evidencia de auditoría aunque el
 * miembro se anonimice después.
 */
class AccountDeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id', 'user_id', 'status', 'reason',
        'ip_address', 'user_agent', 'metadata',
        'requested_at', 'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
