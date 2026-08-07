<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Algo concreto que alguien tiene que mirar.
 *
 * La diferencia con un insight, que es la que justifica esta tabla: un insight
 * explica una tendencia y se recalcula; una alerta señala a una persona
 * esperando y se cierra. «Esta campaña convierte poco» es lo primero. «Este
 * cliente lleva tres días con un pago a medias» es lo segundo.
 */
class CommercialAlert extends Model
{
    // ── Tipos ────────────────────────────────────────────────────────────

    /** La pauta promete algo que ya no está en el catálogo. */
    public const TYPE_OUTDATED_AD = 'pauta_desactualizada';

    public const TYPE_PRICE_MISMATCH = 'precio_pauta_catalogo_inconsistente';

    public const TYPE_HIGH_INTENT_IDLE = 'alta_intencion_sin_accion';

    public const TYPE_PAYMENT_PENDING = 'pago_pendiente';

    public const TYPE_REPEATED_DECLINE = 'pago_rechazado_repetidamente';

    public const TYPE_RENEWAL_DUE = 'renovacion_proxima_sin_accion';

    public const TYPE_OPPORTUNITY_EXPIRED = 'oportunidad_vencida';

    public const TYPE_CUSTOMER_UNATTENDED = 'cliente_desatendido';

    public const TYPE_ATTRIBUTION_GAP = 'atribucion_desconocida_elevada';

    public const TYPE_TOOL_FAILING = 'herramienta_comercial_fallando';

    public const TYPE_NO_REPLY = 'conversacion_sin_respuesta';

    public const TYPE_HOT_LEAD_IDLE = 'lead_caliente_sin_seguimiento';

    public const TYPES = [
        self::TYPE_OUTDATED_AD, self::TYPE_PRICE_MISMATCH, self::TYPE_HIGH_INTENT_IDLE,
        self::TYPE_PAYMENT_PENDING, self::TYPE_REPEATED_DECLINE, self::TYPE_RENEWAL_DUE,
        self::TYPE_OPPORTUNITY_EXPIRED, self::TYPE_CUSTOMER_UNATTENDED,
        self::TYPE_ATTRIBUTION_GAP, self::TYPE_TOOL_FAILING, self::TYPE_NO_REPLY,
        self::TYPE_HOT_LEAD_IDLE,
    ];

    // ── Severidad ────────────────────────────────────────────────────────

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    // ── Estados ──────────────────────────────────────────────────────────

    public const STATUS_OPEN = 'open';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RESOLVED = 'resolved';

    /** Se decidió no hacer nada, y consta por qué. */
    public const STATUS_IGNORED = 'ignored';

    /** Dejó de aplicar sola: el pago entró, alguien contestó. */
    public const STATUS_AUTO_CLOSED = 'auto_closed';

    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_ASSIGNED];

    protected $fillable = [
        'type', 'severity', 'status',
        'marketing_lead_id', 'member_id', 'marketing_conversation_id',
        'commercial_opportunity_id', 'campaign_name', 'ad_id',
        'title', 'summary', 'evidence', 'suggested_action', 'opportunity_value',
        'detected_at', 'due_at', 'owner_admin_id',
        'resolved_at', 'resolution', 'resolution_note', 'fingerprint',
    ];

    protected $casts = [
        'evidence' => 'array',
        'opportunity_value' => 'decimal:2',
        'detected_at' => 'datetime',
        'due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Se pasó de su plazo y sigue abierta. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }
}
