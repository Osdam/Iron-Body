<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IronAiUsageLog extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FALLBACK = 'fallback';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ERROR = 'error';

    /** Estados que consumen cuota (un mensaje "usado"). */
    public const CONSUMING = [self::STATUS_SUCCESS, self::STATUS_FALLBACK];

    protected $fillable = [
        'user_id',
        'member_id',
        'document',
        'membership_plan_id',
        'message_id',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost',
        'status',
        'block_reason',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens'   => 'integer',
            'output_tokens'  => 'integer',
            'estimated_cost' => 'decimal:6',
        ];
    }
}
