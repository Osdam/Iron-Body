<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión real de una clase (una por fecha): hora de inicio/fin efectiva con
 * rostro del entrenador. Ver `create_class_sessions_table`.
 */
class ClassSession extends Model
{
    protected $fillable = [
        'class_id',
        'session_date',
        'started_at',
        'ended_at',
        'renewed_at',
        'started_by',
        'ended_by',
        'start_face_verified',
        'end_face_verified',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'renewed_at' => 'datetime',
            'start_face_verified' => 'boolean',
            'end_face_verified' => 'boolean',
        ];
    }

    public function gymClass(): BelongsTo
    {
        return $this->belongsTo(MyClass::class, 'class_id');
    }

    public function startedByTrainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'started_by');
    }

    public function isLive(): bool
    {
        return $this->started_at !== null && $this->ended_at === null;
    }

    /** Forma pública para el portal/CRM. */
    public function toPublicArray(): array
    {
        return [
            'class_id' => $this->class_id,
            'session_date' => optional($this->session_date)->toDateString(),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'started_by' => $this->started_by,
            'ended_by' => $this->ended_by,
            'start_face_verified' => (bool) $this->start_face_verified,
            'end_face_verified' => (bool) $this->end_face_verified,
            'is_live' => $this->isLive(),
        ];
    }
}
