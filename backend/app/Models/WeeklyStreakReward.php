<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $config_id
 * @property int $required_days
 * @property string $title
 * @property string|null $description
 * @property string|null $image_url
 * @property string|null $badge_label
 * @property string|null $reward_type
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $metadata
 */
class WeeklyStreakReward extends Model
{
    protected $fillable = [
        'config_id', 'required_days', 'title', 'description', 'image_url',
        'badge_label', 'reward_type', 'is_active', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'config_id' => 'integer',
        'required_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(WeeklyStreakConfig::class, 'config_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
