<?php

namespace App\Services\Outreach\Core;

/**
 * Lo que dice el presupuesto de contacto de una persona ({@see ContactBudget}),
 * con la forma de `contact-budget-cases.json`: `{allowed, reasons, releaseAt,
 * retryAt, counts {today, rolling7}}`. Inmutable. Todos sus motivos son
 * temporales: si niega, aplaza a `retryAt`.
 */
final class BudgetVerdict
{
    /**
     * @param  list<string>  $reasons  en {@see ContactBudget::REASON_ORDER}
     * @param  array<string, string>  $releaseAt  motivo del presupuesto → instante en que deja de aplicar
     * @param  array{today: int, rolling7: int}  $counts
     */
    public function __construct(
        public readonly array $reasons,
        public readonly array $releaseAt,
        public readonly ?string $retryAt,
        public readonly array $counts,
    ) {}

    public function allowed(): bool
    {
        return $this->reasons === [];
    }

    /** @return array{allowed: bool, reasons: list<string>, releaseAt: array<string, string>, retryAt: string|null, counts: array{today: int, rolling7: int}} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed(), 'reasons' => $this->reasons, 'releaseAt' => $this->releaseAt, 'retryAt' => $this->retryAt, 'counts' => $this->counts];
    }
}
