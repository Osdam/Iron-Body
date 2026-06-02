<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $member_id
 * @property \Carbon\Carbon $log_date
 * @property string $meal_type
 */
class NutritionMealLog extends Model
{
    protected $fillable = ['member_id', 'log_date', 'meal_type'];

    protected $casts = [
        'log_date' => 'date:Y-m-d',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(NutritionMealItem::class, 'meal_log_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
