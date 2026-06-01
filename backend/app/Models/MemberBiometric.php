<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberBiometric extends Model
{
    public const STATUS_ACTIVE                 = 'active';
    public const STATUS_LEGACY                 = 'legacy';
    public const STATUS_RE_ENROLLMENT_REQUIRED = 're_enrollment_required';
    public const STATUS_DISABLED               = 'disabled';

    protected $fillable = [
        'member_id',
        'face_path',
        'face_mime',
        'face_size',
        'captured_at',
        'bytes_length',
        'biometric_template_version',
        'normalizer_version',
        'enrolled_platform',
        'enrolled_device_type',
        'biometric_reference_status',
        'biometric_legacy_reason',
        'last_biometric_enrolled_at',
        'last_biometric_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at'                => 'datetime',
            'last_biometric_enrolled_at' => 'datetime',
            'last_biometric_verified_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Una referencia es "legacy" si no tiene normalizer_version (creada antes
     * del normalizador cross-platform) o si quedó marcada como tal. No bloquea
     * por sí sola: sólo habilita ofrecer re-enrolamiento cuando además falla.
     */
    public function isLegacy(): bool
    {
        return $this->normalizer_version === null
            || $this->biometric_reference_status === self::STATUS_LEGACY
            || $this->biometric_reference_status === self::STATUS_RE_ENROLLMENT_REQUIRED;
    }

    public function isDisabled(): bool
    {
        return $this->biometric_reference_status === self::STATUS_DISABLED;
    }
}
