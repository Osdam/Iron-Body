<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando se intenta enlazar un perfil (miembro o entrenador) a una
 * identidad sin la verificación de propiedad requerida (OTP al teléfono
 * registrado). Conocer el documento NUNCA basta para vincular perfiles.
 */
class IdentityLinkException extends RuntimeException
{
    public static function ownershipNotVerified(): self
    {
        return new self('El enlace de perfiles requiere verificación de propiedad por OTP.');
    }
}
