<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Services\UltronStudio\Stream\StreamClock;
use Closure;

/**
 * Un reloj que avanza al «dormir»: el bucle entero del compás (sondeos,
 * latidos, cierre a los 55 s) corre en milisegundos de verdad. `onSleep`
 * permite escribir filas ENTRE sondeos, como harían otras peticiones.
 */
final class FakeStreamClock implements StreamClock
{
    public float $now = 1_700_000_000.0;

    public int $sleeps = 0;

    /** @var list<int> */
    public array $sleptMs = [];

    public ?Closure $onSleep = null;

    public int $abortAfterSleeps = PHP_INT_MAX;

    public bool $prepared = false;

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->sleeps++;
        $this->sleptMs[] = $milliseconds;
        $this->now += $milliseconds / 1000;
        if ($this->onSleep !== null) {
            ($this->onSleep)($this->sleeps);
        }
    }

    public function aborted(): bool
    {
        return $this->sleeps >= $this->abortAfterSleeps;
    }

    public function prepare(int $maxSeconds): void
    {
        $this->prepared = true;
    }
}
