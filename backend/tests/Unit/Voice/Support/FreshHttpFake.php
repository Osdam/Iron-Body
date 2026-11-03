<?php

namespace Tests\Unit\Voice\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Un `Http::fake` NUEVO por escenario. Laravel acumula los stubs de cada
 * `Http::fake()` (gana el primero que responde), así que en una prueba con
 * varios escenarios el segundo no sustituiría al primero. Se cambia la fábrica
 * entera y se vuelve a cerrar la red con `preventStrayRequests()`: una
 * petición sin stub sigue rompiendo la prueba.
 */
trait FreshHttpFake
{
    /** @param  callable|array<string, mixed>|null  $stubs */
    protected static function freshFake(callable|array|null $stubs = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }
}
