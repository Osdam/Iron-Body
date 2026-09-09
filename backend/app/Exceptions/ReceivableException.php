<?php

namespace App\Exceptions;

use App\Services\Billing\Money;
use RuntimeException;

/**
 * Operación imposible sobre una cuenta por cobrar: abonar más de lo que se
 * debe, abonar sobre una cuenta ya saldada o anulada, o importes que no son
 * dinero.
 *
 * Excepción y no booleano, por la misma razón que {@see CashShiftException}: un
 * `false` que su llamador ignora es un abono fantasma, y con dinero eso se
 * descubre semanas después cuadrando a mano.
 */
class ReceivableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code_ = 'receivable_error',
    ) {
        parent::__construct($message);
    }

    /**
     * Sobrepago. El mensaje lleva el saldo REAL, no un «importe inválido»
     * genérico: quien está en el mostrador con el socio delante necesita saber
     * cuánto cobrar, y su pantalla puede estar mostrando un saldo viejo.
     */
    public static function overpayment(Money $balance): self
    {
        return new self(
            'El saldo pendiente es $'.number_format($balance->toFloat(), 0, ',', '.').'.',
            'overpayment',
        );
    }

    public static function alreadySettled(): self
    {
        return new self('Esta cuenta ya está pagada.', 'already_settled');
    }

    public static function cancelled(): self
    {
        return new self('Esta cuenta está anulada y no admite abonos.', 'receivable_cancelled');
    }

    public static function invalidAmount(): self
    {
        return new self('El abono debe ser mayor que cero.', 'invalid_amount');
    }

    /**
     * El deudor elegido no existe (o no es de ese tipo).
     *
     * Se rechaza en vez de guardar la deuda «a nombre de nadie»: una cuenta por
     * cobrar sin deudor resoluble es una cuenta que no se puede reclamar.
     */
    public static function unknownDebtor(\App\Enums\DebtorType $type): self
    {
        return new self(
            'No encontramos a esa persona en '.$type->label().'.',
            'unknown_debtor',
        );
    }

    public static function invalidTotal(): self
    {
        return new self('El total de la cuenta debe ser mayor que cero.', 'invalid_total');
    }

    /**
     * Doble anulación. Se RECHAZA en vez de devolver el abono tal cual: quien
     * la pide cree estar corrigiendo algo, y responderle «hecho» sin haber
     * hecho nada le deja pensando que arregló un saldo que no tocó.
     */
    public static function alreadyReversed(): self
    {
        return new self('Este abono ya está anulado.', 'already_reversed');
    }

    public static function reversalReasonRequired(): self
    {
        return new self(
            'Explica por qué se anula: sin motivo, la corrección no se puede revisar después.',
            'reversal_reason_required',
        );
    }
}
