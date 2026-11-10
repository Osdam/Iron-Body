<?php

namespace App\Services\UltronStudio\Stream;

/**
 * El tiempo y la espera del compás, separados para poder probar el bucle
 * entero (latido, cierre a los 55 s, sondeos) sin esperar 55 segundos.
 */
interface StreamClock
{
    /** Segundos con fracción (como microtime(true)). */
    public function now(): float;

    public function sleep(int $milliseconds): void;

    /** El cliente cerró la conexión. */
    public function aborted(): bool;

    /** Antes de emitir: tope de ejecución y búferes de salida fuera. */
    public function prepare(int $maxSeconds): void;
}
