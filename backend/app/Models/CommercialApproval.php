<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Una operación excepcional esperando a que una persona la autorice.
 *
 * El agente puede pedir cualquiera de estas cosas; ninguna ocurre sola. Es la
 * línea que separa «un sistema que opera» de «un sistema que decide por su
 * cuenta devolver dinero», y está aquí y no en una condición dentro de un
 * servicio para que se pueda auditar mirando una tabla.
 */
class CommercialApproval extends Model
{
    // ── Tipos ────────────────────────────────────────────────────────────

    public const TYPE_DISCOUNT = 'discount';

    public const TYPE_EXCEPTIONAL_PROMO = 'exceptional_promotion';

    public const TYPE_RETURN = 'return';

    public const TYPE_REFUND = 'refund';

    public const TYPE_VOID = 'void';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const TYPE_FISCAL_CORRECTION = 'fiscal_correction';

    public const TYPE_OFF_CATALOG_BENEFIT = 'off_catalog_benefit';

    public const TYPE_CONTRACT_EXCEPTION = 'contract_exception';

    /** Dos fichas que podrían ser la misma persona. Fusionar es irreversible. */
    public const TYPE_IDENTITY_MERGE = 'identity_merge';

    public const TYPE_BULK_CAMPAIGN = 'bulk_campaign';

    public const TYPE_EXTRAORDINARY_FINANCIAL = 'extraordinary_financial';

    public const TYPES = [
        self::TYPE_DISCOUNT, self::TYPE_EXCEPTIONAL_PROMO, self::TYPE_RETURN,
        self::TYPE_REFUND, self::TYPE_VOID, self::TYPE_CREDIT_NOTE,
        self::TYPE_FISCAL_CORRECTION, self::TYPE_OFF_CATALOG_BENEFIT,
        self::TYPE_CONTRACT_EXCEPTION, self::TYPE_IDENTITY_MERGE,
        self::TYPE_BULK_CAMPAIGN, self::TYPE_EXTRAORDINARY_FINANCIAL,
    ];

    // ── Estados ──────────────────────────────────────────────────────────

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Ni sí ni no: falta algo y vuelve a quien la pidió. */
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_FAILED = 'failed';

    /** Estados desde los que todavía se puede decidir algo. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_CHANGES_REQUESTED];

    /** Estados finales: no admiten más cambios, y esa es su función. */
    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED, self::STATUS_EXPIRED, self::STATUS_CANCELLED,
        self::STATUS_EXECUTED,
    ];

    protected $fillable = [
        'uuid', 'type', 'status',
        'marketing_lead_id', 'member_id', 'marketing_conversation_id',
        'requested_by', 'requested_by_admin_id',
        'amount', 'currency', 'justification', 'evidence', 'risk', 'impact',
        'decided_by_admin_id', 'decided_at', 'decision_comment',
        'executed_at', 'execution_result', 'failure_reason',
        'expires_at', 'idempotency_key', 'correlation_id',
    ];

    protected $casts = [
        'evidence' => 'array',
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            $approval->uuid ??= (string) Str::uuid();
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** ¿Sigue esperando una decisión? */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true) && ! $this->hasExpired();
    }

    /**
     * Caducada aunque la columna diga «pendiente».
     *
     * Se calcula en vez de depender de que un job la haya marcado: si el
     * proceso de caducidad se para una noche, una autorización vencida NO
     * puede volverse aprobable solo porque nadie actualizó la fila.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast()
            && in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Pasada la fecha límite, decidida o no.
     *
     * Distinta de `hasExpired()` a propósito. Aquella pregunta «¿caducó sin que
     * nadie la decidiera?» y por eso exige que siga abierta; sirve para que la
     * bandeja no ofrezca aprobar algo que ya no se puede aprobar.
     *
     * Esta pregunta otra cosa: «¿se pasó la fecha?». Hace falta para el momento
     * de EJECUTAR, cuando la autorización ya está aprobada —y por tanto no está
     * abierta— pero el permiso que concedía tenía fecha. Una aprobación de ayer
     * ejecutada hoy es una acción sin permiso, aunque el permiso existiera.
     */
    public function isPastDeadline(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Estado real, contando la caducidad. */
    public function effectiveStatus(): string
    {
        return $this->hasExpired() ? self::STATUS_EXPIRED : $this->status;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** ¿Mueve dinero? Decide qué permiso hace falta para autorizarla. */
    public function isFinancial(): bool
    {
        return in_array($this->type, [
            self::TYPE_DISCOUNT, self::TYPE_REFUND, self::TYPE_RETURN,
            self::TYPE_VOID, self::TYPE_CREDIT_NOTE, self::TYPE_FISCAL_CORRECTION,
            self::TYPE_EXTRAORDINARY_FINANCIAL,
        ], true);
    }
}
