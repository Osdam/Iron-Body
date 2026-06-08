<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NutritionOcrScan extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'uuid', 'member_id', 'provider', 'barcode', 'image_path', 'status',
        'extracted_text', 'parsed_payload', 'confidence_score', 'error_message',
        'created_food_id',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'confidence_score' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(fn (NutritionOcrScan $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function createdFood()
    {
        return $this->belongsTo(NutritionFood::class, 'created_food_id');
    }
}
