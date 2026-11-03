<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * UNA página de llamadas del operador para la conciliación (contrato H8, D8-3 y
 * D8-21), con un solo GET.
 *
 *  - `listed`: `calls` (puede estar vacía) y, si hay más, `nextPageToken`.
 *  - `truncated`: el operador dijo que hay más, pero su enlace a la página
 *    siguiente no tenía una forma que se pueda seguir sin confiar en una URL
 *    ajena; quien concilia lo sabe y lo dice, en vez de dar la lista por
 *    completa.
 *  - `auth_failed`, `unreachable`, `error`, `not_configured` (sin red) e
 *    `invalid_request` (un token que no casa; sin red): sin llamadas.
 *
 * `nextPageToken` es OPACO para quien llama: `página:token` del operador, que
 * el adaptador vuelve a comprobar antes de usarlo y con el que construye su
 * propia URL, siempre contra su host fijo.
 */
final class CarrierCallsPage
{
    public const OUTCOME_LISTED = 'listed';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_INVALID_REQUEST = 'invalid_request';

    public const OUTCOMES = [
        self::OUTCOME_LISTED, self::OUTCOME_AUTH_FAILED, self::OUTCOME_UNREACHABLE, self::OUTCOME_ERROR,
        self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_INVALID_REQUEST,
    ];

    public const PAGE_TOKEN_PATTERN = '/^[0-9]{1,7}:[A-Za-z0-9_-]{1,256}$/D';

    /**
     * @param  list<CarrierCall>  $calls
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $calls,
        public readonly ?string $nextPageToken,
        public readonly bool $truncated,
        public readonly ?int $httpStatus,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de la lista del operador desconocido.');
        }
        if (! array_is_list($calls)) {
            throw new InvalidArgumentException('calls tiene que ser una lista.');
        }
        foreach ($calls as $llamada) {
            if (! $llamada instanceof CarrierCall) {
                throw new InvalidArgumentException('calls solo lleva CarrierCall.');
            }
        }
        if ($nextPageToken !== null && preg_match(self::PAGE_TOKEN_PATTERN, $nextPageToken) !== 1) {
            throw new InvalidArgumentException('nextPageToken no tiene la forma página:token.');
        }
        if ($outcome !== self::OUTCOME_LISTED && ($calls !== [] || $nextPageToken !== null || $truncated)) {
            throw new InvalidArgumentException('Solo una página listada lleva llamadas o continuación.');
        }
        if ($nextPageToken !== null && $truncated) {
            throw new InvalidArgumentException('Una página con continuación no está truncada.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if (in_array($outcome, [self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_INVALID_REQUEST], true) && $httpStatus !== null) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP.');
        }
    }

    /** @return array{outcome:string, calls:list<array<string,int|string|null>>, nextPageToken:?string, truncated:bool, httpStatus:?int} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'calls' => array_map(static fn (CarrierCall $c): array => $c->toArray(), $this->calls),
            'nextPageToken' => $this->nextPageToken,
            'truncated' => $this->truncated,
            'httpStatus' => $this->httpStatus,
        ];
    }
}
