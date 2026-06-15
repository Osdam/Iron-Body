<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio de la asistencia a clases (clase no activa,
 * miembro no inscrito, doble marcado, corrección sin registro previo). Lleva el
 * código HTTP para una respuesta clara.
 */
class AttendanceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function classNotActive(): self
    {
        return new self('La clase no está activa: no se puede registrar asistencia.', 409);
    }

    public static function notAParticipant(): self
    {
        return new self('El miembro no está inscrito en esta clase.', 422);
    }

    public static function alreadyMarked(): self
    {
        return new self('La asistencia de este miembro ya fue registrada para esta sesión.', 409);
    }

    public static function notMarked(): self
    {
        return new self('No hay una asistencia registrada para corregir.', 404);
    }
}
