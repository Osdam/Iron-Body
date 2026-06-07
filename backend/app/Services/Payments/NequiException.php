<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Error controlado del proveedor Nequi directo. Mensaje apto para mostrar al
 * usuario (sin secretos). `$unavailable=true` indica que Nequi directo no está
 * habilitado/configurado (la app debe ofrecer otro método), no un fallo de pago.
 */
class NequiException extends RuntimeException
{
    public function __construct(
        string $message,
        public bool $unavailable = false,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(string $message): self
    {
        return new self($message, unavailable: true);
    }
}
