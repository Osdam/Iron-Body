<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * Una llamada tal como la lista el operador, para la conciliación (contrato
 * H8, D8-3 y D8-21): se cruza con `voice_calls` por el SID. SIN NÚMEROS: de
 * `to` y `from` solo quedan los cuatro últimos dígitos (D8-17), lo bastante
 * para un incidente de «llamada que Laravel no inició» sin datos personales.
 *
 * El precio va como TEXTO, tal como lo da el operador (negativo = cargo, p.
 * ej. `-0.03770`): un decimal no tiene una única escritura y quien lo suma lo
 * convierte con su propia regla.
 */
final class CarrierCall
{
    /** Las direcciones del recurso Call (documentación oficial del 03-11-2026). Otra se descarta (null). */
    public const DIRECTIONS = ['inbound', 'outbound-api', 'outbound-dial', 'trunking-originating', 'trunking-terminating'];

    public const PRICE_PATTERN = '/^-?[0-9]{1,6}(\.[0-9]{1,8})?$/D';

    public const PRICE_UNIT_PATTERN = '/^[A-Z]{3}$/D';

    public const LAST4_PATTERN = '/^[0-9]{4}$/D';

    /** ISO 8601 en UTC, sin fracción: `2026-11-03T21:00:00Z`. */
    public const TIME_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D';

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $sid,
        public readonly ?string $parentSid,
        public readonly ?string $status,
        public readonly ?string $direction,
        public readonly ?string $startTime,
        public readonly ?string $endTime,
        public readonly ?int $durationSeconds,
        public readonly ?string $price,
        public readonly ?string $priceUnit,
        public readonly ?string $toLast4,
        public readonly ?string $fromLast4,
    ) {
        if (! VoiceIds::isCallSid($sid) || ($parentSid !== null && ! VoiceIds::isCallSid($parentSid))) {
            throw new InvalidArgumentException('El SID de la llamada no tiene la forma CA + 32 hexadecimales.');
        }
        if ($status !== null && ! in_array($status, CarrierResult::PROVIDER_STATUSES, true)) {
            throw new InvalidArgumentException('Estado de llamada del operador desconocido.');
        }
        if ($direction !== null && ! in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException('Dirección de llamada desconocida.');
        }
        foreach ([$startTime, $endTime] as $hora) {
            if ($hora !== null && preg_match(self::TIME_PATTERN, $hora) !== 1) {
                throw new InvalidArgumentException('Las horas van en ISO 8601 UTC.');
            }
        }
        if ($durationSeconds !== null && $durationSeconds < 0) {
            throw new InvalidArgumentException('La duración no puede ser negativa.');
        }
        if (($price !== null && preg_match(self::PRICE_PATTERN, $price) !== 1) || ($priceUnit !== null && preg_match(self::PRICE_UNIT_PATTERN, $priceUnit) !== 1)) {
            throw new InvalidArgumentException('El precio o su moneda no tienen forma.');
        }
        foreach ([$toLast4, $fromLast4] as $ultimos) {
            if ($ultimos !== null && preg_match(self::LAST4_PATTERN, $ultimos) !== 1) {
                throw new InvalidArgumentException('De un número solo van sus cuatro últimos dígitos.');
            }
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'sid' => $this->sid,
            'parentSid' => $this->parentSid,
            'status' => $this->status,
            'direction' => $this->direction,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'durationSeconds' => $this->durationSeconds,
            'price' => $this->price,
            'priceUnit' => $this->priceUnit,
            'toLast4' => $this->toLast4,
            'fromLast4' => $this->fromLast4,
        ];
    }
}
