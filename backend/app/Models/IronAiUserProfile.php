<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property string|null $primary_goal
 * @property string|null $secondary_goal
 * @property string|null $training_level
 * @property string|null $nutrition_style
 * @property string|null $preferences_summary
 * @property string|null $injuries_summary
 * @property string|null $ai_memory_summary
 * @property \Carbon\Carbon|null $last_context_refresh_at
 */
class IronAiUserProfile extends Model
{
    protected $fillable = [
        'member_id', 'primary_goal', 'secondary_goal', 'training_level',
        'nutrition_style', 'preferences_summary', 'injuries_summary',
        'ai_memory_summary', 'last_context_refresh_at',
    ];

    protected $casts = [
        'last_context_refresh_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
