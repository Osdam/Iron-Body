<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de negocio del flujo 2FA (código inválido, expirado, bloqueado, etc.).
 * Lleva el código HTTP a devolver y datos extra (intentos restantes, cooldown)
 * para que el controlador arme una respuesta clara para la app.
 */
class OtpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
