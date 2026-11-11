<?php

namespace App\Services\Meta\Templates;

use App\Support\Text\InvisibleCharacters;
use SensitiveParameter;

/**
 * Los errores de Meta, normalizados en una lista CERRADA de clases. Puro: sin
 * red, sin configuración y sin estado.
 *
 * De la respuesta de error de Graph (`{"error": {...}}`) solo se conservan:
 *  - `code` y `error_subcode`, como enteros;
 *  - `error_user_title` y `error_user_msg`, saneados ({@see sanitize()}) y
 *    acotados a {@see USER_TITLE_MAX} y {@see USER_MESSAGE_MAX} caracteres: es
 *    el texto que Meta escribe para el usuario, y el que el CRM puede enseñar;
 *  - `fbtrace_id`, si tiene la forma de un id de traza, para hablar con Meta.
 * NUNCA el cuerpo, las cabeceras, ni `message` (el texto para desarrolladores,
 * que puede repetir parámetros de la petición).
 *
 * Fuentes (referencia de Graph, `whats-app-business-account/message_templates`
 * y el nodo de la plantilla, consultadas el 2026-11-10; gestión de plantillas
 * para 100/2388024 y 200): leer da 80008, 100, 190 y 200; crear, además, 131009,
 * 192, 368, 200002 y 139000.
 */
final class TemplateApiErrors
{
    /** El token no vale: caducado, revocado o mal formado para Meta (190, 102; 401 sin código). */
    public const AUTH = 'auth';

    /** El token no tiene permiso, o la cuenta no está asignada a quien lo emitió (10, 3, 200–299; 403 sin código). */
    public const PERMISSION = 'permission';

    /** Meta pide frenar (4, 17, 32, 613, 80007, 80008, 130429; HTTP 429). */
    public const RATE_LIMITED = 'rate_limited';

    /** Ya hay una plantilla con ese nombre en ese idioma (100 / 2388024). */
    public const DUPLICATE_NAME = 'duplicate_name';

    /** Meta no acepta la plantilla o un parámetro (otros 100, 131009, 192). */
    public const VALIDATION = 'validation';

    /** No existe o el token no la ve (100 / 33, 803; 404 sin código). */
    public const NOT_FOUND = 'not_found';

    /** Meta se negó con un código que no está en las clases de arriba (368, 139000, 200002…). */
    public const REJECTED = 'rejected';

    /** Meta falló por su lado (1, 2, `is_transient`, HTTP 5xx). */
    public const SERVER = 'server';

    /** Sin respuesta: no conectó, se cortó o se agotó el tiempo. */
    public const TRANSPORT = 'transport';

    /** Hubo respuesta, pero no es lo que Graph contesta (no es JSON, otra forma, una redirección…). */
    public const INVALID_RESPONSE = 'invalid_response';

    /** No se llegó a llamar: falta la credencial o no hay a quién llamar fuera de producción. */
    public const NOT_CONFIGURED = 'not_configured';

    public const CLASSES = [
        self::AUTH, self::PERMISSION, self::RATE_LIMITED, self::DUPLICATE_NAME, self::VALIDATION, self::NOT_FOUND,
        self::REJECTED, self::SERVER, self::TRANSPORT, self::INVALID_RESPONSE, self::NOT_CONFIGURED,
    ];

    /** Las clases en las que tiene sentido volver a mirar MÁS TARDE (nunca reenviar un POST: ver la interfaz). */
    public const RETRYABLE = [self::RATE_LIMITED, self::SERVER, self::TRANSPORT];

    /** «Content in this language already exists»: el nombre ya existe en ese idioma. */
    public const SUBCODE_DUPLICATE_NAME = 2388024;

    /** «Object with ID does not exist, cannot be loaded due to missing permissions, or does not support this operation». */
    public const SUBCODE_OBJECT_NOT_FOUND = 33;

    public const AUTH_CODES = [102, 190];

    /** Además de estos, todo el rango 200–299 («API permission»). */
    public const PERMISSION_CODES = [3, 10];

    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80007, 80008, 130429];

    public const SERVER_CODES = [1, 2];

    public const VALIDATION_CODES = [100, 131009, 192];

    public const NOT_FOUND_CODES = [803];

    public const USER_TITLE_MAX = 120;

    public const USER_MESSAGE_MAX = 300;

    /** La forma de un `fbtrace_id`; lo que no la tiene no se guarda. */
    public const FBTRACE_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/D';

    /**
     * Clasifica una respuesta de error de Graph ya decodificada (o lo que haya
     * llegado: un texto, null…). `$token`, si se da, se tacha de los textos.
     *
     * @return array{class: string, code: ?int, subcode: ?int, userTitle: ?string, userMessage: ?string, fbtraceId: ?string}
     */
    public static function classify(int $httpStatus, mixed $decodedBody, #[SensitiveParameter] ?string $token = null): array
    {
        $error = is_array($decodedBody) && is_array($decodedBody['error'] ?? null) ? $decodedBody['error'] : [];

        $code = self::integer($error['code'] ?? null);
        $subcode = self::integer($error['error_subcode'] ?? null);
        $transient = ($error['is_transient'] ?? false) === true;
        $trace = $error['fbtrace_id'] ?? null;

        return [
            'class' => self::classOf($httpStatus, $code, $subcode, $transient),
            'code' => $code,
            'subcode' => $subcode,
            'userTitle' => self::sanitize($error['error_user_title'] ?? null, self::USER_TITLE_MAX, $token),
            'userMessage' => self::sanitize($error['error_user_msg'] ?? null, self::USER_MESSAGE_MAX, $token),
            'fbtraceId' => is_string($trace) && preg_match(self::FBTRACE_PATTERN, $trace) === 1 ? $trace : null,
        ];
    }

    /**
     * La clase de un error, en este orden: el código de Graph manda sobre el
     * estado HTTP; sin código, decide el estado; sin nada reconocible,
     * {@see INVALID_RESPONSE}.
     */
    public static function classOf(int $httpStatus, ?int $code, ?int $subcode, bool $transient = false): string
    {
        return match (true) {
            $code === 100 && $subcode === self::SUBCODE_DUPLICATE_NAME => self::DUPLICATE_NAME,
            ($code === 100 && $subcode === self::SUBCODE_OBJECT_NOT_FOUND) || in_array($code, self::NOT_FOUND_CODES, true) => self::NOT_FOUND,
            in_array($code, self::AUTH_CODES, true) || ($code === null && $httpStatus === 401) => self::AUTH,
            in_array($code, self::PERMISSION_CODES, true) || ($code !== null && $code >= 200 && $code <= 299) || ($code === null && $httpStatus === 403) => self::PERMISSION,
            in_array($code, self::RATE_LIMIT_CODES, true) || $httpStatus === 429 => self::RATE_LIMITED,
            in_array($code, self::SERVER_CODES, true) || $transient || $httpStatus >= 500 => self::SERVER,
            in_array($code, self::VALIDATION_CODES, true) => self::VALIDATION,
            $code === null && $httpStatus === 404 => self::NOT_FOUND,
            $code !== null => self::REJECTED,
            default => self::INVALID_RESPONSE,
        };
    }

    /** ¿Tiene sentido volver a mirar más tarde? (Un POST nunca se reenvía solo.) */
    public static function retryable(?string $class): bool
    {
        return in_array($class, self::RETRYABLE, true);
    }

    /**
     * Texto de Meta apto para guardar y enseñar, o null si no queda nada:
     *  - sin caracteres invisibles ni de control ({@see InvisibleCharacters::clean()}),
     *    en una sola línea y con los espacios colapsados;
     *  - sin el token (si se da), sin `Bearer …`, sin `access_token=…` y
     *    parecidos, sin nada con forma de token de Meta (`EAA…`) ni cadenas
     *    opacas largas (otro token, una firma);
     *  - con los números largos (cuentas, plantillas, teléfonos) reducidos a
     *    sus cuatro últimas cifras;
     *  - acotado a `$max` caracteres, con «…» si se cortó.
     */
    public static function sanitize(mixed $text, int $max, #[SensitiveParameter] ?string $token = null): ?string
    {
        if (! is_string($text) || $text === '' || $max < 2) {
            return null;
        }

        $texto = InvisibleCharacters::clean($text)['text'];

        $token = $token === null ? '' : trim($token);
        if ($token !== '') {
            $texto = str_replace($token, '[token]', $texto);
        }
        $texto = preg_replace('/\b(access_token|input_token|client_secret|appsecret_proof|fb_exchange_token|code)=[^&\s"\']*/i', '$1=[redactado]', $texto) ?? '';
        $texto = preg_replace('/\bBearer\s+[^\s"\',]+/i', 'Bearer [redactado]', $texto) ?? '';
        $texto = preg_replace('/\bEAA[A-Za-z0-9]{16,}/', '[token]', $texto) ?? '';
        $texto = preg_replace('/[A-Za-z0-9_\-]{40,}/', '[redactado]', $texto) ?? '';
        $texto = preg_replace_callback('/\d{8,}/', static fn (array $m): string => '***'.substr($m[0], -4), $texto) ?? '';
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        if ($texto === '') {
            return null;
        }

        return mb_strlen($texto) > $max ? rtrim(mb_substr($texto, 0, $max - 1)).'…' : $texto;
    }

    /** Un entero de Graph (llega como número o como texto de dígitos), o null. */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value) === 1 ? (int) $value : null;
    }
}
