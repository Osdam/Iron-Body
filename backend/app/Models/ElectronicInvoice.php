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
}
