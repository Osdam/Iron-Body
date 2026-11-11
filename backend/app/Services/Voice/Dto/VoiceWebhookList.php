<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * Los webhooks del workspace del proveedor (auditoría B-T5; contrato H8, D8-16
 * y D8-21, decisión (f)). De cada uno, solo lo que hace falta para compararlo
 * con las referencias de Laravel: su id, la URL a la que entrega, si está
 * desactivado (a mano, o SOLO tras fallos seguidos) y su autenticación. Nunca
 * un secreto: la lista del proveedor no lo da.
 *
 *  - `observed`: la lista se pudo leer (puede venir vacía).
 *  - `not_found` (404), `auth_failed` (401/403), `unreachable` (sin conexión o
 *    tiempo agotado), `error` (otro estado o un cuerpo que no se puede leer).
 *  - `not_configured`: sin la bandera o sin todas las piezas; SIN RED. Es lo
 *    que da el nulo: «desconocido».
 *
 * La URL de entrega se queda en memoria para comparar: {@see toArray()} no la
 * lleva.
 */
final class VoiceWebhookList
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

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    public const REASONS = [self::REASON_INVALID_RESPONSE];

    /** La forma de la autenticación que declara el proveedor (`hmac`, `oauth2`…). */
    public const AUTH_TYPE_PATTERN = '/^[a-z0-9_]{1,32}$/D';

    /** Lo más largo que se acepta como URL de entrega. */
    public const URL_MAX = 2048;

    /**
     * @param  list<array{id: string, url: ?string, disabled: ?bool, autoDisabled: ?bool, authType: ?string}>  $webhooks
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $webhooks,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de la lectura de los webhooks desconocido.');
        }
        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de la lectura de los webhooks desconocido.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome !== self::OUTCOME_OBSERVED && $webhooks !== []) {
            throw new InvalidArgumentException('Solo una lista observada trae webhooks.');
        }
        if ($outcome === self::OUTCOME_OBSERVED && $reason !== null) {
            throw new InvalidArgumentException('Una lista observada no lleva motivo.');
        }
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && $httpStatus !== null) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP.');
        }
        if (! array_is_list($webhooks)) {
            throw new InvalidArgumentException('Los webhooks van en una lista.');
        }
        foreach ($webhooks as $w) {
            if (! is_array($w) || array_keys($w) !== ['id', 'url', 'disabled', 'autoDisabled', 'authType']) {
                throw new InvalidArgumentException('Un webhook lleva exactamente id, url, disabled, autoDisabled y authType.');
            }
            if (! VoiceIds::isProviderId($w['id'])) {
                throw new InvalidArgumentException('El id de un webhook no tiene la forma de un id del proveedor.');
            }
            if ($w['url'] !== null && (! is_string($w['url']) || $w['url'] === '' || strlen($w['url']) > self::URL_MAX)) {
                throw new InvalidArgumentException('La URL de un webhook no tiene forma.');
            }
            foreach (['disabled', 'autoDisabled'] as $campo) {
                if ($w[$campo] !== null && ! is_bool($w[$campo])) {
                    throw new InvalidArgumentException("{$campo} es un booleano o null.");
                }
            }
            if ($w['authType'] !== null && (! is_string($w['authType']) || preg_match(self::AUTH_TYPE_PATTERN, $w['authType']) !== 1)) {
                throw new InvalidArgumentException('La autenticación de un webhook no tiene forma.');
            }
        }
    }

    /** Lo que da el nulo y todo lo que no se pudo leer sin red: «desconocido». */
    public static function unknown(): self
    {
        return new self(self::OUTCOME_NOT_CONFIGURED, [], null, null);
    }

    /**
     * El webhook con ese id, o null (también si la lista no se pudo leer).
     *
     * @return array{id: string, url: ?string, disabled: ?bool, autoDisabled: ?bool, authType: ?string}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->webhooks as $w) {
            if (hash_equals($w['id'], $id)) {
                return $w;
            }
        }

        return null;
    }

    /** @return array{outcome: string, count: int, httpStatus: ?int, reason: ?string} sin URLs */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'count' => count($this->webhooks), 'httpStatus' => $this->httpStatus, 'reason' => $this->reason];
    }
}
