<?php

namespace App\Services\Meta\Templates;

use InvalidArgumentException;
use JsonException;

/**
 * Lo que se le puede pedir a Meta, comprobado ANTES de componer una petición.
 * Puro: sin red, sin configuración y sin estado. Lo usan las dos
 * implementaciones del cliente, así que el doble rechaza exactamente lo mismo
 * que el cliente de verdad.
 *
 * Todo lo que no pasa lanza `InvalidArgumentException` (es un error de quien
 * llama, no de Meta) con un mensaje que NUNCA repite el valor recibido: si
 * alguien cruzó variables, ahí podría venir el token.
 *
 * Los ids van en la RUTA de la URL: solo dígitos, con `D` para que un salto
 * de línea final no se cuele (sin él, `$` de PCRE lo admite).
 */
final class TemplateRequestGuard
{
    /** Id de una cuenta (WABA) o de una plantilla: solo dígitos. */
    public const ID_PATTERN = '/^[0-9]{1,32}$/D';

    /** Nombre de plantilla según Meta: minúsculas, dígitos y guion bajo, hasta 512. */
    public const NAME_PATTERN = '/^[a-z0-9_]{1,512}$/D';

    /** Código de idioma de Meta: `es`, `es_CO`, `en_US`, `fil`… */
    public const LANGUAGE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?$/D';

    /** Un valor de enumeración de Meta (categoría, subcategoría…). */
    public const ENUM_PATTERN = '/^[A-Z_]{1,32}$/D';

    /** El cursor `after` de Graph: base64 o base64url, opaco. */
    public const CURSOR_PATTERN = '/^[A-Za-z0-9_\-+\/=]{1,1024}$/D';

    /** Las únicas claves que puede llevar la plantilla que se crea. */
    public const PAYLOAD_KEYS = [
        'name', 'language', 'category', 'parameter_format', 'components',
        'allow_category_change', 'message_send_ttl_seconds', 'sub_category',
    ];

    /** Las obligatorias. */
    public const PAYLOAD_REQUIRED = ['name', 'language', 'category', 'components'];

    /** Campos que se pueden pedir de una vez (con repetidos; se piden sin repetir). */
    public const FIELDS_MAX = 32;

    /** El cuerpo JSON de una plantilla, como mucho (la mayor posible ronda unos pocos KB). */
    public const PAYLOAD_MAX_BYTES = 32768;

    /** Flags del JSON que sale: UTF-8 legible, barras sin escapar, y nada se calla. */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function wabaId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('El id de la cuenta de WhatsApp (WABA) tiene que ser solo dígitos.');
        }

        return $id;
    }

    public static function templateId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('El id de la plantilla de Meta tiene que ser solo dígitos.');
        }

        return $id;
    }

    public static function name(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('El nombre de la plantilla solo admite minúsculas, dígitos y guion bajo (hasta 512).');
        }

        return $name;
    }

    public static function language(?string $language): ?string
    {
        if ($language !== null && preg_match(self::LANGUAGE_PATTERN, $language) !== 1) {
            throw new InvalidArgumentException('El idioma de la plantilla no tiene la forma de un código de Meta (es, es_CO…).');
        }

        return $language;
    }

    /**
     * Los campos de la consulta: los obligatorios primero y después los
     * pedidos, sin repetir y en su orden. Todos de la lista cerrada.
     *
     * @param  array<mixed>  $fields
     * @param  list<string>  $forced
     */
    public static function fields(array $fields, array $forced): string
    {
        if (! array_is_list($fields) || count($fields) > self::FIELDS_MAX) {
            throw new InvalidArgumentException('Los campos de la plantilla van en una lista de como mucho '.self::FIELDS_MAX.'.');
        }

        $out = [];
        foreach ([...$forced, ...$fields] as $field) {
            if (! is_string($field) || ! in_array($field, WhatsappTemplateClient::FIELDS, true)) {
                throw new InvalidArgumentException('Campo de plantilla no admitido; los válidos son: '.implode(', ', WhatsappTemplateClient::FIELDS).'.');
            }
            if (! in_array($field, $out, true)) {
                $out[] = $field;
            }
        }
        if ($out === []) {
            throw new InvalidArgumentException('Hay que pedir al menos un campo de la plantilla.');
        }

        return implode(',', $out);
    }

    public static function limit(int $limit): int
    {
        if ($limit < 1 || $limit > WhatsappTemplateClient::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('El tamaño de página va de 1 a '.WhatsappTemplateClient::MAX_PAGE_SIZE.'.');
        }

        return $limit;
    }

    public static function cursor(?string $cursor): ?string
    {
        if ($cursor !== null && preg_match(self::CURSOR_PATTERN, $cursor) !== 1) {
            throw new InvalidArgumentException('El cursor de la página no tiene la forma de un cursor de Graph.');
        }

        return $cursor;
    }

    /**
     * La plantilla que se crea, comprobada en su forma (lo que Meta exige de su
     * contenido lo decide Meta) y convertida en el cuerpo EXACTO que sale.
     *
     * @param  array<mixed>  $payload
     */
    public static function payload(array $payload): string
    {
        if ($payload === [] || array_is_list($payload)) {
            throw new InvalidArgumentException('La plantilla tiene que ser un objeto con name, language, category y components.');
        }

        $sobran = array_diff(array_map('strval', array_keys($payload)), self::PAYLOAD_KEYS);
        if ($sobran !== []) {
            throw new InvalidArgumentException('La plantilla lleva claves que este cliente no envía; las admitidas son: '.implode(', ', self::PAYLOAD_KEYS).'.');
        }
        foreach (self::PAYLOAD_REQUIRED as $clave) {
            if (! array_key_exists($clave, $payload)) {
                throw new InvalidArgumentException("A la plantilla le falta «{$clave}».");
            }
        }

        if (! is_string($payload['name'])) {
            throw new InvalidArgumentException('El nombre de la plantilla tiene que ser un texto.');
        }
        self::name($payload['name']);
        if (! is_string($payload['language'])) {
            throw new InvalidArgumentException('El idioma de la plantilla tiene que ser un texto.');
        }
        self::language($payload['language']);
        if (! is_string($payload['category']) || preg_match(self::ENUM_PATTERN, $payload['category']) !== 1) {
            throw new InvalidArgumentException('La categoría de la plantilla va en mayúsculas (MARKETING, UTILITY…).');
        }
        if (array_key_exists('parameter_format', $payload) && ! in_array($payload['parameter_format'], ['POSITIONAL', 'NAMED'], true)) {
            throw new InvalidArgumentException('parameter_format solo puede ser POSITIONAL o NAMED.');
        }
        if (array_key_exists('sub_category', $payload) && (! is_string($payload['sub_category']) || preg_match(self::ENUM_PATTERN, $payload['sub_category']) !== 1)) {
            throw new InvalidArgumentException('sub_category va en mayúsculas.');
        }
        if (array_key_exists('allow_category_change', $payload) && ! is_bool($payload['allow_category_change'])) {
            throw new InvalidArgumentException('allow_category_change tiene que ser true o false.');
        }
        if (array_key_exists('message_send_ttl_seconds', $payload) && (! is_int($payload['message_send_ttl_seconds']) || $payload['message_send_ttl_seconds'] < 1)) {
            throw new InvalidArgumentException('message_send_ttl_seconds tiene que ser un entero positivo.');
        }

        $componentes = $payload['components'];
        if (! is_array($componentes) || $componentes === [] || ! array_is_list($componentes)) {
            throw new InvalidArgumentException('Los componentes de la plantilla van en una lista con al menos uno.');
        }
        foreach ($componentes as $componente) {
            if (! is_array($componente) || array_is_list($componente) || ! is_string($componente['type'] ?? null) || preg_match(self::ENUM_PATTERN, $componente['type']) !== 1) {
                throw new InvalidArgumentException('Cada componente es un objeto con su «type» en mayúsculas (HEADER, BODY, FOOTER, BUTTONS).');
            }
        }

        try {
            $json = json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException) {
            throw new InvalidArgumentException('La plantilla no se puede escribir como JSON (¿texto que no es UTF-8?).');
        }
        if (strlen($json) > self::PAYLOAD_MAX_BYTES) {
            throw new InvalidArgumentException('La plantilla pasa de '.self::PAYLOAD_MAX_BYTES.' bytes en JSON.');
        }

        return $json;
    }
}
