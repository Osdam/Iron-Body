<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un recibo de caja que no se puede emitir, o una escritura que su contrato
 * prohíbe.
 *
 * `code_` viaja tal cual al CRM (mismo patrón que CashShiftException) y `reason`
 * dice POR QUÉ una operación no es imprimible, para que la pantalla lo explique
 * en vez de pintar un error genérico.
 */
class ReceiptException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code_ = 'receipt_error',
        public readonly ?string $reason = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
    ) {
        parent::__construct($message);
    }

    /** La operación existe pero no es dinero recibido en una caja física. */
    public static function notPrintable(string $reason, string $message, string $sourceType, int $sourceId): self
    {
        return new self($message, 'not_printable', $reason, $sourceType, $sourceId);
    }

    public static function sourceNotFound(string $sourceType, int $sourceId): self
    {
        return new self('No existe la operación indicada.', 'not_found', null, $sourceType, $sourceId);
    }

    /** Programación defensiva: nadie debe intentarlo, y si lo intenta, se nota. */
    public static function immutable(?string $number): self
    {
        return new self(
            'Un recibo emitido no se modifica ni se borra'.($number ? " ({$number})" : '').'.',
            'receipt_immutable',
        );
    }

    public static function printImmutable(): self
    {
        return new self(
            'Un intento de impresión solo se cierra una vez, desde «started», y nunca se borra.',
            'print_immutable',
        );
    }
}
