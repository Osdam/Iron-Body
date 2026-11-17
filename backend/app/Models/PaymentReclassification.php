<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * La constancia de que un cobro se reclasificó: qué era, qué pasó a ser, cuándo
 * entró el dinero, cuándo se corrigió, quién y por qué.
 *
 * INMUTABLE. Una reclasificación equivocada no se edita ni se borra: se
 * compensa con otra operación documentada. Es la misma regla que los recibos.
 */
class PaymentReclassification extends Model
{
    /** El dinero era el piso de un entrenador personalizado. */
    public const KIND_TRAINER_COMMISSION = 'trainer_commission';

    protected $fillable = [
        'payment_id', 'kind', 'amount', 'method', 'economic_at', 'cash_shift_id',
        'agreement_id', 'charge_id', 'receivable_id', 'receivable_payment_id',
        'original_receipt_id', 'cancelled_invoice_id',
        'membership_end_before', 'membership_end_after', 'membership_days_removed',
        'reason', 'performed_by', 'performed_by_name', 'performed_at', 'procedure',
        'before_snapshot', 'after_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'economic_at' => 'datetime',
            'performed_at' => 'datetime',
            'membership_end_before' => 'date',
            'membership_end_after' => 'date',
            'membership_days_removed' => 'integer',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r): void {
            throw new LogicException('Una reclasificación no se modifica: se compensa con otra operación documentada.');
        });

        static::deleting(function (self $r): void {
            throw new LogicException('Una reclasificación no se borra: es la constancia de una corrección financiera.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(TrainerCommissionAgreement::class, 'agreement_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(TrainerCommissionCharge::class, 'charge_id');
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function receivablePayment(): BelongsTo
    {
        return $this->belongsTo(ReceivablePayment::class);
    }

    public function originalReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'original_receipt_id');
    }

    public function cancelledInvoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class, 'cancelled_invoice_id');
    }

    /** Lo que se lee en caja, en el historial y en los informes. */
    public function label(): string
    {
        return match ($this->kind) {
            self::KIND_TRAINER_COMMISSION => 'Reclasificado a piso de entrenador',
            default => 'Reclasificado',
        };
    }
}
