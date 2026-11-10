<?php

namespace App\Services\Outreach\Core;

/**
 * El resultado de evaluar una condición (contrato H9, D9-5): el valor de tres
 * estados (null = desconocido) y su traza explicable. Inmutable.
 */
final class ConditionResult
{
    /**
     * @param  list<array<string,mixed>>  $trace
     */
    public function __construct(public readonly ?bool $value, public readonly array $trace) {}

    /** `true`, `false` o `unknown`. */
    public function label(): string
    {
        return ConditionEvaluator::label($this->value);
    }

    public function isTrue(): bool
    {
        return $this->value === true;
    }

    /** sha256 del JSON canónico de la traza (T12: la misma entrada da la misma huella). */
    public function traceHash(): string
    {
        return CanonicalJson::hash($this->trace);
    }
}
