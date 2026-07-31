<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $story_id
 * @property string $viewer_type 'member' | 'user'
 * @property int $viewer_id
 * @property string $type heart|fire|muscle|clap|trophy|lightning
 * @property Carbon $reacted_at
 */
class StoryReaction extends Model
{
    public $timestamps = false;

    /** Tipos válidos — single source of truth. */
    public const VALID_TYPES = [
        'heart', 'fire', 'muscle', 'clap', 'trophy', 'lightning',
    ];

    protected $fillable = [
        'story_id', 'viewer_type', 'viewer_id', 'type', 'reacted_at',
    ];

    protected $casts = [
        'reacted_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
