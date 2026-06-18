<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSupportTicket extends Model
{
    public const STATUS_NEW         = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';

    protected $fillable = [
        'member_id',
        'user_id',
        'document',
        'type',
        'message',
        'status',
        'app_version',
        'platform',
        'device_name',
        'screen',
        'recent_errors',
        'metadata',
        'admin_note',
        'resolved_at',
    ];

    protected $casts = [
        'recent_errors' => 'array',
        'metadata'      => 'array',
        'resolved_at'   => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function toPublicArray(): array
    {
        return [
            'id'            => $this->id,
            'member_id'     => $this->member_id,
            'member_name'   => $this->member?->full_name,
            'document'      => $this->document,
            'type'          => $this->type,
            'message'       => $this->message,
            'status'        => $this->status,
            'app_version'   => $this->app_version,
            'platform'      => $this->platform,
            'device_name'   => $this->device_name,
            'screen'        => $this->screen,
            'recent_errors' => $this->recent_errors ?? [],
            'metadata'      => $this->metadata ?? [],
            'admin_note'    => $this->admin_note,
            'resolved_at'   => optional($this->resolved_at)->toIso8601String(),
            'created_at'    => optional($this->created_at)->toIso8601String(),
            'time_ago'      => optional($this->created_at)->diffForHumans(),
        ];
    }
}
