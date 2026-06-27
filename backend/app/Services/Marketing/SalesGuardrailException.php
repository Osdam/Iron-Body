<?php

namespace App\Services\Marketing;

use RuntimeException;

/**
 * Violación de un guardrail comercial (pago/contacto). Lleva un `code` estable
 * (para que el caller/n8n reaccione) y un mensaje YA saneado para el cliente.
 * `escalate` indica si el caso debería derivarse a un humano.
 */
class SalesGuardrailException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $escalate = false,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, bool $escalate = false, int $httpStatus = 422): self
    {
        return new self($code, $message, $escalate, $httpStatus);
    }
}
