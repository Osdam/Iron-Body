<?php

namespace App\Services\Voice\Dto;

/**
 * Las formas de los identificadores que cruzan la frontera de la voz (contrato
 * H8, D8-3 y D8-13), en un solo sitio. Pura. Todos los patrones llevan `D`:
 * sin él, PCRE acepta un salto de línea final y llegaría a una ruta o a la base.
 *
 * Un identificador que no casa no se «limpia»: se rechaza (en una DTO) o se
 * descarta (en lo que llega de un proveedor), y nunca se repite su valor en un
 * mensaje.
 */
final class VoiceIds
{
    /** Ids de recursos del proveedor: agentes, herramientas, conversaciones, versiones, ramas y números importados. */
    public const PROVIDER_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    /** El SID de una llamada de Twilio. */
    public const CALL_SID_PATTERN = '/^CA[0-9a-f]{32}$/D';

    /** Un uuid en minúsculas (el `call_ref` de una llamada, los uuid del Studio). */
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public static function isProviderId(mixed $valor): bool
    {
        return is_string($valor) && preg_match(self::PROVIDER_ID_PATTERN, $valor) === 1;
    }

    public static function isCallSid(mixed $valor): bool
    {
        return is_string($valor) && preg_match(self::CALL_SID_PATTERN, $valor) === 1;
    }

    public static function isUuid(mixed $valor): bool
    {
        return is_string($valor) && preg_match(self::UUID_PATTERN, $valor) === 1;
    }

    /** El valor si es un id del proveedor; si no, null (lo que llega de fuera no se corrige). */
    public static function providerIdOrNull(mixed $valor): ?string
    {
        return self::isProviderId($valor) ? $valor : null;
    }

    public static function callSidOrNull(mixed $valor): ?string
    {
        return self::isCallSid($valor) ? $valor : null;
    }

    public static function uuidOrNull(mixed $valor): ?string
    {
        return self::isUuid($valor) ? $valor : null;
    }
}
