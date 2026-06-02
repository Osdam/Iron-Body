<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property int $weekly_goal_days
 * @property string|null $hero_title
 * @property string|null $hero_description
 * @property string|null $hero_image_url
 * @property string|null $promo_image_url
 * @property string|null $cta_label
 * @property string|null $cta_route
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $metadata
 */
class WeeklyStreakConfig extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'weekly_goal_days', 'hero_title', 'hero_description',
        'hero_image_url', 'promo_image_url', 'cta_label', 'cta_route',
        'is_active', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'weekly_goal_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function rewards(): HasMany
    {
        return $this->hasMany(WeeklyStreakReward::class, 'config_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Config activa principal (la de menor sort_order). Null si no hay ninguna. */
    public static function activePrimary(): ?self
    {
        return static::active()->orderBy('sort_order')->orderBy('id')->first();
    }
}
