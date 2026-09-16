<?php

namespace App\Services\Marketing\Ultron;

/**
 * De dónde nace una decisión de ULTRON.
 *
 * Existe como vocabulario cerrado porque `/ai/commit` sirve a más de un origen
 * y la idempotencia se calcula distinto en cada uno: un mensaje entrante se
 * identifica por el `wamid` de Meta, que es estable entre reentregas; un
 * seguimiento (v2) por su id y su número de intento, porque el mismo
 * seguimiento sí puede reintentarse a propósito.
 */
final class UltronSource
{
    public const INBOUND_MESSAGE = 'inbound_message';

    /** Reservado para la v2. La v1 es reactiva y no lo emite. */
    public const FOLLOWUP = 'followup';

    public const ALL = [self::INBOUND_MESSAGE, self::FOLLOWUP];

    /** Lo que la v1 acepta hoy. */
    public const V1 = [self::INBOUND_MESSAGE];

    public static function isV1(?string $type): bool
    {
        return $type !== null && in_array($type, self::V1, true);
    }
}
