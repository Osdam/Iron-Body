<?php

namespace App\Models;

use App\Http\Resources\Moderation\AdminReportResource;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Reporte de contenido generado por usuarios.
 *
 * IMPORTANTE — anonimato del reportante: `reporter_member_id` es de uso
 * EXCLUSIVAMENTE interno (dedup, rate limit, notificación de cierre). Ninguna
 * serialización hacia el CRM ni hacia la app lo incluye. Ver
 * {@see AdminReportResource}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $reporter_member_id
 * @property int|null $reported_member_id
 * @property int $content_id
 * @property string $reason_code
 * @property string $status
 */
class ContentReport extends Model
{
    public const CONTENT_TYPE_STORY = 'story';

    /**
     * Reporte sobre una PERSONA, no sobre una publicación concreta.
     *
     * `content_id` guarda el id del miembro reportado. Es un tipo distinto —y no
     * un reporte de story con la story vacía— porque la decisión del moderador
     * es distinta: aquí no hay contenido que retirar, sólo una cuenta que
     * evaluar por su conducta.
     */
    public const CONTENT_TYPE_MEMBER = 'member';

    protected $fillable = [
        'public_id',
        'reporter_member_id',
        'reported_member_id',
        'reported_author_type',
        'reported_author_id',
        'content_type',
        'content_id',
        'reason_code',
        'reason_detail',
        'status',
        'severity',
        'priority',
        'assigned_admin_id',
        'submitted_at',
        'reviewed_at',
        'resolved_at',
        'resolution_code',
        'moderator_notes',
        'reporter_notified_at',
        'reported_user_notified_at',
        'lock_version',
    ];

    protected $casts = [
        'content_id' => 'integer',
        'priority' => 'integer',
        'lock_version' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reporter_notified_at' => 'datetime',
        'reported_user_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContentReport $report): void {
            $report->public_id ??= (string) Str::uuid();
            $report->submitted_at ??= now();
        });
    }

    // ── Relaciones ────────────────────────────────────────────────────────

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'reporter_member_id');
    }

    public function reportedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'reported_member_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(ReportContentSnapshot::class, 'report_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'report_id');
    }

    /**
     * La Story reportada, si todavía existe (incluye soft-deleted para que el
     * moderador pueda ver que fue borrada). Puede ser null: la evidencia vive
     * en el snapshot, no aquí.
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'content_id')->withTrashed();
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ReportStatus::open());
    }

    public function scopeForContent(Builder $q, string $type, int $id): Builder
    {
        return $q->where('content_type', $type)->where('content_id', $id);
    }

    /** Cola de trabajo del CRM: lo más grave y más antiguo primero. */
    public function scopeQueueOrder(Builder $q): Builder
    {
        return $q->orderByDesc('priority')->orderBy('submitted_at');
    }

    // ── Estado ────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return ReportStatus::isOpen($this->status);
    }

    public function isClosed(): bool
    {
        return ReportStatus::isTerminal($this->status);
    }

    /** Minutos que el caso lleva abierto (o los que tardó en resolverse). */
    public function openMinutes(): int
    {
        $end = $this->resolved_at ?? now();

        return (int) ($this->submitted_at?->diffInMinutes($end) ?? 0);
    }

    public function reasonLabel(): string
    {
        return ReportReason::labelFor($this->reason_code);
    }

    public function statusLabel(): string
    {
        return ReportStatus::label($this->status);
    }
}
