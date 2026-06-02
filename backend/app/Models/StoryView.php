<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $story_id
 * @property string $viewer_type 'member' | 'user'
 * @property int $viewer_id
 * @property \Carbon\Carbon $viewed_at
 */
class StoryView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'story_id', 'viewer_type', 'viewer_id', 'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
