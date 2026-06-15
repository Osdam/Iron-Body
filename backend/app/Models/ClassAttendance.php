<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asistencia de un miembro a una sesión concreta de una clase. Ver la migración
 * para las garantías (anti-doble + correcciones auditadas).
 */
class ClassAttendance extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const ALL_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
    ];

    protected $fillable = [
        'class_id',
        'member_id',
        'session_date',
        'status',
        'marked_by_trainer_id',
        'marked_at',
        'corrected_at',
        'correction_note',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date:Y-m-d',
            'marked_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::ALL_STATUSES, true);
    }

    public function gymClass(): BelongsTo
    {
        return $this->belongsTo(MyClass::class, 'class_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
