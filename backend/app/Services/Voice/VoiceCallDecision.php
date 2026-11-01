<?php

namespace App\Services\Voice;

use InvalidArgumentException;

/**
 * La decisión de {@see VoiceCallPolicy} (contrato H6 §I6.5): `allowed` es
 * exactamente «sin motivos», y los motivos van en el orden de
 * {@see VoiceCallPolicy::REASONS}, sin repetir. El constructor lo exige: una
 * decisión que dijera «sí» con motivos, o «no» sin ellos, no puede existir.
 */
final class VoiceCallDecision
{
    /** @param list<string> $reasons en el orden de VoiceCallPolicy::REASONS */
    public function __construct(public readonly bool $allowed, public readonly array $reasons)
    {
        if (! array_is_list($reasons) || array_values(array_intersect(VoiceCallPolicy::REASONS, $reasons)) !== $reasons) {
            throw new InvalidArgumentException('Los motivos tienen que ser un subconjunto ordenado de VoiceCallPolicy::REASONS.');
        }
        if ($allowed !== ($reasons === [])) {
            throw new InvalidArgumentException('Una llamada se permite si y solo si no hay ningún motivo en contra.');
        }
    }

    /** @return array{allowed:bool, reasons:list<string>} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reasons' => $this->reasons];
    }
}
