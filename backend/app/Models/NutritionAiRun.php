<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Registro de auditoría de una ejecución de IA (extracción/parser/estimación/
 * insight/admin-review). Sirve también de caché por `input_hash` y de base para
 * el cost guard diario.
 */
class NutritionAiRun extends Model
{
    public const MODE_LABEL_IMAGE = 'label_image';
    public const MODE_OCR_TEXT = 'ocr_text';
    public const MODE_ESTIMATE = 'estimate';
    public const MODE_INSIGHT = 'insight';
    public const MODE_ADMIN_REVIEW = 'admin_review';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    protected $fillable = [
        'uuid', 'member_id', 'food_id', 'barcode', 'mode', 'provider', 'model',
        'input_hash', 'confidence_score', 'status', 'error_code', 'prompt_version',
        'response_json', 'warnings',
    ];

    protected $casts = [
        'response_json'    => 'array',
        'warnings'         => 'array',
        'confidence_score' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(fn (NutritionAiRun $r) => $r->uuid ??= (string) Str::uuid());
    }
}
