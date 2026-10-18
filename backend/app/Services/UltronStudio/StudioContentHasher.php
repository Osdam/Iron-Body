<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * Huellas del Studio: sha256 de un JSON CANÓNICO.
 *
 * Canónico quiere decir que el mismo contenido da siempre los mismos bytes:
 * las claves de cada objeto se ordenan (recursivamente), las listas conservan
 * su orden y se codifica sin escapar tildes ni barras. Los decimales se
 * rechazan: `0.1` no tiene una única escritura y una huella que dependa de
 * cómo se imprime un float no prueba nada. El contenido que se firma solo
 * lleva enteros (el normalizador del borrador lo garantiza).
 *
 * Lo que entra en cada huella es lo que decide el COMPORTAMIENTO:
 *
 *  - Borrador: `{catalogVersion, definition, configs}`.
 *  - Versión: lo mismo más las reglas, ordenadas por código. Depende del
 *    FORMATO del contenido (`content.format ?? 1`, contrato G §A7):
 *      · formato 1 (F): exactamente lo de F, {@see VERSION_RULE_FIELDS};
 *      · formato 2 (G): además `conditions` en cada regla (null incluido) y
 *        `format` dentro del comportamiento.
 *    Un formato desconocido no se firma (InvalidArgumentException): la
 *    integridad de una versión así es `false`, nunca la de otro formato.
 *  - Regla: `{description, type, layers, priority, refs, origin}`, más
 *    `conditions` SOLO cuando no es null: las reglas sin condiciones
 *    conservan la huella de F.
 *
 * La disposición del lienzo (posiciones y vista) NO cuenta: mover un nodo no
 * cambia lo que hace el agente, así que dos versiones con el mismo
 * comportamiento comparten huella. Por lo mismo, en la definición las capas y
 * las aristas son CONJUNTOS (su orden no cuenta); las listas de los parámetros
 * sí son listas (el orden de las ofertas priorizadas es significativo).
 */
final class StudioContentHasher
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Formato del contenido de las versiones de F (sin `format` en el contenido). */
    public const FORMAT_1 = 1;

    /** Formato del contenido de las versiones de G: reglas con `conditions`. */
    public const FORMAT_2 = 2;

    /** El formato con el que se crean las versiones nuevas. */
    public const LATEST_FORMAT = self::FORMAT_2;

    /** Lo que se copia de cada regla dentro de una versión de formato 1. */
    public const VERSION_RULE_FIELDS = ['code', 'revision', 'source', 'enforcement', 'status', 'description', 'type', 'layers', 'priority', 'refs'];

    /** Lo que se copia de cada regla dentro de una versión de formato 2. */
    public const VERSION_RULE_FIELDS_FORMAT_2 = [...self::VERSION_RULE_FIELDS, 'conditions'];

    /** Lo que identifica el contenido de una regla (sin estado ni revisión). */
    public const RULE_FIELDS = ['description', 'type', 'layers', 'priority', 'refs', 'origin'];

    /**
     * Huella de un borrador. `$content` es el contenido normalizado
     * (`{definition, configs, layout}`); la disposición se ignora.
     *
     * @param  array<string,mixed>  $content
     */
    public static function draftHash(string $catalogVersion, array $content): string
    {
        return self::hash(self::behaviour($catalogVersion, $content));
    }

    /**
     * Huella de una versión: la del borrador más sus reglas, ordenadas por
     * código y reducidas a los campos de su formato ({@see versionRuleFields()}).
     * Lo demás de cada regla (uuid, fechas, autor) no cambia el comportamiento.
     * El formato sale del propio contenido ({@see format()}).
     *
     * @param  array<string,mixed>  $content
     * @param  list<array<string,mixed>>  $rules
     *
     * @throws InvalidArgumentException si el formato es desconocido o falta un campo
     */
    public static function versionHash(string $catalogVersion, array $content, array $rules): string
    {
        $formato = self::format($content);
        $campos = self::versionRuleFields($formato);

        $copia = array_map(static fn (array $rule): array => self::pick($rule, $campos), $rules);
        usort($copia, static fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));

        $comportamiento = self::behaviour($catalogVersion, $content);
        if ($formato !== self::FORMAT_1) {
            $comportamiento['format'] = $formato;
        }

        return self::hash($comportamiento + ['rules' => $copia]);
    }

    /**
     * El formato del contenido de una versión: `content.format ?? 1`. Solo
     * existen el 1 (F) y el 2 (G); cualquier otro valor es un error, no un
     * formato por defecto.
     *
     * @param  array<string,mixed>  $content
     *
     * @throws InvalidArgumentException
     */
    public static function format(array $content): int
    {
        $formato = $content['format'] ?? self::FORMAT_1;
        if ($formato !== self::FORMAT_1 && $formato !== self::FORMAT_2) {
            throw new InvalidArgumentException('Formato de contenido desconocido.');
        }

        return $formato;
    }

    /**
     * Los campos de cada regla que copia (y firma) una versión de ese formato.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function versionRuleFields(int $format): array
    {
        return match ($format) {
            self::FORMAT_1 => self::VERSION_RULE_FIELDS,
            self::FORMAT_2 => self::VERSION_RULE_FIELDS_FORMAT_2,
            default => throw new InvalidArgumentException('Formato de contenido desconocido.'),
        };
    }

    /**
     * Huella del contenido de una regla. `conditions` entra SOLO si no es
     * null: así una regla documental (todas las de F) conserva su huella.
     *
     * @param  array<string,mixed>  $rule
     */
    public static function ruleHash(array $rule): string
    {
        $campos = self::pick($rule, self::RULE_FIELDS);
        if (($rule['conditions'] ?? null) !== null) {
            $campos['conditions'] = $rule['conditions'];
        }

        return self::hash($campos);
    }

    /** sha256 (hex, 64 caracteres) del JSON canónico de `$value`. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), self::JSON_FLAGS);
    }

    /**
     * Ordena las claves de los objetos, recursivamente; las listas quedan en
     * su orden. Solo admite null, booleanos, enteros, cadenas y arrays.
     */
    public static function canonicalize(mixed $value, string $path = '$'): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException("La huella no admite decimales ({$path}).");
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('La huella no admite valores de tipo '.get_debug_type($value)." ({$path}).");
        }

        if (array_is_list($value)) {
            $lista = [];
            foreach ($value as $i => $item) {
                $lista[] = self::canonicalize($item, "{$path}[{$i}]");
            }

            return $lista;
        }

        $objeto = [];
        foreach ($value as $clave => $item) {
            $objeto[$clave] = self::canonicalize($item, "{$path}.{$clave}");
        }
        ksort($objeto, SORT_STRING);

        return $objeto;
    }

    /**
     * Lo que decide el comportamiento de un borrador. Capas y aristas como
     * conjuntos: sin repetidos y en orden alfabético.
     *
     * @param  array<string,mixed>  $content
     * @return array{catalogVersion:string,definition:array{layers:list<string>,edgeIds:list<string>},configs:mixed}
     */
    private static function behaviour(string $catalogVersion, array $content): array
    {
        $definition = $content['definition'] ?? null;
        if (! is_array($definition) || ! array_key_exists('configs', $content)) {
            throw new InvalidArgumentException('El contenido a firmar necesita definition y configs.');
        }

        return [
            'catalogVersion' => $catalogVersion,
            'definition' => [
                'layers' => self::asSet($definition['layers'] ?? null, 'definition.layers'),
                'edgeIds' => self::asSet($definition['edgeIds'] ?? null, 'definition.edgeIds'),
            ],
            'configs' => $content['configs'],
        ];
    }

    /** @return list<string> */
    private static function asSet(mixed $ids, string $campo): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException("{$campo} tiene que ser una lista.");
        }

        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException("{$campo} solo admite identificadores de texto.");
            }
        }

        $conjunto = array_values(array_unique($ids));
        sort($conjunto, SORT_STRING);

        return $conjunto;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private static function pick(array $source, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                throw new InvalidArgumentException("Falta el campo {$field} para calcular la huella.");
            }
            $out[$field] = $source[$field];
        }

        return $out;
    }
}
