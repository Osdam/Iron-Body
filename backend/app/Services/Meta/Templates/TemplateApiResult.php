<?php

namespace App\Services\Meta\Templates;

use LogicException;

/**
 * El resultado de UNA operación del cliente de plantillas. Inmutable. No lleva
 * nada que no sea un código cerrado, un número, un texto de Meta ya saneado o
 * una plantilla normalizada: ni el token, ni la URL, ni cuerpos crudos.
 *
 * TRES DESENLACES ({@see $outcome}):
 *  - `ok`: Meta contestó y se entendió.
 *  - `failed`: Meta dijo que no, o no se llegó a preguntar. {@see $error} dice
 *    por qué ({@see TemplateApiErrors::CLASSES}).
 *  - `unknown`: SOLO en `create`. No se sabe si Meta creó la plantilla; quien
 *    llama la busca por nombre e idioma antes de pensar en reenviarla.
 *
 * {@see $detail} afina algunos casos con otra lista cerrada ({@see DETAILS}):
 *  - `not_configured`: `missing_token`, `malformed_token`, `no_simulator`;
 *  - `transport`: `not_sent` (no llegó a salir: no conectó) o `maybe_sent`;
 *  - `invalid_response`: `not_json`, `unexpected_shape`, `redirect`,
 *    `too_large`, `bad_cursor`, `too_many_pages`.
 */
final class TemplateApiResult
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const OUTCOMES = [self::OK, self::FAILED, self::UNKNOWN];

    public const OP_CREATE = 'create';

    public const OP_FIND = 'find';

    public const OP_FIND_BY_NAME = 'find_by_name';

    public const OP_LIST = 'list';

    public const OP_PERMISSIONS = 'permissions';

    public const OPERATIONS = [self::OP_CREATE, self::OP_FIND, self::OP_FIND_BY_NAME, self::OP_LIST, self::OP_PERMISSIONS];

    public const DETAILS = [
        'missing_token', 'malformed_token', 'no_simulator',
        'not_sent', 'maybe_sent',
        'not_json', 'unexpected_shape', 'redirect', 'too_large', 'bad_cursor', 'too_many_pages',
    ];

    /** Los valores que puede tener un permiso en {@see $permissions}. */
    public const PERMISSION_STATES = ['granted', 'declined', 'expired'];

    /**
     * @param  list<MetaTemplate>  $templates
     * @param  array<string,string>  $permissions
     */
    private function __construct(
        public readonly string $operation,
        public readonly string $outcome,
        public readonly ?string $error,
        public readonly ?string $detail,
        public readonly ?int $httpStatus,
        public readonly ?int $metaCode,
        public readonly ?int $metaSubcode,
        public readonly ?string $userTitle,
        public readonly ?string $userMessage,
        public readonly ?string $fbtraceId,
        public readonly int $durationMs,
        public readonly ?string $createdId,
        public readonly ?string $createdStatus,
        public readonly ?string $createdCategory,
        public readonly array $templates,
        public readonly ?string $nextCursor,
        public readonly int $skipped,
        public readonly array $permissions,
        public readonly int $requests,
    ) {
        if (! in_array($operation, self::OPERATIONS, true) || ! in_array($outcome, self::OUTCOMES, true)) {
            throw new LogicException('Operación o desenlace desconocidos.');
        }
        if ($outcome === self::UNKNOWN && $operation !== self::OP_CREATE) {
            throw new LogicException('Solo crear puede quedar en «desconocido»: una lectura no cambia nada en Meta.');
        }
        if (($outcome === self::OK) !== ($error === null)) {
            throw new LogicException('Un resultado bueno no lleva error, y uno malo sí.');
        }
        if ($error !== null && ! in_array($error, TemplateApiErrors::CLASSES, true)) {
            throw new LogicException('Clase de error fuera de la lista cerrada.');
        }
        if ($detail !== null && ! in_array($detail, self::DETAILS, true)) {
            throw new LogicException('Detalle fuera de la lista cerrada.');
        }
    }

    /** `create` bien: el id que dio Meta y, si los dio, su estado y su categoría. */
    public static function created(string $id, ?string $status, ?string $category, int $httpStatus, int $durationMs): self
    {
        if (preg_match(TemplateRequestGuard::ID_PATTERN, $id) !== 1) {
            throw new LogicException('El id de la plantilla creada tiene que ser solo dígitos.');
        }

        return new self(self::OP_CREATE, self::OK, null, null, $httpStatus, null, null, null, null, null, $durationMs,
            $id, $status, $category, [], null, 0, [], 1);
    }

    /**
     * `find`, `find_by_name` o `list` bien.
     *
     * @param  list<MetaTemplate>  $templates
     */
    public static function templates(string $operation, array $templates, ?string $nextCursor, int $skipped, int $httpStatus, int $durationMs, int $requests = 1): self
    {
        if (! in_array($operation, [self::OP_FIND, self::OP_FIND_BY_NAME, self::OP_LIST], true)) {
            throw new LogicException('Solo las lecturas devuelven plantillas.');
        }
        foreach ($templates as $template) {
            if (! $template instanceof MetaTemplate) {
                throw new LogicException('Las plantillas van normalizadas (MetaTemplate).');
            }
        }

        return new self($operation, self::OK, null, null, $httpStatus, null, null, null, null, null, $durationMs,
            null, null, null, array_values($templates), $nextCursor, $skipped, [], $requests);
    }

    /**
     * `permissions` bien.
     *
     * @param  array<string,string>  $permissions  permiso => granted|declined|expired
     */
    public static function permissionsRead(array $permissions, int $skipped, int $httpStatus, int $durationMs): self
    {
        foreach ($permissions as $permiso => $estado) {
            if (! is_string($permiso) || ! in_array($estado, self::PERMISSION_STATES, true)) {
                throw new LogicException('Permisos con otra forma.');
            }
        }

        return new self(self::OP_PERMISSIONS, self::OK, null, null, $httpStatus, null, null, null, null, null, $durationMs,
            null, null, null, [], null, $skipped, $permissions, 1);
    }

    /** Cualquier operación mal (`failed`) o, solo `create`, sin saber (`unknown`). */
    public static function failure(
        string $operation,
        string $outcome,
        string $error,
        ?string $detail = null,
        ?int $httpStatus = null,
        ?int $metaCode = null,
        ?int $metaSubcode = null,
        ?string $userTitle = null,
        ?string $userMessage = null,
        ?string $fbtraceId = null,
        int $durationMs = 0,
        int $requests = 1,
    ): self {
        if ($outcome === self::OK) {
            throw new LogicException('Un fallo no puede salir bien.');
        }

        return new self($operation, $outcome, $error, $detail, $httpStatus, $metaCode, $metaSubcode, $userTitle, $userMessage,
            $fbtraceId, $durationMs, null, null, null, [], null, 0, [], $requests);
    }

    /** No se llegó a llamar: falta la credencial o no hay a quién llamar. Sin red. */
    public static function notConfigured(string $operation, string $detail): self
    {
        return self::failure($operation, self::FAILED, TemplateApiErrors::NOT_CONFIGURED, $detail, requests: 0);
    }

    public function ok(): bool
    {
        return $this->outcome === self::OK;
    }

    public function failed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    public function unknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }

    /** La primera plantilla leída (la de `find`), o null. */
    public function template(): ?MetaTemplate
    {
        return $this->templates[0] ?? null;
    }

    /** ¿Tiene sentido volver a mirar más tarde? Nunca para reenviar un `create` desconocido. */
    public function retryable(): bool
    {
        return $this->outcome === self::FAILED && TemplateApiErrors::retryable($this->error);
    }

    /**
     * Todo el resultado como array, para registros y pruebas.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'outcome' => $this->outcome,
            'error' => $this->error,
            'detail' => $this->detail,
            'httpStatus' => $this->httpStatus,
            'metaCode' => $this->metaCode,
            'metaSubcode' => $this->metaSubcode,
            'userTitle' => $this->userTitle,
            'userMessage' => $this->userMessage,
            'fbtraceId' => $this->fbtraceId,
            'durationMs' => $this->durationMs,
            'createdId' => $this->createdId,
            'createdStatus' => $this->createdStatus,
            'createdCategory' => $this->createdCategory,
            'templates' => array_map(static fn (MetaTemplate $t): array => $t->toArray(), $this->templates),
            'nextCursor' => $this->nextCursor,
            'skipped' => $this->skipped,
            'permissions' => $this->permissions,
            'requests' => $this->requests,
        ];
    }
}
