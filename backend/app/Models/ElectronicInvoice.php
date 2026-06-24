<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Comprobante electrónico DIAN (factura o nota crédito) emitido vía Factus.
 *
 * Es un agregado propio: el pago/venta lo dispara, pero su ciclo de vida y
 * reintentos son independientes (best-effort, no bloquea el cobro). La
 * idempotencia dura es (source_type, source_id, type) — ver migración.
 */
class ElectronicInvoice extends Model
{
    protected $fillable = [
        'uuid', 'source_type', 'source_id', 'type', 'references_invoice_id', 'status',
        'numbering_range_id', 'prefix', 'number', 'full_number',
        'factus_id', 'cufe', 'dian_status', 'validated_at', 'qr_url', 'qr_data',
        'pdf_path', 'pdf_url', 'xml_path', 'xml_url',
        'customer_doc_type', 'customer_doc_number', 'customer_dv', 'customer_name',
        'customer_email', 'customer_phone', 'customer_address',
        'customer_city_code', 'customer_department_code', 'is_final_consumer',
        'currency', 'subtotal', 'discount', 'tax_total', 'total',
        'request_payload', 'response_payload', 'failure_reason',
        'retry_count', 'last_attempt_at', 'issued_at', 'created_by_admin_id',
    ];

    protected $casts = [
        'type'              => InvoiceType::class,
        'status'            => InvoiceStatus::class,
        'is_final_consumer' => 'boolean',
        'subtotal'          => 'decimal:2',
        'discount'          => 'decimal:2',
        'tax_total'         => 'decimal:2',
        'total'             => 'decimal:2',
        'request_payload'   => 'array',
        'response_payload'  => 'array',
        'retry_count'       => 'integer',
        'validated_at'      => 'datetime',
        'last_attempt_at'   => 'datetime',
        'issued_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ElectronicInvoice $inv): void {
            $inv->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relaciones ──────────────────────────────────────────────────────────

    /** Fuente facturada (Payment | ProductSale). */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Factura original que esta nota crédito anula. */
    public function referencesInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'references_invoice_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceLog::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', InvoiceStatus::PENDING->value);
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->whereIn('status', [InvoiceStatus::ERROR->value, InvoiceStatus::CREDIT_NOTE_ERROR->value]);
    }

    public function scopeProcessing(Builder $q): Builder
    {
        return $q->whereIn('status', [InvoiceStatus::PROCESSING->value, InvoiceStatus::CREDIT_NOTE_PROCESSING->value]);
    }

    /** Filtros del listado admin. Ignora claves vacías. */
    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', \App\Services\Billing\InvoicingService::SOURCE_MAP[$v] ?? $v))
            ->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($f['number'] ?? null, fn ($q, $v) => $q->where('full_number', 'like', "%{$v}%"))
            ->when($f['cufe'] ?? null, fn ($q, $v) => $q->where('cufe', 'like', "%{$v}%"))
            ->when($f['document'] ?? null, fn ($q, $v) => $q->where('customer_doc_number', 'like', "%{$v}%"))
            ->when($f['customer'] ?? null, fn ($q, $v) => $q->where('customer_name', 'like', "%{$v}%"))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    // ── Transiciones de estado (idempotentes) ───────────────────────────────

    public function markProcessing(): void
    {
        $this->forceFill([
            'status'          => $this->type === InvoiceType::CREDIT_NOTE
                ? InvoiceStatus::CREDIT_NOTE_PROCESSING
                : InvoiceStatus::PROCESSING,
            'last_attempt_at' => now(),
        ])->save();
    }

    public function markValidated(array $attrs = []): void
    {
        $this->forceFill(array_merge($attrs, [
            'status'       => $this->type === InvoiceType::CREDIT_NOTE
                ? InvoiceStatus::CREDIT_NOTE_VALIDATED
                : InvoiceStatus::VALIDATED,
            'validated_at' => $attrs['validated_at'] ?? now(),
            'issued_at'    => $this->issued_at ?? now(),
        ]))->save();
    }

    public function markRejected(?string $reason = null, array $attrs = []): void
    {
        $this->forceFill(array_merge($attrs, [
            'status'         => $this->type === InvoiceType::CREDIT_NOTE
                ? InvoiceStatus::CREDIT_NOTE_REJECTED
                : InvoiceStatus::REJECTED,
            'failure_reason' => $reason,
        ]))->save();
    }

    public function markError(?string $reason = null): void
    {
        $this->forceFill([
            'status'         => $this->type === InvoiceType::CREDIT_NOTE
                ? InvoiceStatus::CREDIT_NOTE_ERROR
                : InvoiceStatus::ERROR,
            'failure_reason' => $reason,
            'retry_count'    => $this->retry_count + 1,
        ])->save();
    }

    // ── Serialización ───────────────────────────────────────────────────────

    /** Detalle para el CRM admin. No expone payloads crudos con secretos. */
    public function toAdminArray(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'type'        => $this->type->value,
            'status'      => $this->status->value,
            'source_type' => $this->source_type,
            'source_id'   => $this->source_id,
            'full_number' => $this->full_number,
            'cufe'        => $this->cufe,
            'dian_status' => $this->dian_status,
            'customer'    => [
                'doc_type'   => $this->customer_doc_type,
                'doc_number' => $this->customer_doc_number,
                'name'       => $this->customer_name,
                'email'      => $this->customer_email,
                'final'      => (bool) $this->is_final_consumer,
            ],
            'currency'    => $this->currency,
            'subtotal'    => (float) $this->subtotal,
            'discount'    => (float) $this->discount,
            'tax_total'   => (float) $this->tax_total,
            'total'       => (float) $this->total,
            'has_pdf'     => (bool) ($this->pdf_path || $this->pdf_url),
            'has_xml'     => (bool) ($this->xml_path || $this->xml_url),
            'qr_url'      => $this->qr_url,
            'failure_reason' => $this->failure_reason,
            'retry_count' => (int) $this->retry_count,
            'issued_at'   => optional($this->issued_at)->toIso8601String(),
            'created_at'  => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Detalle completo para el CRM admin: cabecera + cliente + montos + resumen
     * del source + items (si hay request saneado) + logs YA SANEADOS. NUNCA
     * expone payloads crudos con secretos ni rutas internas de archivo.
     */
    public function toAdminDetailArray(): array
    {
        $this->loadMissing('logs');

        $items = is_array($this->request_payload['items'] ?? null)
            ? $this->request_payload['items']
            : [];

        return array_merge($this->toAdminArray(), [
            'prefix'                => $this->prefix,
            'number'                => $this->number,
            'qr_data'               => $this->qr_data,
            'validated_at'          => optional($this->validated_at)->toIso8601String(),
            'last_attempt_at'       => optional($this->last_attempt_at)->toIso8601String(),
            'references_invoice_id' => $this->references_invoice_id,
            'customer_full' => [
                'doc_type'        => $this->customer_doc_type,
                'doc_number'      => $this->customer_doc_number,
                'dv'              => $this->customer_dv,
                'name'            => $this->customer_name,
                'email'           => $this->customer_email,
                'phone'           => $this->customer_phone,
                'address'         => $this->customer_address,
                'city_code'       => $this->customer_city_code,
                'department_code' => $this->customer_department_code,
                'final'           => (bool) $this->is_final_consumer,
            ],
            'fiscal_summary' => [
                'currency'  => $this->currency,
                'subtotal'  => (float) $this->subtotal,
                'discount'  => (float) $this->discount,
                'tax_total' => (float) $this->tax_total,
                'total'     => (float) $this->total,
            ],
            'items'  => $items,
            'source' => $this->sourceSummary(),
            'logs'   => $this->logs->map(fn (ElectronicInvoiceLog $l) => [
                'action'      => $l->action?->value,
                'result'      => $l->result,
                'endpoint'    => $l->endpoint,
                'http_status' => $l->http_status,
                'message'     => $l->message,
                'excerpt'     => $l->payload_excerpt, // saneado en escritura
                'created_at'  => optional($l->created_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Resumen liviano de la fuente facturada (sin datos sensibles). */
    private function sourceSummary(): array
    {
        $source = $this->source; // morphTo (Payment | ProductSale)
        if ($source === null) {
            return ['type' => $this->source_type, 'id' => $this->source_id, 'label' => null];
        }
        if ($source instanceof ProductSale) {
            return [
                'type' => 'product_sale', 'id' => $source->id, 'label' => $source->code,
                'total' => (float) $source->total, 'channel' => $source->channel,
            ];
        }

        return [
            'type' => 'payment', 'id' => $source->id, 'label' => $source->reference,
            'amount' => (float) $source->amount, 'method' => $source->method, 'status' => $source->status,
        ];
    }
}