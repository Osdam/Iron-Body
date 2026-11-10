<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * A qué agente apunta el número importado en el proveedor (contrato H8, D8-21;
 * Enmienda 2, E2-3; Enmienda 4, E4-8: la DERIVA DEL NÚMERO). Solo ids del
 * proveedor: el número de teléfono que trae la respuesta no entra aquí.
 *
 *  - `observed`: el número existe y se pudo leer; `agentId` es el agente
 *    asignado, o null si está sin asignar.
 *  - `not_found` (404), `auth_failed` (401/403), `unreachable` (sin conexión o
 *    tiempo agotado), `error` (otro estado o un cuerpo que no se puede leer).
 *  - `not_configured`: sin la bandera o sin todas las piezas; SIN RED. Es lo
 *    que da el nulo: «desconocido».
 *
 * {@see state()} lo resume: `assigned`, `unassigned` o `unknown` (todo lo que
 * no es `observed`).
 *
 * `inboundCapable` (B8-g, salientes antes que entrantes): lo que dice el
 * proveedor de si el número ADMITE ENTRANTES (`supports_inbound` de la
 * respuesta, un campo que la referencia oficial marca como obsoleto). Un número
 * «Outbound Only» de ElevenLabs es un caller ID verificado en Twilio: no recibe
 * llamadas ni se puede asignar a un agente. `false` solo si el proveedor lo
 * dice con un booleano; sin el campo (o con otra forma), null: no se sabe. Solo
 * un número observado lo lleva.
 *
 * `phoneNumber()` (B8-g, SEC8-c B-1): el E.164 con el que ElevenLabs llama de
 * verdad (`phone_number` de la respuesta), para comparar con `TWILIO_VOICE_FROM`
 * y con los números de WhatsApp. Va envuelto en `SensitiveParameterValue`, como
 * `OutboundCallRequest::toNumber()`: `var_export()`, `json_encode()`,
 * `print_r()` y `var_dump()` no lo enseñan, y {@see toArray()} no lo lleva.
 * Quien lo lee lo compara en memoria y lo descarta. Solo un número observado
 * lo lleva; sin el campo o sin un E.164 estricto, null.
 */
final class VoiceNumberAssignment
{
    public const OUTCOME_OBSERVED = 'observed';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOMES = [
        self::OUTCOME_OBSERVED, self::OUTCOME_NOT_FOUND, self::OUTCOME_AUTH_FAILED, self::OUTCOME_UNREACHABLE,
        self::OUTCOME_ERROR, self::OUTCOME_NOT_CONFIGURED,
    ];

    public const REASON_INVALID_REQUEST = 'invalid_request';

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    public const REASONS = [self::REASON_INVALID_REQUEST, self::REASON_INVALID_RESPONSE];

    public const STATE_ASSIGNED = 'assigned';

    public const STATE_UNASSIGNED = 'unassigned';

    public const STATE_UNKNOWN = 'unknown';

    /** @var SensitiveParameterValue<string>|null */
    private readonly ?SensitiveParameterValue $phoneNumber;

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $phoneNumberId,
        public readonly ?string $agentId,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
        public readonly ?bool $inboundCapable = null,
        #[\SensitiveParameter] ?string $phoneNumber = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de la lectura del número desconocido.');
        }
        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de la lectura del número desconocido.');
        }
        foreach (['phoneNumberId' => $phoneNumberId, 'agentId' => $agentId] as $campo => $id) {
            if ($id !== null && ! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException("{$campo} no tiene la forma de un id del proveedor.");
            }
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome === self::OUTCOME_OBSERVED && ($phoneNumberId === null || $reason !== null)) {
            throw new InvalidArgumentException('Un número observado lleva su id y ningún motivo.');
        }
        if ($outcome !== self::OUTCOME_OBSERVED && $agentId !== null) {
            throw new InvalidArgumentException('Solo un número observado dice a qué agente apunta.');
        }
        if ($outcome !== self::OUTCOME_OBSERVED && $inboundCapable !== null) {
            throw new InvalidArgumentException('Solo un número observado dice si admite entrantes.');
        }
        if ($phoneNumber !== null && ($outcome !== self::OUTCOME_OBSERVED || preg_match(VoiceAgentSpec::E164_PATTERN, $phoneNumber) !== 1)) {
            throw new InvalidArgumentException('Solo un número observado lleva su E.164, y estricto.');
        }
        $this->phoneNumber = $phoneNumber === null ? null : new SensitiveParameterValue($phoneNumber);
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && ($httpStatus !== null || $phoneNumberId !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni id.');
        }
    }

    /** B8-g (B-1): el E.164 con el que llama el proveedor, solo en memoria (nunca en {@see toArray()}). */
    public function phoneNumber(): ?string
    {
        return $this->phoneNumber?->getValue();
    }

    /** Lo que da el nulo y todo lo que no se pudo leer sin red: «desconocido». */
    public static function unknown(): self
    {
        return new self(self::OUTCOME_NOT_CONFIGURED, null, null, null, null);
    }

    public function state(): string
    {
        return match (true) {
            $this->outcome !== self::OUTCOME_OBSERVED => self::STATE_UNKNOWN,
            $this->agentId === null => self::STATE_UNASSIGNED,
            default => self::STATE_ASSIGNED,
        };
    }

    /** @return array{outcome:string, state:string, phoneNumberId:?string, agentId:?string, httpStatus:?int, reason:?string, inboundCapable:?bool} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'state' => $this->state(),
            'phoneNumberId' => $this->phoneNumberId,
            'agentId' => $this->agentId,
            'httpStatus' => $this->httpStatus,
            'reason' => $this->reason,
            'inboundCapable' => $this->inboundCapable,
        ];
    }
}
