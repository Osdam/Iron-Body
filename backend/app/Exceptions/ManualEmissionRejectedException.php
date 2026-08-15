<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La emisión manual de un comprobante no procede, y se sabe por qué.
 *
 * Lleva su propio código HTTP porque el CRM necesita distinguir dos cosas que
 * para el operador son muy distintas:
 *
 *   - 422: este pago NO es facturable (no está cobrado, faltan datos fiscales).
 *     Hay algo que corregir antes de volver a intentarlo.
 *   - 409: el estado actual impide emitir AHORA (ya hay documento fiscal, o hay
 *     una emisión en curso). No hay nada que corregir; hay que mirar la factura
 *     que ya existe.
 *
 * El mensaje se muestra tal cual en el modal, así que se escribe para el
 * operador, no para el log.
 */
class ManualEmissionRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /** El pago no es facturable: hay que corregir algo primero. */
    public static function noFacturable(string $message): self
    {
        return new self($message, 422);
    }

    /** El estado actual impide emitir ahora mismo (duplicado / en curso). */
    public static function conflicto(string $message): self
    {
        return new self($message, 409);
    }
}
