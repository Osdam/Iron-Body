<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reporte de seguridad/acceso de un usuario (robo, pérdida de acceso, cambio de
 * número, actividad sospechosa). Bandeja "Seguridad / Reportes de acceso" del
 * CRM. Nunca se desbloquea nada automáticamente: el equipo lo revisa.
 */
class SupportSecurityReport extends Model
{
    public const TYPE_STOLEN_DEVICE       = 'stolen_device';
    public const TYPE_LOST_ACCESS         = 'lost_access';
    public const TYPE_PHONE_CHANGED        = 'phone_changed';
    public const TYPE_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const TYPE_OTHER               = 'other';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_REJECTED  = 'rejected';

    public const TYPES = [
        self::TYPE_STOLEN_DEVICE,
        self::TYPE_LOST_ACCESS,
        self::TYPE_PHONE_CHANGED,
        self::TYPE_SUSPICIOUS_ACTIVITY,
        self::TYPE_OTHER,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'member_id',
        'document_number',
        'name',
        'phone',
        'email',
        'report_type',
        'status',
        'description',
        'contact_channel',
        'resolution_note',
        'resolved_by',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
