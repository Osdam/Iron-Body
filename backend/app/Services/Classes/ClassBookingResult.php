<?php

namespace App\Services\Classes;

/**
 * Lo que pasó al inscribir o quitar a alguien desde el mostrador.
 *
 * `ok` dice si el efecto pedido EXISTE ahora —inscrito o quitado—, venga de esta
 * petición o de una anterior. Por eso «ya estaba» es `ok`: un reintento no puede
 * convertirse en un error para quien atiende.
 */
final class ClassBookingResult
{
    private function __construct(
        public readonly bool $ok,
        /** reserved | already | removed, o el motivo del rechazo. */
        public readonly string $outcome,
        /** La ocurrencia sobre la que se actuó (Y-m-d). */
        public readonly string $date,
    ) {}

    public static function done(string $outcome, string $date): self
    {
        return new self(true, $outcome, $date);
    }

    public static function rejected(string $reason, string $date): self
    {
        return new self(false, $reason, $date);
    }
}
