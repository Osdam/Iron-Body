<?php

namespace App\Models;

use App\Services\Commercial\CommercialVocabulary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Qué toca hacer con esta persona, cuándo, por qué, y qué NO hacer todavía.
 *
 * Una oportunidad abierta por sujeto y objetivo. `reason` y `exclusions` no son
 * decorado: son lo que permite que un humano mire una fila y entienda la
 * decisión sin preguntarle a nadie.
 */
class CommercialOpportunity extends Model
{
    protected $fillable = [
        'uuid', 'marketing_lead_id', 'member_id', 'marketing_conversation_id',
        'goal', 'status', 'next_action', 'next_offer',
        'offer_plan_id', 'alternative_plan_id', 'floor_plan_id',
        'priority', 'confidence', 'reason', 'exclusions', 'evidence',
        'act_after', 'expires_at', 'channel',
        'attempts', 'max_attempts', 'last_attempt_at',
        'outcome', 'outcome_reason', 'closed_at',
        'estimated_value', 'realized_value', 'created_by', 'correlation_id',
    ];

    protected $casts = [
        'exclusions' => 'array',
        'evidence' => 'array',
        'act_after' => 'datetime',
        'expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'closed_at' => 'datetime',
        'confidence' => 'float',
        'priority' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'estimated_value' => 'decimal:2',
        'realized_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $opportunity): void {
            $opportunity->uuid ??= (string) Str::uuid();
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'marketing_conversation_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, CommercialVocabulary::OPEN_STATUSES, true);
    }

    /**
     * ¿Se puede actuar sobre esto AHORA?
     *
     * Tres condiciones y las tres importan: que siga viva, que haya llegado su
     * momento, y que no se hayan agotado los intentos. Insistir más allá del
     * máximo es acoso, no seguimiento.
     */
    public function isActionable(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if ($this->attempts >= $this->max_attempts) {
            return false;
        }

        if ($this->act_after !== null && $this->act_after->isFuture()) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Cierra la oportunidad con su resultado, para poder atribuir después.
     *
     * El estado terminal se conserva TAL CUAL cuando el resultado ya es uno de
     * ellos. Aplastar todo lo que no sea `won` contra `lost` mezclaba tres cosas
     * distintas: una oferta rechazada (perdida), una que venció sin respuesta
     * (expirada) y una detenida porque la persona pidió no recibir mensajes
     * (bloqueada). Solo la primera es una derrota comercial; las otras dos
     * ensuciarían cualquier métrica de conversión y, en el caso de la
     * bloqueada, impedirían reabrir el caso si la persona vuelve a escribir.
     */
    public function close(string $outcome, ?string $reason = null, ?float $realizedValue = null): void
    {
        $terminal = [
            CommercialVocabulary::STATUS_WON,
            CommercialVocabulary::STATUS_LOST,
            CommercialVocabulary::STATUS_EXPIRED,
            CommercialVocabulary::STATUS_CANCELLED,
            CommercialVocabulary::STATUS_BLOCKED,
        ];

        $this->forceFill([
            'status' => in_array($outcome, $terminal, true)
                ? $outcome
                : CommercialVocabulary::STATUS_LOST,
            'outcome' => $outcome,
            'outcome_reason' => $reason,
            'realized_value' => $realizedValue,
            'closed_at' => now(),
        ])->save();
    }

    /** Registra un intento y aplaza el siguiente. */
    public function recordAttempt(?\DateTimeInterface $nextAt = null): void
    {
        $this->forceFill([
            'attempts' => $this->attempts + 1,
            'last_attempt_at' => now(),
            'act_after' => $nextAt,
            'status' => CommercialVocabulary::STATUS_IN_PROGRESS,
        ])->save();
    }
}
