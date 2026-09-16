<?php

namespace App\Services\Marketing\Ultron;

use RuntimeException;

/**
 * Motivo por el que una propuesta de ULTRON no se ejecuta.
 *
 * Lleva un código estable porque quien está al otro lado es un workflow, no una
 * persona: n8n necesita distinguir «vuelve a pedir contexto» de «esto ya se
 * hizo» de «lo que propusiste no es legal», y un 422 con prosa no sirve para eso.
 *
 * `$detail` lleva solo datos ya conocidos por el llamante (la transición que
 * propuso, las que había) — nunca contenido de la conversación.
 */
class UltronCommitException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $detail = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, int $status = 422, array $detail = []): self
    {
        return new self($code, $message, $status, $detail);
    }
}
