<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Una reclasificación que no se puede hacer, dicha con su motivo.
 *
 * La lanza {@see PaymentReclassifier} cuando una precondición no se cumple: el
 * procedimiento deshace la transacción entera y el comando sale con código 1.
 * `code_` es estable (el informe JSON y las pruebas lo leen); el mensaje es
 * para quien ejecuta el comando y nunca lleva SQL, rutas ni nombres de host.
 */
final class ReclassificationRejected extends RuntimeException
{
    public function __construct(
        public readonly string $code_,
        string $message,
    ) {
        parent::__construct($message);
    }
}
