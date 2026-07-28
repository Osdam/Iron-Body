<?php

namespace App\Models;

use App\Support\Moderation\ActionType;
use App\Support\Moderation\ModerationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Acción administrativa aplicada en un caso de moderación.
 *
 * Es un HECHO histórico: revocarla no la borra, le pone `revoked_at`. La
 * sanción "viva" que consulta la app es {@see MemberSuspension}.
 *
 * @property string $action_type
 * @property string $scope
 * @property int|null $duration_minutes
 */
class ModerationAction extends Model
{
    protected $fillable = [
        'public_id',
        'report_id',
        'target_member_id',
        'target_story_id',
        'action_type',
        'scope',
        'duration_minutes',
        'starts_at',
        'ends_at',
        'reason',
        'internal_notes',
        'created_by_admin_id',
        'revoked_by_admin_id',
        'revoked_at',
        'revoke_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Nunca sale hacia la app: es exclusivamente interno. */
    protected $hidden = [
        'internal_notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (ModerationAction $action): void {
            $action->public_id ??= (string) Str::uuid();
            $action->starts_at ??= now();
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ContentReport::class, 'report_id');
    }

    public function targetMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'target_member_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function suspension(): HasOne
    {
        return $this->hasOne(MemberSuspension::class, 'moderation_action_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(ModerationAppeal::class, 'moderation_action_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isPermanent(): bool
    {
        return $this->ends_at === null
            && ActionType::createsSuspension($this->action_type);
    }

    /** ¿El miembro puede apelar esta acción ahora mismo? */
    public function isAppealable(): bool
    {
        if ($this->isRevoked()) {
            return false;
        }
        // Una acción sobre contenido restaurado o desestimada no se apela.
        if (in_array($this->action_type, [
            ActionType::RESTORE_CONTENT,
            ActionType::DISMISS,
        ], true)) {
            return false;
        }
        // Ya caducó: no tiene efecto que revertir.
        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function typeLabel(): string
    {
        return ActionType::label($this->action_type);
    }

    public function scopeLabel(): string
    {
        return ModerationScope::label($this->scope);
    }
}
