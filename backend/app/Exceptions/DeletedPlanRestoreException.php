<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una precondición de `plans:restore-deleted` que no se cumple.
 *
 * Se lanza ANTES de escribir o con la transacción todavía abierta: quien la
 * recibe sabe que no quedó nada escrito. `code_` dice cuál falló, para que las
 * pruebas y quien opera distingan «el snapshot es de otro plan» de «ya existe
 * un plan con ese id».
 */
class DeletedPlanRestoreException extends RuntimeException
{
    public function __construct(string $message, public readonly string $code_)
    {
        parent::__construct($message);
    }
}
