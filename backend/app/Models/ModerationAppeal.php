<?php

namespace App\Models;

use App\Services\Moderation\AppealService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Apelación de un miembro contra una acción de moderación.
 *
 * Reglas de negocio (verificadas en {@see AppealService}):
 *  - Una apelación ABIERTA por acción.
 *  - Solo el miembro objetivo de la acción puede apelar.
 *  - Las notas de resolución son internas: la app recibe estado + mensaje
 *    público, nunca `resolution_notes`.
 *
 * @property string $status
 */
class ModerationAppeal extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    /** Se mantiene la sanción. */
    public const STATUS_UPHELD = 'upheld';

    /** Se da la razón al miembro: la sanción se revoca. */
    public const STATUS_GRANTED = 'granted';

    /** Apelación inadmisible (fuera de plazo, sin fundamento formal). */
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'moderation_action_id',
        'member_id',
        'appeal_text',
        'status',
        'reviewed_by_admin_id',
        'resolution_notes',
        'public_resolution',
        'submitted_at',
        'resolved_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** Interno — nunca sale a la app. */
    protected $hidden = [
        'resolution_notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (ModerationAppeal $appeal): void {
            $appeal->public_id ??= (string) Str::uuid();
            $appeal->submitted_at ??= now();
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class, 'moderation_action_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    /** @return list<string> Estados que cuentan como apelación abierta. */
    public static function openStatuses(): array
    {
        return [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW];
    }

    /**
     * Estados que agotan el derecho a apelar esa acción.
     *
     * Una vez que un moderador resolvió la apelación, la misma medida no se
     * puede volver a apelar: sin esto, el límite diario permitiría reabrir el
     * mismo caso una y otra vez (spam de apelaciones).
     *
     * @return list<string>
     */
    public static function resolvedStatuses(): array
    {
        return [self::STATUS_UPHELD, self::STATUS_GRANTED, self::STATUS_REJECTED];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::openStatuses());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::openStatuses(), true);
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_SUBMITTED => 'Enviada',
            self::STATUS_UNDER_REVIEW => 'En revisión',
            self::STATUS_UPHELD => 'Sanción mantenida',
            self::STATUS_GRANTED => 'Sanción revocada',
            self::STATUS_REJECTED => 'No admitida',
        ][$this->status] ?? $this->status;
    }
}
