<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LiveStream extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid', 'title', 'description', 'host_member_id', 'status',
        'provider', 'provider_room_id', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LiveStream $live): void {
            if (empty($live->uuid)) {
                $live->uuid = (string) Str::uuid();
            }
            if (empty($live->provider_room_id)) {
                $live->provider_room_id = 'live_'.$live->uuid;
            }
        });
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'host_member_id');
    }

    public function scopeLiveNow(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_LIVE);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function toAppArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'host_name' => $this->host?->full_name,
            'host_member_id' => $this->host_member_id,
            'room' => $this->provider_room_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
