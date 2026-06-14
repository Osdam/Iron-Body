<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violación de una regla de negocio de las valoraciones profesionales (editar
 * una enviada, enmendar un borrador, anular dos veces, etc.). Lleva el código
 * HTTP a devolver para una respuesta clara.
 */
class AssessmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notEditable(): self
    {
        return new self('Esta valoración ya fue enviada y no se puede editar. Crea una corrección.', 409);
    }

    public static function notSubmittable(): self
    {
        return new self('Solo un borrador puede enviarse.', 409);
    }

    public static function notAmendable(): self
    {
        return new self('Solo una valoración enviada puede corregirse.', 409);
    }

    public static function amendmentReasonRequired(): self
    {
        return new self('Indica el motivo de la corrección.', 422);
    }
}
