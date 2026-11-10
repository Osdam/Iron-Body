<?php

namespace App\Services\Outreach\Run\Channels;

use InvalidArgumentException;

/**
 * Lo que devuelve un canal tras su ÚNICA petición: el estado final del
 * trabajo, el código de detalle (un código, nunca el texto del proveedor) y
 * las referencias que la base guarda una vez (`marketing_message_id`,
 * `voice_call_id`). Inmutable.
 */
final class ChannelAttempt
{
    public const STARTED = 'started';

    public const SIMULATED = 'simulated';

    public const FAILED_TO_START = 'failed_to_start';

    public const AMBIGUOUS = 'ambiguous';

    public const STATUSES = [self::STARTED, self::SIMULATED, self::FAILED_TO_START, self::AMBIGUOUS];

    /** @param  array{marketing_message_id?: int, voice_call_id?: int}  $refs */
    public function __construct(public readonly string $status, public readonly ?string $detailCode = null, public readonly array $refs = [])
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Estado del intento fuera del conjunto cerrado.');
        }
        if ($detailCode !== null && preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $detailCode) !== 1) {
            throw new InvalidArgumentException('El detalle del intento es un código.');
        }
    }

    public static function simulated(): self
    {
        return new self(self::SIMULATED);
    }

    public static function started(array $refs = []): self
    {
        return new self(self::STARTED, null, $refs);
    }

    public static function failed(string $detailCode): self
    {
        return new self(self::FAILED_TO_START, $detailCode);
    }

    public static function ambiguous(string $detailCode): self
    {
        return new self(self::AMBIGUOUS, $detailCode);
    }

    public function wentOut(): bool
    {
        return $this->status === self::STARTED;
    }
}
