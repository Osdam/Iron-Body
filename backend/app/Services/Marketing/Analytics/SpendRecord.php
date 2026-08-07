<?php

namespace App\Services\Marketing\Analytics;

/**
 * Un importe de gasto con todo lo que hace falta para poder auditarlo.
 *
 * La cantidad sola no basta. Un gasto sin período no se puede comparar con unos
 * ingresos; sin moneda no se puede sumar; sin fecha de sincronización no se
 * sabe si son datos de hoy o de hace tres semanas; y sin fiabilidad no se sabe
 * si vienen de la API de Meta o de que alguien los escribió a mano.
 */
final class SpendRecord
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly \DateTimeInterface $periodStart,
        public readonly \DateTimeInterface $periodEnd,
        public readonly string $source,
        public readonly ?\DateTimeInterface $syncedAt,
        /** high|medium|low: de una API, de un informe manual, o estimado. */
        public readonly string $reliability = 'high',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'period_start' => $this->periodStart->format('c'),
            'period_end' => $this->periodEnd->format('c'),
            'source' => $this->source,
            'synced_at' => $this->syncedAt?->format('c'),
            'reliability' => $this->reliability,
        ];
    }
}
