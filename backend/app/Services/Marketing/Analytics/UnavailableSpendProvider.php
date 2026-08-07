<?php

namespace App\Services\Marketing\Analytics;

/**
 * La implementación de hoy: no hay gasto, y se dice.
 *
 * Es deliberadamente la única que existe. Mientras no haya una integración real
 * con Meta Ads, cualquier cifra de gasto sería inventada, y una cifra inventada
 * en un panel de rentabilidad es peor que un hueco: el hueco se pregunta, la
 * cifra se cree.
 */
class UnavailableSpendProvider implements AdvertisingSpendProvider
{
    public function spendFor(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function sourceName(): string
    {
        return 'unavailable';
    }
}
