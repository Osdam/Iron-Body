<?php

namespace App\Services\Marketing\Meta;

use RuntimeException;

/**
 * Un fallo de la Marketing API, ya clasificado y con el mensaje saneado.
 *
 * La categoría decide qué se hace con él: `rate_limit` y `transient` se
 * reintentan; `permission` y `other` no, porque repetir la misma petición con el
 * mismo token o la misma cuenta devuelve el mismo error y solo gasta cuota.
 *
 * El mensaje NUNCA lleva el token ni ids completos: acaba en `meta_sync_runs`,
 * en el log y en el CRM. Por eso no se encadena la excepción original, cuyo texto
 * puede traer la URL entera.
 */
class MetaAdsApiException extends RuntimeException
{
    /** Token caducado o sin permiso sobre la cuenta (códigos 10, 190 y 200-299). */
    public const PERMISSION = 'permission';

    /** Meta pide frenar (códigos 4, 17, 32, 613, límites por caso de uso, HTTP 429). */
    public const RATE_LIMIT = 'rate_limit';

    /** Meta caída o sin respuesta a tiempo (5xx y timeouts). */
    public const TRANSIENT = 'transient';

    /** Todo lo demás: petición inválida, respuesta sin el formato esperado… */
    public const OTHER = 'other';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
    ) {
        parent::__construct($message);
    }

    /** ¿Tiene sentido volver a intentarlo más tarde? */
    public function retryable(): bool
    {
        return in_array($this->category, [self::RATE_LIMIT, self::TRANSIENT], true);
    }
}
