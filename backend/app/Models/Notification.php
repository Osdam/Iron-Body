<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Notificación de Iron Body. Modelo propio (no la tabla morph de Laravel).
 *
 * Lo consumen la app Flutter (audience=member, por documento) y el CRM Angular
 * (audience=admin). Toda creación pasa por NotificationService para garantizar
 * idempotencia vía `event_key`.
 */
class Notification extends Model
{
    public const STATUS_UNREAD = 'unread';
    public const STATUS_READ   = 'read';

    public const AUDIENCE_MEMBER  = 'member';
    public const AUDIENCE_ADMIN   = 'admin';
    public const AUDIENCE_TRAINER = 'trainer';
    public const AUDIENCE_SYSTEM  = 'system';

    protected $fillable = [
        'uuid',
        'user_id',
        'member_id',
        'document',
        'audience',
        'type',
        'title',
        'message',
        'status',
        'priority',
        'action_type',
        'action_url',
        'action_payload',
        'metadata',
        'event_key',
        'read_at',
        'should_popup',
        'popup_shown_at',
    ];

    protected function casts(): array
    {
        return [
            'action_payload' => 'array',
            'metadata'       => 'array',
            'read_at'        => 'datetime',
            'should_popup'   => 'boolean',
            'popup_shown_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Notification $n): void {
            $n->uuid ??= (string) Str::uuid();
            $n->status ??= self::STATUS_UNREAD;
            $n->audience ??= self::AUDIENCE_MEMBER;
            $n->priority ??= 'medium';
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->status === self::STATUS_READ;
    }

    public function markRead(): void
    {
        if ($this->status === self::STATUS_READ) {
            return;
        }
        $this->status = self::STATUS_READ;
        $this->read_at = now();
        $this->save();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeUnread(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_UNREAD);
    }

    public function scopeAudience(Builder $q, string $audience): Builder
    {
        return $q->where('audience', $audience);
    }

    /**
     * Notificaciones visibles para un miembro: suyas + difusión global.
     *
     * [$since] (normalmente la fecha de alta del miembro) acota las difusiones
     * globales y las coincidencias SOLO por documento a las creadas desde que el
     * miembro existe. Sin esto, un miembro recién creado heredaba TODAS las
     * promos/anuncios históricos (aparecían ~50 al instante) y, si se recreaba
     * con la misma cédula, también las notificaciones del miembro borrado.
     * Las notificaciones ligadas a su `member_id` no se acotan (son posteriores
     * a su alta por definición).
     */
    public function scopeForMember(Builder $q, ?int $memberId, ?string $document, $since = null): Builder
    {
        return $q->where('audience', self::AUDIENCE_MEMBER)
            ->where(function (Builder $sub) use ($memberId, $document, $since): void {
                if ($memberId) {
                    $sub->orWhere('member_id', $memberId);
                }
                if ($document) {
                    $sub->orWhere(function (Builder $d) use ($document, $since): void {
                        $d->where('document', $document);
                        if ($since) {
                            $d->where('created_at', '>=', $since);
                        }
                    });
                }
                // Difusión global a todos los miembros (promos/anuncios): solo las
                // emitidas desde que el miembro existe.
                $sub->orWhere(function (Builder $g) use ($since): void {
                    $g->whereNull('member_id')->whereNull('document');
                    if ($since) {
                        $g->where('created_at', '>=', $since);
                    }
                });
            });
    }

    /** Búsqueda libre por título, mensaje, documento y metadata (JSON texto). */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%' . $term . '%';

        return $q->where(function (Builder $sub) use ($like): void {
            $sub->where('title', 'like', $like)
                ->orWhere('message', 'like', $like)
                ->orWhere('document', 'like', $like)
                ->orWhere('type', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('metadata', 'like', $like);
        });
    }

    // ── Serialización pública (lo que consumen Flutter y Angular) ──────────────

    public function toPublicArray(): array
    {
        return [
            'uuid'           => $this->uuid,
            'type'           => $this->type,
            'audience'       => $this->audience,
            'title'          => $this->title,
            'message'        => $this->message,
            'status'         => $this->status,
            'priority'       => $this->priority,
            'document'       => $this->document,
            'member_id'      => $this->member_id,
            'action_type'    => $this->action_type,
            'action_url'     => $this->action_url,
            'action_payload' => $this->action_payload,
            'metadata'       => $this->metadata,
            'should_popup'   => (bool) $this->should_popup,
            'read_at'        => optional($this->read_at)->toIso8601String(),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'time_ago'       => $this->timeAgo(),
        ];
    }

    public function timeAgo(): string
    {
        $created = $this->created_at instanceof Carbon
            ? $this->created_at
            : Carbon::parse($this->created_at);

        $diff = $created->diffInSeconds(now());

        if ($diff < 60) {
            return 'Hace un momento';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return "Hace {$m} min";
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return 'Hace ' . $h . ' ' . ($h === 1 ? 'hora' : 'horas');
        }
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);
            return 'Hace ' . $d . ' ' . ($d === 1 ? 'día' : 'días');
        }

        return $created->translatedFormat('d M Y');
    }
}
