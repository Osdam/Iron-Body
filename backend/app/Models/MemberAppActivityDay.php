<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property Carbon $activity_date
 * @property string|null $source
 */
class MemberAppActivityDay extends Model
{
    protected $fillable = [
        'member_id',
        'activity_date',
        'source',
    ];

    protected $casts = [
        'activity_date' => 'date:Y-m-d',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
