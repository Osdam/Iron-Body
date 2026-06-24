<?php

namespace App\Enums;

/**
 * Ciclo de vida de un comprobante electrónico (factura o nota crédito).
 *
 * Factura:
 *   pending -> processing -> validated | rejected | error -> (reintento) processing
 *   cancelled: la factura fue anulada por una nota crédito ya validada.
 *
 * Nota crédito (estados propios para no confundir el tablero):
 *   credit_note_pending -> credit_note_processing -> credit_note_validated
 *                                                  | credit_note_rejected
 *                                                  | credit_note_error
 *
 * Semántica de reintento:
 *   - ERROR  (técnico/red/timeout): reintenta con backoff.
 *   - REJECTED (DIAN/datos): NO reintenta solo; requiere corrección manual.
 */
enum InvoiceStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case VALIDATED  = 'validated';
    case REJECTED   = 'rejected';
    case ERROR      = 'error';
    case CANCELLED  = 'cancelled';

    case CREDIT_NOTE_PENDING    = 'credit_note_pending';
    case CREDIT_NOTE_PROCESSING = 'credit_note_processing';
    case CREDIT_NOTE_VALIDATED  = 'credit_note_validated';
    case CREDIT_NOTE_REJECTED   = 'credit_note_rejected';
    case CREDIT_NOTE_ERROR      = 'credit_note_error';

    /** Estado terminal: no se reintenta automáticamente. */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::VALIDATED,
            self::CANCELLED,
            self::CREDIT_NOTE_VALIDATED,
        ], true);
    }

    /** ¿El job puede reintentar la emisión desde este estado? */
    public function canRetry(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::ERROR,
            self::CREDIT_NOTE_PENDING,
            self::CREDIT_NOTE_ERROR,
        ], true);
    }

    /** ¿La emisión está en curso (para reconciliación)? */
    public function isProcessing(): bool
    {
        return in_array($this, [self::PROCESSING, self::CREDIT_NOTE_PROCESSING], true);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
