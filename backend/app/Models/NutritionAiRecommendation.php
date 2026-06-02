<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property \Carbon\Carbon $recommendation_date
 * @property array|null $context_json
 * @property array|null $response_json
 * @property string|null $summary
 */
class NutritionAiRecommendation extends Model
{
    protected $fillable = [
        'member_id', 'recommendation_date', 'context_json', 'response_json', 'summary',
    ];

    protected $casts = [
        'recommendation_date' => 'date:Y-m-d',
        'context_json' => 'array',
        'response_json' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
