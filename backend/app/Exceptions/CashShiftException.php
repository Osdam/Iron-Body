<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operación de caja imposible en el estado actual del turno: cobrar sin turno
 * abierto, abrir uno cuando ya hay otro, o cerrar el de otra persona sin ser
 * supervisor.
 *
 * Es una excepción y no un booleano descartable a propósito: el antecedente en
 * este mismo módulo fue un `false` que su llamador ignoraba, y dejaba ventas
 * cobradas sin descontar existencias.
 */
class CashShiftException extends RuntimeException
{
    public function __construct(string $message, public readonly string $code_ = 'cash_shift_error')
    {
        parent::__construct($message);
    }

    public static function noOpenShift(): self
    {
        return new self(
            'No hay una caja abierta. Abre el turno antes de registrar cobros.',
            'no_open_shift',
        );
    }

    public static function alreadyOpen(string $who): self
    {
        return new self(
            "Ya hay una caja abierta por {$who}. Debe cerrarse antes de abrir otro turno.",
            'shift_already_open',
        );
    }

    public static function notOwner(): self
    {
        return new self(
            'Este turno lo abrió otra persona. Para cerrarlo hace falta permiso de gestión de caja.',
            'not_shift_owner',
        );
    }
}
