<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MemberContract extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending_signature';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'contract_uuid',
        'folio',
        'member_id',
        'contract_template_id',
        'contract_type',
        'status',
        'member_snapshot',
        'guardian_snapshot',
        'medical_snapshot',
        'acceptance_snapshot',
        'signature_path',
        'signed_pdf_path',
        'signed_pdf_checksum',
        'signed_at',
        'voided_at',
        'void_reason',
        'ip_address',
        'user_agent',
        'device_id',
        'app_platform',
        'app_version',
        'template_version',
    ];

    protected $hidden = [
        // Rutas internas de almacenamiento: nunca se exponen al cliente.
        'signature_path',
        'signed_pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'member_snapshot'     => 'array',
            'guardian_snapshot'   => 'array',
            'medical_snapshot'    => 'array',
            'acceptance_snapshot' => 'array',
            'signed_at'           => 'datetime',
            'voided_at'           => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberContract $contract): void {
            $contract->contract_uuid ??= (string) Str::uuid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ContractAuditLog::class);
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** Un contrato firmado o anulado es inmutable (no se re-firma ni edita). */
    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_SIGNED, self::STATUS_VOID], true);
    }
}
