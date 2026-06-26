<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Transacción de pasarela de pago (Wompi). Fuente de verdad del estado del
 * pago para la app. `raw_response` guarda el último payload recibido del
 * proveedor (sin datos de tarjeta — la app tokeniza y solo viaja el token).
 */
class PaymentTransaction extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_EXPIRED    = 'expired';

    // Estados adicionales de la máquina Wompi (provider='wompi'). Los registros
    // legados siguen usando el set de arriba; estos son aditivos.
    public const STATUS_CREATED         = 'created';
    public const STATUS_TOKENIZING      = 'tokenizing';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_DECLINED        = 'declined';
    public const STATUS_VOIDED          = 'voided';
    public const STATUS_ERROR           = 'error';

    protected $fillable = [
        'reference', 'idempotency_key', 'order_id', 'member_id', 'user_id', 'plan_id',
        'amount', 'currency', 'status', 'provider', 'method', 'provider_ref',
        'checkout_url', 'description', 'failure_reason', 'customer',
        'raw_response', 'paid_at',
        // Wompi (aditivo).
        'uuid', 'environment', 'wompi_transaction_id', 'status_message',
        'processor_response_code', 'customer_email', 'customer_phone',
        'customer_legal_id_type', 'customer_legal_id', 'external_auth_url',
        'redirect_url', 'approved_at', 'declined_at', 'voided_at', 'failed_at',
        'expires_at', 'last_reconciled_at', 'retry_count', 'card_brand',
        'card_last_four', 'installments', 'metadata',
    ];

    protected $casts = [
        'amount'       => 'float',
        'customer'     => 'array',
        'raw_response' => 'array',
        'paid_at'      => 'datetime',
        // Wompi (aditivo).
        'metadata'           => 'array',
        'approved_at'        => 'datetime',
        'declined_at'        => 'datetime',
        'voided_at'          => 'datetime',
        'failed_at'          => 'datetime',
        'expires_at'         => 'datetime',
        'last_reconciled_at' => 'datetime',
        'retry_count'        => 'integer',
        'installments'       => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /** Estado finalizado: no admite reintento sobre la misma referencia. */
    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ], true);
    }

    /** Hay un intento en curso: se reutiliza la misma transacción/checkout. */
    public function isInFlight(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true);
    }

    /** Respuesta segura para la app (sin secretos, sin datos sensibles). */
    public function toPublicArray(): array
    {
        return [
            'ok'           => !in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED], true),
            'transaction_id' => $this->provider_ref ?: (string) $this->id,
            'reference'    => $this->reference,
            'status'       => $this->status,
            'member_id'    => $this->member_id,
            'user_id'      => $this->user_id,
            'plan_id'      => $this->plan_id,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'provider'     => $this->provider,
            'method'       => $this->method,
            'provider_ref' => $this->provider_ref,
            'checkout_url' => $this->checkout_url,
            // Smart Checkout v2 (Nequi/DaviPlata): flujo + sesión + URL del bridge
            // (WebView). Aditivo; null en tarjeta/PSE.
            'flow' => is_array($this->raw_response) ? ($this->raw_response['flow'] ?? null) : null,
            'session_id' => is_array($this->raw_response) ? ($this->raw_response['session_id'] ?? null) : null,
            'checkout_bridge_url' => is_array($this->raw_response) && ($this->raw_response['flow'] ?? null) === 'smart_checkout'
                ? $this->checkout_url
                : null,
            // URL del portal del banco (PSE) para autorizar DENTRO de la app
            // (WebView interno). No es un secreto; no se loguea completa.
            'authorization_url' => is_array($this->raw_response)
                ? ($this->raw_response['urlbanco'] ?? null)
                : null,
            'description'  => $this->description,
            'product'      => $this->description,
            'user_name'    => is_array($this->customer)
                ? ($this->customer['name'] ?? null)
                : null,
            'reason'       => $this->failure_reason,
            'paid_at'      => optional($this->paid_at)->toIso8601String(),
            'created_at'   => optional($this->created_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Respuesta pública para la app en el flujo WOMPI. Estados canónicos de la
     * máquina de estados (created|tokenizing|pending|requires_action|approved|
     * declined|voided|error|expired). SIN secretos: ni token, ni llaves, ni
     * payload crudo. Incluye la URL de autenticación EXTERNA oficial (PSE/3DS)
     * y datos NO sensibles de tarjeta (marca, últimos 4, cuotas).
     */
    public function toWompiPublicArray(): array
    {
        $isFailedLike = in_array($this->status, [
            self::STATUS_DECLINED, self::STATUS_VOIDED, self::STATUS_ERROR,
            self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED,
        ], true);

        $isFinal = $this->isWompiFinal();
        $action = $this->wompiAction($isFinal);

        return [
            'ok'                  => ! $isFailedLike,
            'reference'           => $this->reference,
            'uuid'                => $this->uuid,
            'status'              => $this->status,
            'is_final'            => $isFinal,
            // Contrato explícito que la app conmuta por método + action.type.
            // type ∈ wait_for_confirmation|external_browser|open_nequi_app|daviplata_otp|none
            'action'              => $action,
            'status_message'      => $this->status_message,
            'reason'              => $this->status_message ?: $this->failure_reason,
            'amount'              => (float) $this->amount,
            'amount_in_cents'     => (int) round((float) $this->amount * 100),
            'currency'            => $this->currency,
            'provider'            => $this->provider,
            'environment'         => $this->environment,
            'payment_method'      => $this->method ? strtoupper($this->method) : null,
            'method'              => $this->method,
            'transaction_id'      => $this->wompi_transaction_id ?: $this->provider_ref,
            'wompi_transaction_id'=> $this->wompi_transaction_id,
            // URL del banco para PSE (solo cuando action.type=external_browser).
            // Se abre en el NAVEGADOR EXTERNO del sistema, nunca en un WebView.
            'external_auth_url'   => $action['type'] === 'external_browser' ? $action['url'] : null,
            'card_brand'          => $this->card_brand,
            'card_last_four'      => $this->card_last_four,
            'installments'        => $this->installments,
            'member_id'           => $this->member_id,
            'plan_id'             => $this->plan_id,
            'description'         => $this->description,
            'product'             => $this->description,
            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'paid_at'             => optional($this->paid_at)->toIso8601String(),
            'expires_at'          => optional($this->expires_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /** ¿El pago Wompi está en un estado FINAL (terminal)? */
    public function isWompiFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_VOIDED,
            self::STATUS_ERROR, self::STATUS_EXPIRED,
            // Equivalentes legados:
            self::STATUS_FAILED, self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Acción que la app debe ejecutar, EXPLÍCITA por método. NUNCA devuelve una
     * acción de WebView. El cliente conmuta por `action.type`:
     *   - none                 → estado final, ir a la pantalla de resultado.
     *   - wait_for_confirmation→ espera NATIVA + polling (tarjeta / PSE sin URL).
     *   - external_browser     → abrir la URL del banco en el NAVEGADOR EXTERNO (PSE).
     *   - open_nequi_app       → mensaje "abre Nequi y aprueba" + polling.
     *   - daviplata_otp        → OTP nativo (endpoints OTP del backend).
     *
     * @return array{type:string,url:?string}
     */
    public function wompiAction(?bool $isFinal = null): array
    {
        $isFinal ??= $this->isWompiFinal();
        if ($isFinal) {
            return ['type' => 'none', 'url' => null];
        }

        $method = strtolower((string) $this->method);

        return match ($method) {
            // PSE: navegador externo SOLO si hay URL real del banco; si aún no la
            // hay (creándose), espera nativa.
            'pse' => ($this->status === self::STATUS_REQUIRES_ACTION && $this->external_auth_url)
                ? ['type' => 'external_browser', 'url' => $this->external_auth_url]
                : ['type' => 'wait_for_confirmation', 'url' => null],
            'nequi'     => ['type' => 'open_nequi_app', 'url' => null],
            'daviplata' => ['type' => 'daviplata_otp', 'url' => null],
            // CARD (y cualquier otro): SIEMPRE espera nativa, jamás navegador/WebView.
            default     => ['type' => 'wait_for_confirmation', 'url' => null],
        };
    }
}
