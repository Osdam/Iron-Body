<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * Una sesión del laboratorio hablado (contrato H8, D8-3 y D8-23): la URL
 * FIRMADA con la que el navegador habla con un agente de laboratorio, sin
 * teléfono. La pide el servidor con su clave; la clave nunca sale de aquí.
 *
 * LA URL ES UNA CREDENCIAL de un solo uso mientras vive (la documentación
 * oficial consultada el 03-11-2026 da 15 minutos, {@see MAX_AGE_SECONDS}): va
 * envuelta en `SensitiveParameterValue` (`dump()`, `var_dump()` y `print_r()`
 * no la enseñan y `serialize()` se niega). Se entrega una vez a quien abrió la
 * sesión y no se registra ni se guarda; {@see toArray()} no la lleva.
 *
 * Resultados: `issued`; `rejected` (4xx), `not_found` (404), `auth_failed`
 * (401/403), `unreachable` (sin conexión o tiempo agotado), `error` (otro
 * estado o una respuesta sin una URL válida) y `not_configured` (sin red).
 */
final class VoiceLabSession
{
    public const OUTCOME_ISSUED = 'issued';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOMES = [
        self::OUTCOME_ISSUED, self::OUTCOME_REJECTED, self::OUTCOME_NOT_FOUND, self::OUTCOME_AUTH_FAILED,
        self::OUTCOME_UNREACHABLE, self::OUTCOME_ERROR, self::OUTCOME_NOT_CONFIGURED,
    ];

    /** Vista en la documentación oficial (autenticación del agente): hay que empezar la conversación antes de 15 minutos. */
    public const MAX_AGE_SECONDS = 900;

    /** @var SensitiveParameterValue<string|null> */
    private readonly SensitiveParameterValue $signedUrl;

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $outcome,
        #[\SensitiveParameter] ?string $signedUrl,
        public readonly ?string $agentId,
        public readonly ?int $httpStatus,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de sesión desconocido.');
        }
        if (($outcome === self::OUTCOME_ISSUED) !== ($signedUrl !== null)) {
            throw new InvalidArgumentException('Hay URL si y solo si la sesión se emitió.');
        }
        if ($agentId !== null && ! VoiceIds::isProviderId($agentId)) {
            throw new InvalidArgumentException('agentId no tiene la forma de un id del proveedor.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && $httpStatus !== null) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP.');
        }

        $this->signedUrl = new SensitiveParameterValue($signedUrl);
    }

    /** EN CLARO: se entrega una vez al navegador de quien abrió la sesión. */
    public function signedUrl(): ?string
    {
        return $this->signedUrl->getValue();
    }

    /** @return array{outcome:string, agentId:?string, httpStatus:?int, maxAgeSeconds:int} sin la URL */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'agentId' => $this->agentId,
            'httpStatus' => $this->httpStatus,
            'maxAgeSeconds' => self::MAX_AGE_SECONDS,
        ];
    }
}
